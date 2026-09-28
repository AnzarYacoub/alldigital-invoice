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
use SolidInvoice\CoreBundle\Company\CompanySelector;
use SolidInvoice\CoreBundle\ConfigWriter;
use SolidInvoice\CoreBundle\Entity\Company;
use SolidInvoice\CoreBundle\Repository\CompanyRepository;
use SolidInvoice\CoreBundle\Telemetry\Telemetry;
use SolidInvoice\CoreBundle\Tests\Telemetry\CollectingMessageBus;
use ReflectionProperty;
use SolidInvoice\SaasBundle\Action\SelectPlanAction;
use SolidInvoice\SaasBundle\Plan\PlanTierGrouper;
use SolidWorx\Platform\SaasBundle\Entity\Plan;
use SolidWorx\Platform\SaasBundle\Entity\Subscription;
use SolidWorx\Platform\SaasBundle\Enum\SubscriptionStatus;
use SolidWorx\Platform\SaasBundle\Repository\PlanRepositoryInterface;
use SolidWorx\Platform\SaasBundle\Subscription\SubscriptionProviderInterface;
use Symfony\Bundle\FrameworkBundle\Secrets\AbstractVault;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Uid\Ulid;
use Twig\Environment;

#[CoversClass(SelectPlanAction::class)]
final class SelectPlanActionTest extends TestCase
{
    public function testEmitsPricingPageViewedTelemetryWhenPricingPageRenders(): void
    {
        $bus = new CollectingMessageBus();

        $planRepository = $this->createMock(PlanRepositoryInterface::class);
        $planRepository->method('findAllOrdered')->willReturn([
            $this->makePlan('Free'),
            $this->makePlan('Solo'),
        ]);

        $subscriptionProvider = $this->createMock(SubscriptionProviderInterface::class);
        $subscriptionProvider->method('getSubscriptionFor')->willReturn(null);

        $companyRepository = $this->createMock(CompanyRepository::class);
        $companyRepository->method('find')->willReturn(new Company());

        // CompanySelector is final; a real instance with no selected company
        // returns null from getCompany(), which short-circuits subscription
        // lookup and lets the action fall through to the pricing render.
        $companySelector = new CompanySelector($this->createStub(ManagerRegistry::class));

        $action = new SelectPlanAction(
            $planRepository,
            $subscriptionProvider,
            $companyRepository,
            $companySelector,
            $this->makeTelemetry($bus),
            new PlanTierGrouper(),
        );

        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturn('<html></html>');

        $container = new Container();
        $container->set('twig', $twig);

        $action->setContainer($container);

        $action();

        self::assertCount(1, $bus->messages);
        self::assertSame('event', $bus->messages[0]->type);
        self::assertSame('saas_pricing_page_viewed', $bus->messages[0]->payload['event']);
    }

    /**
     * Requirement 1: for a TRIAL subscription, currentPlanId must still be
     * the actual current plan (so its card stays disabled as "Current
     * plan") and isCancelled must be false (plain "Start free trial" copy
     * on the other cards). Requirement 2 (ACTIVE) is covered separately by
     * testActiveSubscriptionRedirectsToBillingIndexWithoutRendering — an
     * ACTIVE subscription redirects before render() and never gets a
     * currentPlanId/isCancelled pair to assert on.
     */
    public function testTrialSubscriptionKeepsCurrentPlanIdAndIsNotCancelled(): void
    {
        $parameters = $this->renderAndCaptureViewParameters(SubscriptionStatus::TRIAL);

        self::assertSame('starter-monthly', $parameters['currentPlanId']);
        self::assertFalse($parameters['isCancelled']);
    }

    /**
     * ROOT CAUSE of the PHPUnit "non-existent service router" failure
     * (test-only bug, not a production one): this test used to call
     * renderAndCaptureViewParameters(), which assumes every status reaches
     * $this->render() and only ever put a 'twig' service in the container.
     * But SelectPlanAction redirects an ACTIVE subscription straight to
     * billing_index (line 48-50) BEFORE it ever builds view parameters or
     * touches Twig — that redirect is pre-existing, correct behavior (an
     * already-active subscriber has no reason to see the initial plan/trial
     * picker) and is intentionally left unchanged here. So this test's own
     * premise — that ACTIVE renders currentPlanId/isCancelled — was wrong;
     * the container simply never surfaced it before because it failed to
     * compile at all (see the earlier "framework.webhook.routing.handypay"
     * fix). Rewritten to assert what the action actually does for ACTIVE —
     * redirect, with no render — using the same router-recording pattern
     * already established in ConfirmPlanChangeActionTest.
     */
    public function testActiveSubscriptionRedirectsToBillingIndexWithoutRendering(): void
    {
        $bus = new CollectingMessageBus();

        $planRepository = $this->createMock(PlanRepositoryInterface::class);
        $planRepository->expects(self::never())->method('findAllOrdered');

        $subscription = new Subscription();
        $subscription->setPlan(
            new Plan()
                ->setName('Starter')
                ->setPlanId('starter-monthly')
                ->setPrice(1200)
                ->setActive(true),
        );
        $subscription->setStatus(SubscriptionStatus::ACTIVE);

        $subscriptionProvider = $this->createMock(SubscriptionProviderInterface::class);
        $subscriptionProvider->method('getSubscriptionFor')->willReturn($subscription);

        $companyRepository = $this->createMock(CompanyRepository::class);
        $companyRepository->method('find')->willReturn(new Company());

        $companySelector = new CompanySelector($this->createStub(ManagerRegistry::class));
        new ReflectionProperty(CompanySelector::class, 'companyId')->setValue($companySelector, new Ulid());

        $action = new SelectPlanAction(
            $planRepository,
            $subscriptionProvider,
            $companyRepository,
            $companySelector,
            $this->makeTelemetry($bus),
            new PlanTierGrouper(),
        );

        [$router, $recorder] = $this->makeRecordingRouter();

        $container = new Container();
        $container->set('router', $router);

        $action->setContainer($container);

        $action();

        self::assertSame('billing_index', $recorder->lastRoute);
        self::assertCount(
            0,
            $bus->messages,
            'ACTIVE subscriptions redirect before the pricing page renders, so saas_pricing_page_viewed must not fire.',
        );
    }

