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
use Error;
use SolidWorx\Platform\SaasBundle\Entity\Plan;
use SolidWorx\Platform\SaasBundle\Entity\Subscription;
use SolidWorx\Platform\SaasBundle\Enum\SubscriptionStatus;
use SolidWorx\Platform\SaasBundle\Repository\PlanRepositoryInterface;
use SolidWorx\Platform\SaasBundle\Subscription\SubscriptionProviderInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Ulid;
use function str_ends_with;

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

        return $this->render('@SolidInvoiceSaas/subscription/pricing.html.twig', [
            'plans' => $plans,
            'tiers' => $this->groupPlansByTier($plans),
            'subscription' => $subscription,
        ]);
    }

    /**
     * Groups plans that share a display name (e.g. "Starter") into their
     * billing-interval variants, so the pricing page can render one card
     * per tier with a monthly/yearly toggle instead of one card per Plan
     * row. There is no dedicated billing-interval column on Plan — by
     * convention (see LoadPlans fixture) each tier is two rows whose
     * planId ends in "-monthly" / "-annual"; anything else is treated as
     * a monthly-only (single-price) tier, which also keeps this working
     * for a plan that predates the convention.
     *
     * @param list<Plan> $plans
     *
     * @return array<string, array{monthly: ?Plan, annual: ?Plan}>
     */
    private function groupPlansByTier(array $plans): array
    {
        $tiers = [];

        foreach ($plans as $plan) {
            try {
                $interval = str_ends_with($plan->getPlanId(), '-annual') ? 'annual' : 'monthly';
            } catch (Error) {
                // Defensive: Plan::$planId is a non-nullable typed property with
                // no default, so a Plan instance that never had setPlanId()
                // called (only seen in isolated unit tests, never in real
                // fixture/DB-hydrated data, where planId is a NOT NULL unique
                // column) would otherwise fatally error here. Treat it as a
                // monthly-only tier instead of crashing the pricing page.
                $interval = 'monthly';
            }

            $tiers[$plan->getName()] ??= ['monthly' => null, 'annual' => null];
            $tiers[$plan->getName()][$interval] = $plan;
        }

        return $tiers;
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
