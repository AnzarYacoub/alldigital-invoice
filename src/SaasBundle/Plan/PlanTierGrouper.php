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

namespace SolidInvoice\SaasBundle\Plan;

use Error;
use SolidWorx\Platform\SaasBundle\Entity\Plan;
use function str_ends_with;

/**
 * Groups plans that share a display name (e.g. "Starter") into their
 * billing-interval variants, so a plan picker can render one card per tier
 * with a monthly/yearly toggle instead of one card per Plan row.
 *
 * Extracted from SelectPlanAction (the pricing page, `/billing/subscription/plans`)
 * so ChangePlanAction (`/billing/subscription/change`) can reuse the exact
 * same interval-grouping logic instead of a second, divergent implementation.
 * ChangePlanAction's page previously rendered via _plans_grid.html.twig — a
 * flat one-card-per-Plan-row partial with NO interval awareness at all — so
 * every annual Plan row (price stored in `saas_plan` as the real annual
 * total, e.g. 12000 cents for Starter) was shown next to a hardcoded
 * "/month" label. That page now uses _tiered_plans_grid.html.twig (the same
 * partial the pricing page already used correctly), driven by this same
 * grouper, so annual plans are labelled "/year" and monthly plans "/month" —
 * no prices are hardcoded in Twig; both come straight from `saas_plan` via
 * the Plan entities this method groups.
 *
 * There is no dedicated billing-interval column on Plan — by convention
 * (see LoadPlans fixture / ProvisionSaasPlansCommand) each tier is two rows
 * whose planId ends in "-monthly" / "-annual"; anything else is treated as
 * a monthly-only (single-price) tier, which also keeps this working for a
 * plan that predates the convention.
 */
final class PlanTierGrouper
{
    /**
     * @param list<Plan> $plans
     *
     * @return array<string, array{monthly: ?Plan, annual: ?Plan}>
     */
    public function groupByTier(array $plans): array
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
                // monthly-only tier instead of crashing the page.
                $interval = 'monthly';
            }

            $tiers[$plan->getName()] ??= ['monthly' => null, 'annual' => null];
            $tiers[$plan->getName()][$interval] = $plan;
        }

        return $tiers;
    }
}
