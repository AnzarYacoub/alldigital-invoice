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

namespace SolidInvoice\SaasBundle\DataFixtures\ORM;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use SolidInvoice\SaasBundle\Feature\Feature;
use SolidWorx\Platform\SaasBundle\Entity\Plan;
use SolidWorx\Platform\SaasBundle\Feature\PlanFeatureManager;

/**
 * Seeds per-plan feature overrides for the three canonical AllDigital Invoice
 * plans (Starter / Business / Branded). Each tier's monthly and annual Plan
 * row gets identical feature values — billing interval never affects feature
 * access, only price.
 *
 * Quota cases use -1 as the unlimited sentinel (FeatureValue::UNLIMITED).
 * Starter is intentionally unlimited on clients/invoices per the product
 * pricing brief, gated instead on team_seats=1 and the automation/branding/
 * API features it excludes.
 *
 * @codeCoverageIgnore
 */
final class LoadPlanFeatures extends Fixture implements DependentFixtureInterface
{
    /**
     * Feature matrix keyed by tier (not by individual plan reference) — both
     * billing-interval variants of a tier are seeded from the same row below.
     *
     * @var array<string, array<string, int|bool>>
     */
    private const array TIER_MATRIX = [
        'starter' => [
            Feature::TotalClients->value => -1,
            Feature::InvoicesPerMonth->value => -1,
            Feature::TeamSeats->value => 1,
            Feature::Quotes->value => true,
            Feature::OnlinePayments->value => true,
            Feature::RecurringInvoices->value => false,
            Feature::AutomatedReminders->value => false,
            Feature::MultiCurrency->value => true,
            Feature::CustomBranding->value => false,
            Feature::RestApiAccess->value => false,
            Feature::McpAccess->value => false,
            Feature::CustomDomain->value => false,
            Feature::CustomFields->value => false,
        ],
        'business' => [
            Feature::TotalClients->value => -1,
            Feature::InvoicesPerMonth->value => -1,
            Feature::TeamSeats->value => 5,
            Feature::Quotes->value => true,
            Feature::OnlinePayments->value => true,
            Feature::RecurringInvoices->value => true,
            Feature::AutomatedReminders->value => true,
            Feature::MultiCurrency->value => true,
            Feature::CustomBranding->value => true,
            Feature::RestApiAccess->value => false,
            Feature::McpAccess->value => false,
            Feature::CustomDomain->value => false,
            Feature::CustomFields->value => true,
        ],
        'branded' => [
            Feature::TotalClients->value => -1,
            Feature::InvoicesPerMonth->value => -1,
            Feature::TeamSeats->value => 10,
            Feature::Quotes->value => true,
            Feature::OnlinePayments->value => true,
            Feature::RecurringInvoices->value => true,
            Feature::AutomatedReminders->value => true,
            Feature::MultiCurrency->value => true,
            Feature::CustomBranding->value => true,
            Feature::RestApiAccess->value => true,
            Feature::McpAccess->value => true,
            Feature::CustomDomain->value => true,
            Feature::CustomFields->value => true,
        ],
    ];

    /**
     * Maps each Plan reference to the tier its features come from.
     *
     * @var array<string, string>
     */
    private const array PLAN_TIER = [
        LoadPlans::REF_STARTER_MONTHLY => 'starter',
        LoadPlans::REF_STARTER_ANNUAL => 'starter',
        LoadPlans::REF_BUSINESS_MONTHLY => 'business',
        LoadPlans::REF_BUSINESS_ANNUAL => 'business',
        LoadPlans::REF_BRANDED_MONTHLY => 'branded',
        LoadPlans::REF_BRANDED_ANNUAL => 'branded',
    ];

    public function __construct(
        private readonly PlanFeatureManager $planFeatureManager,
    ) {
    }

    public function getDependencies(): array
    {
        return [LoadPlans::class];
    }

    public function load(ObjectManager $manager): void
    {
        foreach (self::PLAN_TIER as $planReference => $tier) {
            $plan = $this->getReference($planReference, Plan::class);
            $features = self::TIER_MATRIX[$tier];

            foreach ($features as $featureKey => $value) {
                $this->planFeatureManager->setFeature($plan, $featureKey, $value);
            }
        }
    }
}