    /**
     * Requirement 3 (root cause fix): a CANCELLED subscription's historical
     * plan_id must NOT be passed through as currentPlanId — every card,
     * including Starter, must be selectable — and isCancelled must be true
     * so the template swaps to "Subscribe again" copy.
     */
    public function testCancelledSubscriptionClearsCurrentPlanIdAndReportsCancelled(): void
    {
        $parameters = $this->renderAndCaptureViewParameters(SubscriptionStatus::CANCELLED);

        self::assertNull(
            $parameters['currentPlanId'],
            'A CANCELLED subscription\'s historical plan_id must not disable that plan\'s card.',
        );
        self::assertTrue($parameters['isCancelled']);
    }

    /**
     * Requirement 4 (CTA-wording bug): no card may be disabled while the
     * subscription is cancelled — currentPlanId stays null (Starter,
     * Business, Branded are all selectable).
     */
    public function testCancelledSubscriptionDisablesNoCards(): void
    {
        $parameters = $this->renderAndCaptureViewParameters(SubscriptionStatus::CANCELLED);

        self::assertNull($parameters['currentPlanId']);
    }

    /**
     * Requirement 1: for a CANCELLED subscription, previousPlanId must carry
     * the exact historical plan_id (here Starter's 'starter-monthly') — the
     * ONLY card _tiered_plans_grid.html.twig compares this against to decide
     * which single card says "Subscribe again".
     */
    public function testCancelledSubscriptionPreviousPlanIdMatchesTheHistoricalStarterPlan(): void
    {
        $parameters = $this->renderAndCaptureViewParameters(SubscriptionStatus::CANCELLED);

        self::assertSame('starter-monthly', $parameters['previousPlanId']);
    }

    /**
     * Requirements 2 & 3 (root cause fix): previousPlanId must be the ONE
     * specific historical planId, never a value that would also match
     * Business or Branded — those cards must fall through to "Choose %plan%"
     * in the partial instead of the old global "Subscribe again" bug.
     */
    public function testCancelledSubscriptionPreviousPlanIdDoesNotMatchOtherPlans(): void
    {
        $parameters = $this->renderAndCaptureViewParameters(SubscriptionStatus::CANCELLED);

        self::assertNotSame('business-monthly', $parameters['previousPlanId']);
        self::assertNotSame('branded-monthly', $parameters['previousPlanId']);
    }

    /**
     * Regression guard: for a non-cancelled (TRIAL/ACTIVE) subscription,
     * previousPlanId must stay null — it is only ever populated once
     * cancelled, since it exists purely for cancelled-state CTA wording.
     */
    public function testNonCancelledSubscriptionHasNoPreviousPlanId(): void
    {
        $parameters = $this->renderAndCaptureViewParameters(SubscriptionStatus::TRIAL);

        self::assertNull($parameters['previousPlanId']);
    }

    /**
     * @return array<string, mixed>
     */
    private function renderAndCaptureViewParameters(SubscriptionStatus $status): array
    {
        $bus = new CollectingMessageBus();

        $planRepository = $this->createMock(PlanRepositoryInterface::class);
        $planRepository->method('findAllOrdered')->willReturn([
            $this->makePlan('Starter'),
            $this->makePlan('Business'),
        ]);

        $subscription = new Subscription();
        $subscription->setPlan(
            new Plan()
                ->setName('Starter')
                ->setPlanId('starter-monthly')
                ->setPrice(1200)
                ->setActive(true),
        );
        $subscription->setStatus($status);

        $subscriptionProvider = $this->createMock(SubscriptionProviderInterface::class);
        $subscriptionProvider->method('getSubscriptionFor')->willReturn($subscription);

        $companyRepository = $this->createMock(CompanyRepository::class);
        $companyRepository->method('find')->willReturn(new Company());

        $companySelector = new CompanySelector($this->createStub(ManagerRegistry::class));
        new ReflectionProperty(CompanySelector::class, 'companyId')->setValue($companySelector, new Ulid());

        $action = new SelectPlanAction(
            $planRepository,
            $subscriptionProvider,
            $companyRepository,
            $companySelector,
            $this->makeTelemetry($bus),
            new PlanTierGrouper(),
        );

        $captured = null;
        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturnCallback(function (string $view, array $parameters) use (&$captured): string {
            $captured = $parameters;

            return '<html></html>';
        });

        $container = new Container();
        $container->set('twig', $twig);

        $action->setContainer($container);

        $action();

        self::assertIsArray($captured);

        return $captured;
    }

    private function makePlan(string $name): Plan
    {
        return new Plan()->setName($name);
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

    /**
     * A recording router double: PHPUnit mocks make it awkward to assert
     * BOTH the route name AND its parameters via a plain `willReturn()`, so
     * `generate()` is stubbed to record each call onto a separate plain
     * object the test can inspect afterwards. Same pattern already used in
     * ConfirmPlanChangeActionTest.
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
