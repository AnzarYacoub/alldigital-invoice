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
use SolidInvoice\SaasBundle\Plan\PlanTierGrouper;
use SolidWorx\Platform\SaasBundle\Entity\Subscription;
use SolidWorx\Platform\SaasBundle\Enum\SubscriptionStatus;
use SolidWorx\Platform\SaasBundle\Repository\PlanRepositoryInterface;
use SolidWorx\Platform\SaasBundle\Subscription\SubscriptionProviderInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Ulid;

final class ChangePlanAction extends AbstractController
{
    public function __construct(
        private readonly PlanRepositoryInterface $planRepository,
        private readonly SubscriptionProviderInterface $subscriptionProvider,
        private readonly CompanyRepository $companyRepository,
        private readonly CompanySelector $companySelector,
        private readonly PlanTierGrouper $planTierGrouper,
    ) {
    }

    public function __invoke(): Response
    {
        $subscription = $this->getSubscription();

        if (! $subscription instanceof Subscription) {
            $this->addFlash('error', 'No subscription found.');

            return $this->redirectToRoute('saas_subscription_plans');
        }

        $plans = $this->planRepository->findAllOrdered();

        if ($plans === []) {
            $this->addFlash('error', 'No subscription plans are available.');

            return $this->redirectToRoute('billing_index');
        }

        // ROOT CAUSE (confirmed live): this page used to render via
        // _plans_grid.html.twig, a FLAT one-card-per-Plan-row partial with
        // no interval awareness — it printed every Plan row's raw price
        // next to a hardcoded "/month" label, so the *-annual rows (whose
        // `saas_plan.price` really is the annual total, e.g. 12000 cents
        // for Starter) showed as "$120.00 /month" instead of "$120.00
        // /year". Fixed by reusing the exact same tier-grouping +
        // _tiered_plans_grid.html.twig (with its working monthly/yearly
        // toggle) that the main pricing page (SelectPlanAction) already
        // used correctly — no prices are hardcoded here or in Twig; both
        // still come straight from `saas_plan` via PlanTierGrouper.
        // ROOT CAUSE (confirmed live): `currentPlanId` used to be set
        // unconditionally from `subscription->getPlan()->getPlanId()`, with
        // no regard for status. `saas_subscription.plan_id` is never
        // cleared on cancellation (it stays the historical record of what
        // was billed — see CancelSubscriptionAction / HandyPayWebhookConsumer),
        // so a CANCELLED subscriber's old plan kept showing here as a
        // disabled "Current plan" card, blocking exactly the resubscribe
        // (including to the SAME plan) this page needs to allow. Same rule
        // as SelectPlanAction's pricing page: currentPlanId is null
        // whenever there is no ACTIVE/TRIAL plan to protect from
        // re-selection.
        $isCancelled = $subscription->getStatus() === SubscriptionStatus::CANCELLED;

        // ROOT CAUSE (confirmed live): once currentPlanId was nulled for a
        // CANCELLED subscription (see above), every non-current card fell
        // back to one single isCancelled-driven "Subscribe again" label in
        // the partial — so Business/Branded said "Subscribe again" too, even
        // though the subscriber only ever had Starter. previousPlanId keeps
        // the historical plan_id available to the template for CTA wording
        // ONLY (never for the disabled/current-plan state, which stays
        // driven by currentPlanId alone).
        $previousPlanId = $isCancelled ? $subscription->getPlan()->getPlanId() : null;

        return $this->render('@SolidInvoiceSaas/subscription/change.html.twig', [
            'plans' => $plans,
            'tiers' => $this->planTierGrouper->groupByTier($plans),
            'subscription' => $subscription,
            'currentPlanId' => $isCancelled ? null : $subscription->getPlan()->getPlanId(),
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
