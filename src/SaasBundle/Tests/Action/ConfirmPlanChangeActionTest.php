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

use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use SolidInvoice\CoreBundle\Company\CompanySelector;
use SolidInvoice\CoreBundle\Entity\Company;
use SolidInvoice\CoreBundle\Repository\CompanyRepository;
use SolidInvoice\SaasBundle\Action\ConfirmPlanChangeAction;
use SolidInvoice\SaasBundle\Subscription\ExternalBillingPlanChangeGuard;
use SolidWorx\Platform\SaasBundle\Entity\Plan;
use SolidWorx\Platform\SaasBundle\Entity\Subscription;
use SolidWorx\Platform\SaasBundle\Enum\SubscriptionStatus;
use SolidWorx\Platform\SaasBundle\Integration\PaymentIntegrationInterface;
use SolidWorx\Platform\SaasBundle\Repository\PlanRepositoryInterface;
use SolidWorx\Platform\SaasBundle\Repository\SubscriptionRepositoryInterface;
use SolidWorx\Platform\SaasBundle\Subscription\SubscriptionManager;
use SolidWorx\Platform\SaasBundle\Subscription\SubscriptionProviderInterface;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Uid\Ulid;

/**
 * Requirement 5: a cancelled subscription can choose the SAME plan again.
 *
 * ROOT CAUSE fixed here: this action's same-plan-selected check used to
 * redirect straight to billing_index as a no-op whenever the submitted plan
 * matched `subscription->getPlan()->getPlanId()` — correct while that plan
 * is actually still in effect, but once the subscription is CANCELLED the
 * "current" plan is no longer in effect, so re-selecting it must be treated
 * as a resubscribe (routed to checkout), not silently swallowed.
 */
#[CoversClass(ConfirmPlanChangeAction::class)]
final class ConfirmPlanChangeActionTest extends TestCase
{
    public function testCancelledSubscriptionChoosingTheSamePlanStartsCheckout(): void
    {
        $starter = $this->makePlan('Starter', 'starter-monthly', 1200);
        $subscription = $this->makeSubscription($starter, SubscriptionStatus::CANCELLED, 'sub_old_cancelled');

        [$action, $router] = $this->buildAction($subscription, $starter);

        $action($this->makeRequest('starter-monthly'));

        self::assertSame('saas_subscription_checkout', $router->lastRoute);
        self::assertSame(['plan' => 'starter-monthly'], $router->lastParameters);
        // The local plan/status must NOT be mutated directly here — the
        // webhook handler is the only authority for committing a paid
        // resubscribe, exactly like a first-time checkout.
        self::assertSame(SubscriptionStatus::CANCELLED, $subscription->getStatus());
    }

    public function testCancelledSubscriptionChoosingADifferentPlanAlsoStartsCheckout(): void
    {
        $starter = $this->makePlan('Starter', 'starter-monthly', 1200);
        $business = $this->makePlan('Business', 'business-monthly', 2500);
        $subscription = $this->makeSubscription($starter, SubscriptionStatus::CANCELLED, 'sub_old_cancelled');

        [$action, $router] = $this->buildAction($subscription, $business);

        $action($this->makeRequest('business-monthly'));

        self::assertSame('saas_subscription_checkout', $router->lastRoute);
        self::assertSame(['plan' => 'business-monthly'], $router->lastParameters);
    }

    /**
     * Regression guard: while the subscription is NOT cancelled (still
     * TRIAL/ACTIVE), re-selecting the SAME plan it's already on must remain
     * a plain no-op — this must not start a pointless checkout.
     */
    public function testNonCancelledSubscriptionChoosingTheSamePlanIsStillANoOp(): void
    {
        $starter = $this->makePlan('Starter', 'starter-monthly', 1200);
        $subscription = $this->makeSubscription($starter, SubscriptionStatus::TRIAL, 'sub_live123');

        [$action, $router] = $this->buildAction($subscription, $starter);

        $action($this->makeRequest('starter-monthly'));

        self::assertSame('billing_index', $router->lastRoute);
    }

