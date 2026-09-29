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
use SolidInvoice\SaasBundle\Plan\PlanTierGrouper;
use SolidWorx\Platform\SaasBundle\Entity\Subscription;
use SolidWorx\Platform\SaasBundle\Enum\SubscriptionStatus;
use SolidWorx\Platform\SaasBundle\Repository\PlanRepositoryInterface;
use SolidWorx\Platform\SaasBundle\Subscription\SubscriptionProviderInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Ulid;

/**
 * @see \SolidInvoice\SaasBundle\Tests\Action\SelectPlanActionTest
 */
final class SelectPlanAction extends AbstractController
{
    public function __construct(
        private readonly PlanRepositoryInterface $planRepository,
        private readonly SubscriptionProviderInterface $subscriptionProvider,
        private readonly CompanyRepository $companyRepository,
        private readonly CompanySelector $companySelector,
        private readonly Telemetry $telemetry,
        private readonly PlanTierGrouper $planTierGrouper,
    ) {
    }

    public function __invoke(): Response
    {
        $subscription = $this->getSubscription();

        if ($subscription instanceof Subscription && $subscription->getStatus() === SubscriptionStatus::ACTIVE) {
            return $this->redirectToRoute('billing_index');
        }

        $plans = $this->planRepository->findAllOrdered();

        if ($plans === []) {
            $this->addFlash('error', 'No subscription plans are available.');

            return $this->redirectToRoute('_dashboard');
        }

        if (count($plans) === 1) {
            return $this->redirectToRoute('saas_subscription_checkout');
        }

        $this->telemetry->event(TelemetryEvent::SaasPricingPageViewed);

        // ROOT CAUSE (confirmed live): `currentPlanId` used to be derived
        // straight from `subscription.plan.planId` in pricing.html.twig
        // itself, with no regard for subscription STATUS. `saas_subscription.plan_id`
        // is never cleared on cancellation (see CancelSubscriptionAction /
        // HandyPayWebhookConsumer — it stays the historical record of what
        // was billed), so a CANCELLED subscriber's old plan kept showing as
        // a disabled "Current plan" card here — even though that plan is no
        // longer actually in effect and must be fully selectable again
        // (including re-selecting the SAME plan, to resubscribe). Computed
        // here rather than in Twig so both this page and ChangePlanAction's
        // page share one rule: `currentPlanId` is null whenever there is no
        // ACTIVE/TRIAL plan to protect from re-selection.
        $isCancelled = $subscription instanceof Subscription && $subscription->getStatus() === SubscriptionStatus::CANCELLED;

        // ROOT CAUSE (confirmed live): once currentPlanId was nulled for a
        // CANCELLED subscription (see above), every non-current card fell
        // back to one single isCancelled-driven "Subscribe again" label in
        // the partial — so Business/Branded said "Subscribe again" too, even
        // though the subscriber only ever had Starter. previousPlanId keeps
        // the historical plan_id available to the template for CTA wording
        // ONLY (never for the disabled/current-plan state, which stays
        // driven by currentPlanId alone).
        $previousPlanId = $isCancelled && $subscription instanceof Subscription ? $subscription->getPlan()->getPlanId() : null;

        return $this->render('@SolidInvoiceSaas/subscription/pricing.html.twig', [
            'plans' => $plans,
            'tiers' => $this->planTierGrouper->groupByTier($plans),
            'subscription' => $subscription,
            'currentPlanId' => $subscription instanceof Subscription
                && $subscription->isExternallyBilled()
                && in_array($subscription->getStatus(), [
                    SubscriptionStatus::TRIAL,
                    SubscriptionStatus::ACTIVE,
                ], true)
                    ? $subscription->getPlan()->getPlanId()
                    : null,
            'isCancelled' => $isCancelled,
            'previousPlanId' => $previousPlanId,
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
