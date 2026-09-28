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

namespace SolidInvoice\SaasBundle\Tests\Plan;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SolidInvoice\SaasBundle\Plan\PlanTierGrouper;
use SolidWorx\Platform\SaasBundle\Entity\Plan;

/**
 * Regression tests for Bug 1 ("Change Plan prices are wrong"): the
 * change-plan page used to render via a flat, interval-unaware partial that
 * hardcoded "/month" on every card, so the *-annual rows' real annual-total
 * price (e.g. 12000 cents for Starter) displayed as "$120.00 /month"
 * instead of "$120.00 /year". PlanTierGrouper is the shared logic (reused
 * from the already-working pricing page) that both the pricing page and
 * the change-plan page now use to correctly bucket each tier's monthly and
 * annual Plan rows, so the template can pick the right label and price for
 * each — no prices are hardcoded in Twig, everything below comes straight
 * from the `saas_plan`-backed Plan entities, using this app's real seeded
 * prices (see LoadPlans fixture).
 */
#[CoversClass(PlanTierGrouper::class)]
final class PlanTierGrouperTest extends TestCase
{
    private PlanTierGrouper $grouper;

    protected function setUp(): void
    {
        $this->grouper = new PlanTierGrouper();
    }

    /**
     * Requirement 1: monthly cards show 12/25/40 (dollars) — i.e. the
     * monthly bucket for each tier carries the real seeded monthly price in
     * cents (1200/2500/4000), which the template renders as "/month".
     */
    public function testMonthlyBucketCarriesTheRealMonthlyPrice(): void
    {
        $tiers = $this->grouper->groupByTier($this->seededPlans());

        self::assertSame(1200, $tiers['Starter']['monthly']?->getPrice());
        self::assertSame(2500, $tiers['Business']['monthly']?->getPrice());
        self::assertSame(4000, $tiers['Branded']['monthly']?->getPrice());
    }

    /**
     * Requirement 2: yearly cards show 120/250/400 (dollars) — i.e. the
     * annual bucket for each tier carries the real seeded annual TOTAL
     * price in cents (12000/25000/40000), which the template renders as
     * "/year" rather than mislabelling it "/month".
     */
    public function testAnnualBucketCarriesTheRealAnnualTotalPrice(): void
    {
        $tiers = $this->grouper->groupByTier($this->seededPlans());

        self::assertSame(12000, $tiers['Starter']['annual']?->getPrice());
        self::assertSame(25000, $tiers['Business']['annual']?->getPrice());
        self::assertSame(40000, $tiers['Branded']['annual']?->getPrice());
    }

    /**
     * Requirement 3: monthly Business selection resolves to the
     * `business-monthly` planId — confirms the grouped monthly Plan for the
     * Business tier is the one whose planId the "Choose plan" form actually
     * submits (_tiered_plans_grid.html.twig posts `monthlyPlan.planId`).
     */
    public function testMonthlyBusinessSelectionResolvesToBusinessMonthlyPlanId(): void
    {
        $tiers = $this->grouper->groupByTier($this->seededPlans());

        self::assertSame('business-monthly', $tiers['Business']['monthly']?->getPlanId());
    }

    /**
     * Requirement 4: annual Business selection resolves to the
     * `business-annual` planId — same confirmation for the annual side
     * (_tiered_plans_grid.html.twig posts `annualPlan.planId`).
     */
    public function testAnnualBusinessSelectionResolvesToBusinessAnnualPlanId(): void
    {
        $tiers = $this->grouper->groupByTier($this->seededPlans());

        self::assertSame('business-annual', $tiers['Business']['annual']?->getPlanId());
    }

    public function testGroupingIsOrderIndependent(): void
    {
        $plans = $this->seededPlans();
        // Shuffle deterministically: reverse the array, so annual rows are
        // encountered before their monthly counterparts.
        $tiers = $this->grouper->groupByTier(array_reverse($plans));

        self::assertSame('starter-monthly', $tiers['Starter']['monthly']?->getPlanId());
        self::assertSame('starter-annual', $tiers['Starter']['annual']?->getPlanId());
    }

    /**
     * @return list<Plan>
     */
    private function seededPlans(): array
    {
        // Mirrors the exact 6 plans seeded by
        // src/SaasBundle/DataFixtures/ORM/LoadPlans.php — the only plans
        // that exist in this application.
        return [
            $this->makePlan('Starter', 'starter-monthly', 1200),
            $this->makePlan('Starter', 'starter-annual', 12000),
            $this->makePlan('Business', 'business-monthly', 2500),
            $this->makePlan('Business', 'business-annual', 25000),
            $this->makePlan('Branded', 'branded-monthly', 4000),
            $this->makePlan('Branded', 'branded-annual', 40000),
        ];
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
