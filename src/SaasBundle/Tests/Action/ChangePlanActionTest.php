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
use SolidInvoice\SaasBundle\Action\ChangePlanAction;
use SolidInvoice\SaasBundle\Plan\PlanTierGrouper;
use SolidWorx\Platform\SaasBundle\Entity\Plan;
use SolidWorx\Platform\SaasBundle\Entity\Subscription;
use SolidWorx\Platform\SaasBundle\Enum\SubscriptionStatus;
use SolidWorx\Platform\SaasBundle\Repository\PlanRepositoryInterface;
use SolidWorx\Platform\SaasBundle\Subscription\SubscriptionProviderInterface;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Uid\Ulid;
use Twig\Environment;

/**
 * Regression tests for the /billing/subscription/change equivalent of the
 * pricing-page bug: `currentPlanId` used to be set unconditionally from
 * `subscription->getPlan()->getPlanId()`, so a CANCELLED subscription's
 * historical plan_id (never cleared — see CancelSubscriptionAction /
 * HandyPayWebhookConsumer) kept disabling that plan's card here too.
 */
#[CoversClass(ChangePlanAction::class)]
final class ChangePlanActionTest extends TestCase
{
    /**
     * Requirements 1 & 2: TRIAL/ACTIVE subscriptions keep their real
     * currentPlanId (card stays disabled as "Current plan") and are not
     * reported as cancelled.
     */
    public function testTrialSubscriptionKeepsCurrentPlanIdAndIsNotCancelled(): void
    {
        $parameters = $this->renderAndCaptureViewParameters(SubscriptionStatus::TRIAL);

        self::assertSame('starter-monthly', $parameters['currentPlanId']);
        self::assertFalse($parameters['isCancelled']);
    }

    public function testActiveSubscriptionKeepsCurrentPlanIdAndIsNotCancelled(): void
    {
        $parameters = $this->renderAndCaptureViewParameters(SubscriptionStatus::ACTIVE);

        self::assertSame('starter-monthly', $parameters['currentPlanId']);
        self::assertFalse($parameters['isCancelled']);
    }

    /**
     * Requirement 3 (root cause fix): a CANCELLED subscription's historical
     * plan_id must not be passed through as currentPlanId.
     */
    public function testCancelledSubscriptionClearsCurrentPlanIdAndReportsCancelled(): void
    {
        $parameters = $this->renderAndCaptureViewParameters(SubscriptionStatus::CANCELLED);

        self::assertNull(
            $parameters['currentPlanId'],
            'A CANCELLED subscription\'s historical plan_id must not disable that plan\'s card.',
        );
        self::assertTrue($parameters['isCancelled']);
        // The historical plan reference itself must stay intact — this is
        // purely a view-layer null, never a DB mutation.
        self::assertSame('starter-monthly', $parameters['subscription']->getPlan()->getPlanId());
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
        $planRepository = $this->createMock(PlanRepositoryInterface::class);
        $planRepository->method('findAllOrdered')->willReturn([
            new Plan()->setName('Starter')->setPlanId('starter-monthly')->setPrice(1200)->setActive(true),
            new Plan()->setName('Business')->setPlanId('business-monthly')->setPrice(2500)->setActive(true),
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

        $action = new ChangePlanAction(
            $planRepository,
            $subscriptionProvider,
            $companyRepository,
            $companySelector,
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
}
