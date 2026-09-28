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

namespace SolidInvoice\SaasBundle\Tests\Subscription;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SolidInvoice\SaasBundle\Subscription\ExternalBillingPlanChangeGuard;
use SolidInvoice\SaasBundle\Subscription\PlanChangeGuardResult;
use SolidWorx\Platform\SaasBundle\Entity\Plan;
use SolidWorx\Platform\SaasBundle\Entity\Subscription;
use SolidWorx\Platform\SaasBundle\Exception\PaymentIntegrationException;
use SolidWorx\Platform\SaasBundle\Integration\PaymentIntegrationInterface;
use SolidWorx\Platform\SaasBundle\Repository\PlanRepositoryInterface;
use SolidWorx\Platform\SaasBundle\Repository\SubscriptionRepositoryInterface;
use SolidWorx\Platform\SaasBundle\Subscription\SubscriptionManager;

/**
 * Regression tests for Bug 2 ("Changing plan is not saving") for a
 * subscription that is already externally billed (a real HandyPay
 * `sub_...` id attached) — the exact state the reported company was in
 * (TRIAL status, real external subscription).
 */
#[CoversClass(ExternalBillingPlanChangeGuard::class)]
final class ExternalBillingPlanChangeGuardTest extends TestCase
{
    private const string ORIGINAL_SUBSCRIPTION_ID = 'sub_original123';

    /**
     * Requirement 5: an externally-billed trial cannot create a second
     * subscription during a plan change. The guard must block a
     * still-paid target plan outright — no payment-integration call is
     * made at all (checkout is never reached, so no second HandyPay
     * subscription can be created), and the local plan must not change.
     */
    public function testBlocksSwitchingToAnotherPaidPlanWithoutTouchingPaymentIntegration(): void
    {
        $currentPlan = $this->makePlan('Starter', 'starter-monthly', 1200);
        $targetPlan = $this->makePlan('Business', 'business-monthly', 2500);
        $subscription = $this->makeExternallyBilledSubscription($currentPlan);

        $paymentIntegration = $this->createMock(PaymentIntegrationInterface::class);
        $paymentIntegration->expects(self::never())->method('changePlan');
        $paymentIntegration->expects(self::never())->method('checkout');
        $paymentIntegration->expects(self::never())->method('cancelAtPeriodEnd');

        $subscriptionRepository = $this->createMock(SubscriptionRepositoryInterface::class);
        $subscriptionRepository->expects(self::never())->method('save');

        $guard = $this->makeGuard($subscriptionRepository, $paymentIntegration);

        $result = $guard->handle($subscription, $targetPlan);

        self::assertInstanceOf(PlanChangeGuardResult::class, $result);
        self::assertSame('error', $result->flashType);
        self::assertSame(
            'Plan changes for an active trial are not available yet. Cancel the current subscription first or contact support.',
            $result->message,
        );
    }

    /**
     * Requirement 6: if plan changing is supported (here: the one
     * HandyPay-supported operation, cancel-at-period-end for a downgrade to
     * Free), the existing `sub_...` remains authoritative — it must not be
     * cleared, replaced, or otherwise mutated by the guard.
     */
    public function testExistingExternalSubscriptionIdRemainsAuthoritativeAfterDowngrade(): void
    {
        $currentPlan = $this->makePlan('Starter', 'starter-monthly', 1200);
        $freePlan = $this->makePlan('Free', '0', 0);
        $subscription = $this->makeExternallyBilledSubscription($currentPlan);

        $effectiveAt = new DateTimeImmutable('+14 days');

        $paymentIntegration = $this->createMock(PaymentIntegrationInterface::class);
        $paymentIntegration->expects(self::once())
            ->method('cancelAtPeriodEnd')
            ->with($subscription)
            ->willReturn($effectiveAt);
        $paymentIntegration->expects(self::never())->method('changePlan');
        $paymentIntegration->expects(self::never())->method('checkout');

        $subscriptionRepository = $this->createMock(SubscriptionRepositoryInterface::class);
        $subscriptionRepository->method('save');

        $guard = $this->makeGuard($subscriptionRepository, $paymentIntegration);

        $result = $guard->handle($subscription, $freePlan);

        self::assertInstanceOf(PlanChangeGuardResult::class, $result);
        self::assertSame('success', $result->flashType);
        self::assertSame(
            self::ORIGINAL_SUBSCRIPTION_ID,
            $subscription->getSubscriptionId(),
            'scheduleDowngrade() must not touch the existing sub_... id — it stays the authoritative record of what HandyPay actually billed.',
        );
        // The current (paid) plan keeps applying until the scheduled date —
        // the switch to Free is deferred, not silently applied now.
        self::assertSame('starter-monthly', $subscription->getPlan()->getPlanId());
        self::assertSame($freePlan, $subscription->getPendingPlan());
    }

