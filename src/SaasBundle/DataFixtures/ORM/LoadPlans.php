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

use DateInterval;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use SolidWorx\Platform\SaasBundle\Entity\Plan;

/**
 * Seeds the three canonical AllDigital Invoice SaaS plans (Starter / Business /
 * Branded), each with a monthly and an annual billing variant.
 *
 * There is no separate "billing interval" column on Plan (see vendor entity) —
 * the platform's existing convention is one Plan row per price point, keyed by
 * planId (e.g. the previous fixture's "solo-monthly"). We follow that same
 * convention here: a tier is two Plan rows sharing the same display `name`
 * (so the pricing page can group them) but distinct planId/price — "-monthly"
 * and "-annual" suffixes.
 *
 * Every plan gets a 14-day trialDuration; SaasBundle\EventSubscriber\CompanyEventSubscriber
 * only actually starts a trial once, on first company creation for a user
 * (TrialManager::userHasTrial guards re-entry), so setting it uniformly here
 * is safe and means switching plans during the trial never re-grants one.
 *
 * Plan ids and prices are placeholders for dev/test only — production billing
 * identifiers are managed in the payment provider dashboard and synced via
 * webhooks (see PaymentIntegrationInterface).
 *
 * @codeCoverageIgnore
 */
final class LoadPlans extends Fixture
{
    public const string REF_STARTER_MONTHLY = 'plan_starter_monthly';

    public const string REF_STARTER_ANNUAL = 'plan_starter_annual';

    public const string REF_BUSINESS_MONTHLY = 'plan_business_monthly';

    public const string REF_BUSINESS_ANNUAL = 'plan_business_annual';

    public const string REF_BRANDED_MONTHLY = 'plan_branded_monthly';

    public const string REF_BRANDED_ANNUAL = 'plan_branded_annual';

    public function load(ObjectManager $manager): void
    {
        $trial = new DateInterval('P14D');

        $starterMonthly = new Plan()
            ->setName('Starter')
            ->setPlanId('starter-monthly')
            ->setPrice(1200)
            ->setDescription('Everything a solo business needs to quote, invoice and get paid.')
            ->setTrialDuration($trial)
            ->setDefault(true)
            ->setActive(true);

        $starterAnnual = new Plan()
            ->setName('Starter')
            ->setPlanId('starter-annual')
            ->setPrice(12000)
            ->setDescription('Everything a solo business needs to quote, invoice and get paid.')
            ->setTrialDuration($trial)
            ->setActive(true);

        $businessMonthly = new Plan()
            ->setName('Business')
            ->setPlanId('business-monthly')
            ->setPrice(2500)
            ->setDescription('Growing teams that need recurring billing, automation and branding.')
            ->setTrialDuration($trial)
            ->setActive(true);

        $businessAnnual = new Plan()
            ->setName('Business')
            ->setPlanId('business-annual')
            ->setPrice(25000)
            ->setDescription('Growing teams that need recurring billing, automation and branding.')
            ->setTrialDuration($trial)
            ->setActive(true);

        $brandedMonthly = new Plan()
            ->setName('Branded')
            ->setPlanId('branded-monthly')
            ->setPrice(4000)
            ->setDescription('White-label AllDigital Invoice on your own domain, with priority support.')
            ->setTrialDuration($trial)
            ->setActive(true);

        $brandedAnnual = new Plan()
            ->setName('Branded')
            ->setPlanId('branded-annual')
            ->setPrice(40000)
            ->setDescription('White-label AllDigital Invoice on your own domain, with priority support.')
            ->setTrialDuration($trial)
            ->setActive(true);

        $plans = [
            self::REF_STARTER_MONTHLY => $starterMonthly,
            self::REF_STARTER_ANNUAL => $starterAnnual,
            self::REF_BUSINESS_MONTHLY => $businessMonthly,
            self::REF_BUSINESS_ANNUAL => $businessAnnual,
            self::REF_BRANDED_MONTHLY => $brandedMonthly,
            self::REF_BRANDED_ANNUAL => $brandedAnnual,
        ];

        foreach ($plans as $plan) {
            $manager->persist($plan);
        }

        $manager->flush();

        foreach ($plans as $reference => $plan) {
            $this->addReference($reference, $plan);
        }
    }
}
