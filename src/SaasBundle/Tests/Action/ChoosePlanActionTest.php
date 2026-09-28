<?php

declare(strict_types=1);

/*
 * This file is part of SolidInvoice project.
 *
 * (c) Pierre du Plessis <open-source@solidworx.co>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace SolidInvoice\SaasBundle\Tests\Action;

use Doctrine\DBAL\DriverManager;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use SolidInvoice\CoreBundle\Company\CompanySelector;
use SolidInvoice\CoreBundle\ConfigWriter;
use SolidInvoice\CoreBundle\Entity\Company;
use SolidInvoice\CoreBundle\Repository\CompanyRepository;
use SolidInvoice\CoreBundle\Telemetry\Telemetry;
use SolidInvoice\CoreBundle\Tests\Telemetry\CollectingMessageBus;
use SolidInvoice\SaasBundle\Action\ChoosePlanAction;
use SolidInvoice\SaasBundle\Subscription\ExternalBillingPlanChangeGuard;
use SolidWorx\Platform\SaasBundle\Entity\Plan;
use SolidWorx\Platform\SaasBundle\Entity\Subscription;
use SolidWorx\Platform\SaasBundle\Enum\SubscriptionStatus;
use SolidWorx\Platform\SaasBundle\Integration\PaymentIntegrationInterface;
use SolidWorx\Platform\SaasBundle\Repository\PlanRepositoryInterface;
use SolidWorx\Platform\SaasBundle\Repository\SubscriptionRepositoryInterface;
use SolidWorx\Platform\SaasBundle\Subscription\SubscriptionManager;
use SolidWorx\Platform\SaasBundle\Subscription\SubscriptionProviderInterface;
use Symfony\Bundle\FrameworkBundle\Secrets\AbstractVault;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Uid\Ulid;

#[CoversClass(ChoosePlanAction::class)]
final class ChoosePlanActionTest extends TestCase
{
    public function testEmitsPlanSelectedTelemetryForPaidPlan(): void
    {
        $bus = new CollectingMessageBus();

        $plan = $this->makePlan('Solo', 'solo-monthly', 900);

        [$action, $bus] = $this->buildAction($bus, $plan);

        $action($this->makeRequest('solo-monthly'));

        self::assertCount(1, $bus->messages);
        self::assertSame('event', $bus->messages[0]->type);
        self::assertSame('saas_plan_selected', $bus->messages[0]->payload['event']);
        self::assertSame('solo', $bus->messages[0]->payload['properties']['plan']);
        self::assertTrue($bus->messages[0]->payload['properties']['is_paid']);
    }

    public function testEmitsPlanSelectedTelemetryForFreePlan(): void
    {
        $bus = new CollectingMessageBus();

        $plan = $this->makePlan('Free', '0', 0);

        [$action, $bus] = $this->buildAction($bus, $plan);

        $action($this->makeRequest('0'));

        self::assertCount(1, $bus->messages);
        self::assertSame('saas_plan_selected', $bus->messages[0]->payload['event']);
        self::assertSame('free', $bus->messages[0]->payload['properties']['plan']);
        self::assertFalse($bus->messages[0]->payload['properties']['is_paid']);
    }

    /**
     * Requirement 4: on the pricing page (`/billing/subscription/plans`,
     * posting to THIS action), a CANCELLED subscription re-selecting the
     * SAME plan it was cancelled on must route to a fresh checkout, not be
     * swallowed as a no-op — this is the ChoosePlanAction-side half of the
     * same fix already covered for ConfirmPlanChangeAction.
     */
    public function testCancelledSubscriptionChoosingTheSamePlanStartsCheckout(): void
    {
        $starter = $this->makePlan('Starter', 'starter-monthly', 1200);

        $subscription = new Subscription();
        $subscription->setPlan($starter);
        $subscription->setStatus(SubscriptionStatus::CANCELLED);
        $subscription->setSubscriptionId('sub_old_cancelled');

        [$action, $recorder] = $this->buildActionWithSubscription($subscription, $starter);

        $action($this->makeRequest('starter-monthly'));

        self::assertSame('saas_subscription_checkout', $recorder->lastRoute);
        self::assertSame(['plan' => 'starter-monthly'], $recorder->lastParameters);
        self::assertSame(SubscriptionStatus::CANCELLED, $subscription->getStatus());
    }

    /**
     * @return array{0: ChoosePlanAction, 1: object{lastRoute: ?string, lastParameters: array<string, mixed>}}
     */
    private function buildActionWithSubscription(Subscription $subscription, Plan $targetPlan): array
    {
        $planRepository = $this->createMock(PlanRepositoryInterface::class);
        $planRepository->method('find')->willReturn($targetPlan);

        $subscriptionProvider = $this->createMock(SubscriptionProviderInterface::class);
        $subscriptionProvider->method('getSubscriptionFor')->willReturn($subscription);

        $subscriptionManager = new SubscriptionManager(
            $this->createStub(SubscriptionRepositoryInterface::class),
            $this->createStub(PlanRepositoryInterface::class),
            $this->createStub(PaymentIntegrationInterface::class),
        );

        $companyRepository = $this->createMock(CompanyRepository::class);
        $companyRepository->method('find')->willReturn(new Company());

        $companySelector = new CompanySelector($this->createStub(ManagerRegistry::class));
        new ReflectionProperty(CompanySelector::class, 'companyId')->setValue($companySelector, new Ulid());

        $action = new ChoosePlanAction(
            $planRepository,
            $subscriptionManager,
            $subscriptionProvider,
            $companyRepository,
            $companySelector,
            $this->makeTelemetry(new CollectingMessageBus()),
            new ExternalBillingPlanChangeGuard($subscriptionManager),
        );

        $csrfTokenManager = $this->createMock(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('isTokenValid')->willReturn(true);

        $recorder = new class() {
            public ?string $lastRoute = null;

            /** @var array<string, mixed> */
            public array $lastParameters = [];
        };

        $router = $this->createMock(RouterInterface::class);
        $router->method('generate')->willReturnCallback(
            function (string $name, array $parameters = []) use ($recorder): string {
                $recorder->lastRoute = $name;
                $recorder->lastParameters = $parameters;

                return '/' . $name;
            },
        );

        $request = $this->makeRequest('');
        $requestStack = new RequestStack([$request]);

        $container = new Container();
        $container->set('security.csrf.token_manager', $csrfTokenManager);
        $container->set('router', $router);
        $container->set('request_stack', $requestStack);

        $action->setContainer($container);

        return [$action, $recorder];
    }

    /**
     * @return array{0: ChoosePlanAction, 1: CollectingMessageBus}
     */
    private function buildAction(CollectingMessageBus $bus, Plan $plan): array
    {
        $subscription = new Subscription();
        $subscription->setPlan($this->makePlan('Existing', 'existing-monthly', 900));

        $planRepository = $this->createMock(PlanRepositoryInterface::class);
        $planRepository->method('find')->willReturn($plan);

        $subscriptionProvider = $this->createMock(SubscriptionProviderInterface::class);
        $subscriptionProvider->method('getSubscriptionFor')->willReturn($subscription);

        // SubscriptionManager is final readonly; build a real one whose
        // changePlan()/activate() (free-plan path) just persist via the mocked
        // repository.
        $subscriptionManager = new SubscriptionManager(
            $this->createStub(SubscriptionRepositoryInterface::class),
            $this->createStub(PlanRepositoryInterface::class),
            $this->createStub(PaymentIntegrationInterface::class),
        );

        $companyRepository = $this->createMock(CompanyRepository::class);
        $companyRepository->method('find')->willReturn(new Company());

        // CompanySelector is final; use a real instance with a company id set.
        $companySelector = new CompanySelector($this->createStub(ManagerRegistry::class));
        new ReflectionProperty(CompanySelector::class, 'companyId')->setValue($companySelector, new Ulid());

        $action = new ChoosePlanAction(
            $planRepository,
            $subscriptionManager,
            $subscriptionProvider,
            $companyRepository,
            $companySelector,
            $this->makeTelemetry($bus),
            new ExternalBillingPlanChangeGuard($subscriptionManager),
        );

        $csrfTokenManager = $this->createMock(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('isTokenValid')->willReturn(true);

        $router = $this->createMock(RouterInterface::class);
        $router->method('generate')->willReturn('/redirect');

        $request = $this->makeRequest('');
        $requestStack = new RequestStack([$request]);

        $container = new Container();
        $container->set('security.csrf.token_manager', $csrfTokenManager);
        $container->set('router', $router);
        $container->set('request_stack', $requestStack);

        $action->setContainer($container);

        return [$action, $bus];
    }

    private function makeRequest(string $planId): Request
    {
        $request = Request::create('/billing/subscription/plans/choose', Request::METHOD_POST, [
            '_token' => 'token',
            'plan' => $planId,
        ]);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    private function makePlan(string $name, string $planId, int $price): Plan
    {
        return new Plan()
            ->setName($name)
            ->setPlanId($planId)
            ->setPrice($price)
            ->setActive(true);
    }

    private function makeTelemetry(CollectingMessageBus $bus): Telemetry
    {
        $vault = $this->createMock(AbstractVault::class);
        $vault->method('generateKeys')->willReturn(true);

        return new Telemetry(
            $bus,
            new ConfigWriter($vault, '/tmp/solidinvoice-test-config'),
            DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]),
            'build-123',
            true,
            'manual',
            false,
            'en',
            null,
        );
    }
}
