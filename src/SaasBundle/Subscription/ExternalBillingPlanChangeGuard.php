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

namespace SolidInvoice\SaasBundle\Subscription;

use SolidWorx\Platform\SaasBundle\Entity\Plan;
use SolidWorx\Platform\SaasBundle\Entity\Subscription;
use SolidWorx\Platform\SaasBundle\Enum\SubscriptionStatus;
use SolidWorx\Platform\SaasBundle\Exception\PaymentIntegrationException;
use SolidWorx\Platform\SaasBundle\Subscription\SubscriptionManager;
use function sprintf;

/**
 * ROOT CAUSE (confirmed live): once the card-required-upfront flow gives
 * every paid trial a real HandyPay subscription (sub_...) from the start,
 * BOTH ChoosePlanAction (initial pick) and ConfirmPlanChangeAction
 * (change-plan page) could still reach `saas_subscription_checkout` for a
 * subscription that already had one attached. SubscribeController's
 * duplicate-checkout guard (added separately) then correctly refused to
 * start a second HandyPay subscription there — but neither calling action
 * had any awareness of that refusal, so the user's plan selection just
 * silently bounced back to the billing page with a generic "you already
 * have a subscription" message and no plan change occurred. Separately,
 * ChoosePlanAction's free-plan branch and ConfirmPlanChangeAction's
 * non-ACTIVE branch called `SubscriptionManager::changePlan()` directly for
 * an externally-billed TRIAL subscription — that method only guards against
 * ACTIVE-and-externally-billed (see vendor SubscriptionManager::changePlan()),
 * so for TRIAL it would have silently flipped the LOCAL `plan_id` to Free
 * while the real HandyPay subscription kept running (and would eventually
 * charge the card) in the background — the exact "local plan_id changes
 * out of sync with external billing state" danger this guard exists to
 * prevent.
 *
 * This is the single place both actions now go through whenever
 * `Subscription::isExternallyBilled()` is true (a real `sub_...` is
 * attached — regardless of TRIAL vs ACTIVE status):
 *
 *  - Switching to the FREE plan is safe and already fully supported today:
 *    HandyPay documents a real "cancel at end of billing period" endpoint
 *    (`POST /subscriptions/{id}/cancel`, see HandyPay::cancelAtPeriodEnd()),
 *    which `SubscriptionManager::scheduleDowngrade()` already uses — the
 *    user keeps their current paid access until the period/trial end, the
 *    REAL external subscription is actually cancelled at that same date,
 *    and only then does local state move to Free. The `sub_...` id itself
 *    is left untouched (not cleared, not replaced) so it stays the record
 *    of what was actually billed.
 *  - Switching to any OTHER (still-paid) plan has no safe path today:
 *    HandyPay's docs do not show a price-change endpoint for an existing
 *    subscription (see HandyPay::changePlan(), which always throws
 *    PaymentIntegrationException — confirmed, not guessed). Rather than
 *    silently mutate the local plan_id out of sync with what HandyPay is
 *    actually billing, or start a second checkout/subscription as a
 *    workaround (which is exactly the duplicate-subscription bug fixed
 *    separately), this is a deliberate, explicit, user-facing block.
 *
 * If HandyPay's API ever adds a documented price-change endpoint, wire it
 * into HandyPay::changePlan() first (replacing its current unconditional
 * throw) — SubscriptionManager::changeActivePlan() already calls it and
 * would start working the moment that happens, and this guard's "no safe
 * path" branch below would then need to call it instead of blocking.
 */
final readonly class ExternalBillingPlanChangeGuard
{
    public function __construct(
        private SubscriptionManager $subscriptionManager,
    ) {
    }

    /**
     * Returns null when the subscription has no external billing yet, in
     * which case the caller should proceed with its normal local-commit /
     * first-time-checkout flow exactly as before. Returns a result when
     * this guard has already handled (or explicitly blocked) the change —
     * the caller must flash that message and redirect, and do nothing else.
     */
    public function handle(Subscription $subscription, Plan $newPlan): ?PlanChangeGuardResult
    {
        // A CANCELLED subscription keeps its old (now-ended) `sub_...` id
        // forever — it is never cleared, so it stays the historical record
        // of what was actually billed (see CancelSubscriptionAction /
        // HandyPayWebhookConsumer). `isExternallyBilled()` alone can't
        // distinguish "still live upstream" from "that subscription is over
        // and this is a resubscribe", so this guard must stand down once
        // local state is CANCELLED and let the caller's normal
        // "no external billing yet" branch run — which sends a paid
        // selection to checkout exactly like a first-time subscriber,
        // whether it's the SAME plan as before or a different one.
        if (! $subscription->isExternallyBilled() || $subscription->getStatus() === SubscriptionStatus::CANCELLED) {
            return null;
        }

        if ($newPlan->isFree()) {
            try {
                $this->subscriptionManager->scheduleDowngrade($subscription, $newPlan);

                return new PlanChangeGuardResult(
                    'success',
                    'Your plan will be downgraded to Free at the end of your current billing period. You will not be charged again.',
                );
            } catch (PaymentIntegrationException $e) {
                return new PlanChangeGuardResult(
                    'error',
                    sprintf('Could not schedule your downgrade: %s', $e->getMessage()),
                );
            }
        }

        return new PlanChangeGuardResult(
            'error',
            'Plan changes for an active trial are not available yet. Cancel the current subscription first or contact support.',
        );
    }
}