    /**
     * A subscription with no external billing yet must be left entirely
     * alone by the guard (it returns null so callers proceed with their
     * normal first-time-checkout / local-commit flow) — this guard must
     * never be the thing that blocks a brand-new subscriber.
     */
    public function testReturnsNullWhenSubscriptionIsNotExternallyBilled(): void
    {
        $currentPlan = $this->makePlan('Free', '0', 0);
        $targetPlan = $this->makePlan('Business', 'business-monthly', 2500);

        $subscription = new Subscription();
        $subscription->setPlan($currentPlan);
        // No setSubscriptionId() call: isExternallyBilled() === false.

        $paymentIntegration = $this->createMock(PaymentIntegrationInterface::class);
        $paymentIntegration->expects(self::never())->method('changePlan');
        $paymentIntegration->expects(self::never())->method('cancelAtPeriodEnd');

        $subscriptionRepository = $this->createMock(SubscriptionRepositoryInterface::class);

        $guard = $this->makeGuard($subscriptionRepository, $paymentIntegration);

        self::assertNull($guard->handle($subscription, $targetPlan));
    }

    /**
     * If HandyPay's cancel-at-period-end call itself fails, the guard must
     * surface that as an error result rather than silently mutating local
     * state or letting the caller fall through to checkout.
     */
    public function testDowngradeFailureIsReportedAsAnErrorResult(): void
    {
        $currentPlan = $this->makePlan('Starter', 'starter-monthly', 1200);
        $freePlan = $this->makePlan('Free', '0', 0);
        $subscription = $this->makeExternallyBilledSubscription($currentPlan);

        $paymentIntegration = $this->createMock(PaymentIntegrationInterface::class);
        $paymentIntegration->method('cancelAtPeriodEnd')
            ->willThrowException(new PaymentIntegrationException('HandyPay is unavailable'));

        $subscriptionRepository = $this->createMock(SubscriptionRepositoryInterface::class);
        $subscriptionRepository->expects(self::never())->method('save');

        $guard = $this->makeGuard($subscriptionRepository, $paymentIntegration);

        $result = $guard->handle($subscription, $freePlan);

        self::assertInstanceOf(PlanChangeGuardResult::class, $result);
        self::assertSame('error', $result->flashType);
        self::assertSame(
            self::ORIGINAL_SUBSCRIPTION_ID,
            $subscription->getSubscriptionId(),
            'A failed downgrade attempt must not touch the existing sub_... id either.',
        );
    }

    private function makeExternallyBilledSubscription(Plan $currentPlan): Subscription
    {
        $subscription = new Subscription();
        $subscription->setPlan($currentPlan);
        $subscription->setSubscriptionId(self::ORIGINAL_SUBSCRIPTION_ID);

        return $subscription;
    }

    private function makeGuard(
        SubscriptionRepositoryInterface $subscriptionRepository,
        PaymentIntegrationInterface $paymentIntegration,
    ): ExternalBillingPlanChangeGuard {
        // SubscriptionManager is final readonly; build a real one so
        // scheduleDowngrade()'s actual logic (setPendingPlan/setEndDate/save)
        // runs for real, with only its leaf dependencies mocked/stubbed.
        $subscriptionManager = new SubscriptionManager(
            $subscriptionRepository,
            $this->createStub(PlanRepositoryInterface::class),
            $paymentIntegration,
        );

        return new ExternalBillingPlanChangeGuard($subscriptionManager);
    }

    private function makePlan(string $name, string $planId, int $price): Plan
    {
        return new Plan()
            ->setName($name)
            ->setPlanId($planId)
            ->setPrice($price)
            ->setActive(true);
    }
}
