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

namespace SolidInvoice\SaasBundle\Action;

use SolidInvoice\CoreBundle\Company\CompanySelector;
use SolidInvoice\CoreBundle\Repository\CompanyRepository;
use SolidInvoice\CoreBundle\Telemetry\Telemetry;
use SolidInvoice\CoreBundle\Telemetry\TelemetryEvent;
use SolidInvoice\SaasBundle\Subscription\ExternalBillingPlanChangeGuard;
use SolidInvoice\SaasBundle\Subscription\PlanChangeGuardResult;
use SolidWorx\Platform\SaasBundle\Entity\Plan;
use SolidWorx\Platform\SaasBundle\Entity\Subscription;
use SolidWorx\Platform\SaasBundle\Enum\SubscriptionStatus;
use SolidWorx\Platform\SaasBundle\Repository\PlanRepositoryInterface;
use SolidWorx\Platform\SaasBundle\Subscription\SubscriptionManager;
use SolidWorx\Platform\SaasBundle\Subscription\SubscriptionProviderInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Ulid;
use function strtolower;

/**
 * @see \SolidInvoice\SaasBundle\Tests\Action\ChoosePlanActionTest
 */
final class ChoosePlanAction extends AbstractController
{
    /**
     * Query parameter used to hand off the desired plan id to the SaaS
     * checkout route. The subscription's `plan` field is intentionally NOT
     * mutated here for paid plans — that switch only commits once Lemon
     * Squeezy confirms the upgrade via webhook (see SubscriptionPlanSyncListener).
     */
    public const string PENDING_PLAN_QUERY_PARAMETER = 'plan';

    public function __construct(
        private readonly PlanRepositoryInterface $planRepository,
        private readonly SubscriptionManager $subscriptionManager,
        private readonly SubscriptionProviderInterface $subscriptionProvider,
        private readonly CompanyRepository $companyRepository,
        private readonly CompanySelector $companySelector,
        private readonly Telemetry $telemetry,
        private readonly ExternalBillingPlanChangeGuard $externalBillingGuard,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if (! $this->isCsrfTokenValid('choose_plan', (string) $request->request->get('_token', ''))) {
            $this->addFlash('error', 'Invalid security token, please try again.');

            return $this->redirectToRoute('saas_subscription_plans');
        }

        $subscription = $this->getSubscription();

        if (! $subscription instanceof Subscription) {
            $this->addFlash('error', 'No subscription found');

            return $this->redirectToRoute('_dashboard');
        }

        if (
            $subscription->getStatus() === SubscriptionStatus::ACTIVE
            && $subscription->isExternallyBilled()
        ) {
            return $this->redirectToRoute('billing_index');
        }

        $planId = (string) $request->request->get('plan', '');
        $plan = $planId === '' ? null : $this->planRepository->find($planId);

        if (! $plan instanceof Plan || ! $plan->isActive()) {
            $this->addFlash('error', 'The selected plan is invalid.');

            return $this->redirectToRoute('saas_subscription_plans');
        }

        $this->telemetry->event(TelemetryEvent::SaasPlanSelected, [
            'plan' => strtolower($plan->getName()),
            'is_paid' => ! $plan->isFree(),
        ]);

        // Selecting the plan already in effect is a no-op — UNLESS the
        // subscription has been CANCELLED, in which case the "same" plan is
        // no longer actually in effect and this is a resubscribe, which
        // must be allowed through exactly like picking a different plan
        // (see ExternalBillingPlanChangeGuard below, which also stands down
        // for a CANCELLED subscription).
        if (
            $subscription->isExternallyBilled()
            && $subscription->getStatus() !== SubscriptionStatus::CANCELLED
            && $subscription->getPlan()->getPlanId() === $plan->getPlanId()
        ) {
            return $this->redirectToRoute('billing_index');
        }

        // ROOT CAUSE (Bug 2, ChoosePlanAction side): under the
        // card-required-upfront flow, a subscription can already carry a
        // real HandyPay `sub_...` id while still in TRIAL status (not just
        // ACTIVE). Before this guard, a paid selection here always fell
        // through to the checkout redirect below — which SubscribeController's
        // duplicate-checkout guard now correctly refuses once a subscription
        // is externally billed, bouncing the user back with no plan change
        // and no explanation. And a free-plan selection would have called
        // SubscriptionManager::changePlan() directly, which only guards
        // ACTIVE-and-externally-billed — for TRIAL it would have silently
        // flipped the local plan to Free while the real HandyPay
        // subscription kept running uncancelled in the background.
        // ExternalBillingPlanChangeGuard centralises the safe behaviour
        // (schedule a real cancellation for a Free downgrade; block any
        // other paid-to-paid switch, since HandyPay has no price-change
        // endpoint) and is shared with ConfirmPlanChangeAction so both
        // plan-selection entry points behave identically.
        $guardResult = $this->externalBillingGuard->handle($subscription, $plan);

        if ($guardResult instanceof PlanChangeGuardResult) {
            $this->addFlash($guardResult->flashType, $guardResult->message);

            return $this->redirectToRoute('billing_index');
        }

        // From here the subscription has no external billing yet (never
        // checked out, or currently on the free plan) — safe to commit
        // locally or send to checkout exactly as before.
        if ($plan->isFree()) {
            $this->subscriptionManager->changePlan($subscription, $plan);
            $this->subscriptionManager->activate($subscription);
            $this->addFlash('success', 'Your free plan is now active.');

            return $this->redirectToRoute('_dashboard');
        }

        // Paid plan: defer the local plan switch. Pass the desired plan id
        // to the checkout route via query parameter — the webhook handler
        // commits the switch only once HandyPay confirms the subscription
        // (never trust the success redirect alone).
        return $this->redirectToRoute('saas_subscription_checkout', [
            self::PENDING_PLAN_QUERY_PARAMETER => $plan->getPlanId(),
        ]);
    }

    private function getSubscription(): ?Subscription
    {
        $companyId = $this->companySelector->getCompany();

        if (! $companyId instanceof Ulid) {
            return null;
        }

        $company = $this->companyRepository->find($companyId);

        if ($company === null) {
            return null;
        }

        return $this->subscriptionProvider->getSubscriptionFor($company);
    }
}