    /**
     * Regression guard: an externally-billed, NOT-cancelled subscription
     * choosing a DIFFERENT plan must still be blocked by
     * ExternalBillingPlanChangeGuard (this is the Bug-2 fix from earlier in
     * this session; the cancellation fix must not weaken it).
     */
    public function testNonCancelledExternallyBilledSubscriptionChoosingDifferentPlanIsBlocked(): void
    {
        $starter = $this->makePlan('Starter', 'starter-monthly', 1200);
        $business = $this->makePlan('Business', 'business-monthly', 2500);
        $subscription = $this->makeSubscription($starter, SubscriptionStatus::TRIAL, 'sub_live123');

        [$action, $router] = $this->buildAction($subscription, $business);

        $action($this->makeRequest('business-monthly'));

        self::assertSame('billing_index', $router->lastRoute);
        self::assertSame('starter-monthly', $subscription->getPlan()->getPlanId(), 'Plan must not change locally when the guard blocks.');
    }

    private function makePlan(string $name, string $planId, int $price): Plan
    {
        return new Plan()
            ->setName($name)
            ->setPlanId($planId)
            ->setPrice($price)
            ->setActive(true);
    }

    private function makeSubscription(Plan $currentPlan, SubscriptionStatus $status, ?string $externalSubscriptionId): Subscription
    {
        $subscription = new Subscription();
        new ReflectionProperty(Subscription::class, 'id')->setValue($subscription, new Ulid());
        $subscription->setPlan($currentPlan);
        $subscription->setStatus($status);

        if ($externalSubscriptionId !== null) {
            $subscription->setSubscriptionId($externalSubscriptionId);
        }

        return $subscription;
    }

    /**
     * @return array{0: ConfirmPlanChangeAction, 1: object{lastRoute: ?string, lastParameters: array<string, mixed>}}
     */
    private function buildAction(Subscription $subscription, Plan $targetPlan): array
    {
        $planRepository = $this->createMock(PlanRepositoryInterface::class);
        $planRepository->method('find')->willReturn($targetPlan);

        $subscriptionProvider = $this->createMock(SubscriptionProviderInterface::class);
        $subscriptionProvider->method('getSubscriptionFor')->willReturn($subscription);

        $subscriptionRepository = $this->createStub(SubscriptionRepositoryInterface::class);

        $subscriptionManager = new SubscriptionManager(
            $subscriptionRepository,
            $this->createStub(PlanRepositoryInterface::class),
            $this->createStub(PaymentIntegrationInterface::class),
        );

        $externalBillingGuard = new ExternalBillingPlanChangeGuard($subscriptionManager);

        $companyRepository = $this->createMock(CompanyRepository::class);
        $companyRepository->method('find')->willReturn(new Company());

        $companySelector = new CompanySelector($this->createStub(ManagerRegistry::class));
        new ReflectionProperty(CompanySelector::class, 'companyId')->setValue($companySelector, new Ulid());

        $action = new ConfirmPlanChangeAction(
            $planRepository,
            $subscriptionManager,
            $subscriptionProvider,
            $companyRepository,
            $companySelector,
            $externalBillingGuard,
        );

        $csrfTokenManager = $this->createMock(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('isTokenValid')->willReturn(true);

        [$router, $recorder] = $this->makeRecordingRouter();

        $request = Request::create('/billing/subscription/change/confirm', Request::METHOD_POST);
        $request->setSession(new Session(new MockArraySessionStorage()));
        $requestStack = new RequestStack([$request]);

        $container = new Container();
        $container->set('security.csrf.token_manager', $csrfTokenManager);
        $container->set('router', $router);
        $container->set('request_stack', $requestStack);

        $action->setContainer($container);

        return [$action, $recorder];
    }

    private function makeRequest(string $planId): Request
    {
        $request = Request::create('/billing/subscription/change/confirm', Request::METHOD_POST, [
            '_token' => 'token',
            'plan' => $planId,
        ]);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    /**
     * A recording router double: PHPUnit mocks make it awkward to assert
     * BOTH the route name AND its parameters were correct via a plain
     * `willReturn()`, so `generate()` is stubbed to record each call onto a
     * separate plain object the test can inspect afterwards.
     *
     * @return array{0: RouterInterface, 1: object{lastRoute: ?string, lastParameters: array<string, mixed>}}
     */
    private function makeRecordingRouter(): array
    {
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

        return [$router, $recorder];
    }
}
