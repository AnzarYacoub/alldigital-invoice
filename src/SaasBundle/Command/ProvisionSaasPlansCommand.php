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

namespace SolidInvoice\SaasBundle\Command;

use DateInterval;
use Override;
use SolidInvoice\SaasBundle\Feature\Feature;
use SolidWorx\Platform\PlatformBundle\Console\Command;
use SolidWorx\Platform\SaasBundle\Entity\Plan;
use SolidWorx\Platform\SaasBundle\Entity\PlanFeature;
use SolidWorx\Platform\SaasBundle\Feature\PlanFeatureManager;
use SolidWorx\Platform\SaasBundle\Repository\PlanFeatureRepository;
use SolidWorx\Platform\SaasBundle\Repository\PlanRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;
use function is_file;
use function sprintf;

/**
 * Idempotently provisions AllDigital Invoice's canonical SaaS plans, and
 * their feature matrix, directly in the database.
 *
 * This is the PRODUCTION-SAFE alternative to `doctrine:fixtures:load`:
 *  - It only ever creates or updates rows in `saas_plan` / `saas_plan_feature`.
 *  - It never reads or writes users, companies, invoices, quotes, payments,
 *    or any other customer data.
 *  - It never purges/truncates anything — Doctrine fixture loaders typically
 *    purge the database first, which is not acceptable in production.
 *  - Running it any number of times has the same end state: each plan is
 *    looked up by its unique `planId` and updated in place rather than
 *    duplicated (mirrors the vendor `saas:sync-plan` command's own
 *    find-or-create-by-planId idiom), and PlanFeatureManager::setFeature()
 *    is itself idempotent per plan+feature key.
 *
 * The plan/feature data below is intentionally the production source of
 * truth. `SaasBundle\DataFixtures\ORM\LoadPlans` / `LoadPlanFeatures` seed
 * the same catalogue for tests/dev via `doctrine:fixtures:load` (which is
 * fine there, since dev/test databases are expected to be reset) — if you
 * change pricing, trial length, or feature gates, update both places.
 *
 * Feature VALUES/TYPES come from PlanFeatureManager::setFeature(), which is
 * the correct, canonical way to write them. Feature DESCRIPTIONS are a
 * separate concern: setFeature() also copies a description onto the
 * PlanFeature row, but it reads that description from the DI-injected
 * FeatureConfigRegistry — a snapshot of platform.yaml's `saas.features.*.description`
 * values baked into the compiled container at cache-build time. Editing
 * platform.yaml does NOT retroactively update that snapshot; only clearing/
 * rebuilding the container (`bin/console cache:clear`) does. Because
 * platform.yaml is parsed manually in the vendor `Kernel::processPlatformConfig()`
 * (via `Yaml::parseFile()`) rather than loaded through Symfony's normal
 * container-extension config loading, it is never registered as a container
 * resource — so nothing here automatically invalidates that cache when the
 * file changes, in dev or prod.
 *
 * That means re-running this command with a stale container will faithfully
 * "update" every PlanFeature row's description... to the same stale text it
 * already had, over and over, which looks exactly like the command doing
 * nothing. To make provisioning resilient to that (rather than requiring
 * every operator to remember `cache:clear` before every provision), this
 * command re-parses platform.yaml directly — bypassing the cached
 * FeatureConfigRegistry entirely for descriptions — and force-syncs each
 * PlanFeature's description to whatever is on disk right now. This is
 * additional to, not a replacement for, PlanFeatureManager::setFeature(),
 * which remains the sole place that writes feature values/types.
 */
#[AsCommand(
    name: 'saas:plans:provision',
    description: 'Idempotently creates/updates the canonical AllDigital Invoice SaaS plans and feature matrix (safe for production — never touches customer data, never purges)',
)]
final class ProvisionSaasPlansCommand extends Command
{
    private const string TRIAL_DURATION = 'P14D';

    /**
     * @var list<array{planId: string, name: string, price: int, description: string, default: bool}>
     */
    private const array PLANS = [
        [
            'planId' => 'starter-monthly',
            'name' => 'Starter',
            'price' => 250000,
            'description' => 'Everything a solo business needs to quote, invoice and get paid.',
            'default' => true,
        ],
        [
            'planId' => 'starter-annual',
            'name' => 'Starter',
            'price' => 2500000,
            'description' => 'Everything a solo business needs to quote, invoice and get paid.',
            'default' => false,
        ],
        [
            'planId' => 'business-monthly',
            'name' => 'Business',
            'price' => 520000,
            'description' => 'Growing teams that need recurring billing, automation and branding.',
            'default' => false,
        ],
        [
            'planId' => 'business-annual',
            'name' => 'Business',
            'price' => 5200000,
            'description' => 'Growing teams that need recurring billing, automation and branding.',
            'default' => false,
        ],
        [
            'planId' => 'branded-monthly',
            'name' => 'Branded',
            'price' => 830000,
            'description' => 'White-label AllDigital Invoice on your own domain, with priority support.',
            'default' => false,
        ],
        [
            'planId' => 'branded-annual',
            'name' => 'Branded',
            'price' => 8300000,
            'description' => 'White-label AllDigital Invoice on your own domain, with priority support.',
            'default' => false,
        ],
    ];

    /**
     * Feature matrix keyed by tier name — both billing-interval variants of a
     * tier get the identical feature set (billing interval never affects
     * feature access, only price). Kept in step with
     * LoadPlanFeatures::TIER_MATRIX.
     *
     * @var array<string, array<string, int|bool>>
     */
    private const array TIER_FEATURES = [
        'Starter' => [
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
        'Business' => [
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
        'Branded' => [
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

    public function __construct(
        private readonly PlanRepository $planRepository,
        private readonly PlanFeatureManager $planFeatureManager,
        private readonly PlanFeatureRepository $planFeatureRepository,
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    /**
     * Reads `saas.features.*.description` straight from platform.yaml on
     * disk, bypassing the DI-injected FeatureConfigRegistry (which only
     * reflects platform.yaml as of the last container compile — see the
     * class docblock). This is what makes re-running this command actually
     * pick up a description change without requiring a manual cache:clear
     * first.
     *
     * @return array<string, string> feature key => description
     */
    private function loadCanonicalFeatureDescriptions(): array
    {
        foreach (['platform.yaml', 'platform.yml'] as $filename) {
            $path = $this->projectDir . '/' . $filename;

            if (! is_file($path)) {
                continue;
            }

            /** @var array{saas?: array{features?: array<string, array{description?: string}>}} $parsed */
            $parsed = Yaml::parseFile($path) ?? [];
            $descriptions = [];

            foreach ($parsed['saas']['features'] ?? [] as $featureKey => $featureConfig) {
                if (isset($featureConfig['description'])) {
                    $descriptions[$featureKey] = $featureConfig['description'];
                }
            }

            return $descriptions;
        }

        // No platform.yaml on disk (shouldn't happen outside of tests that
        // don't exercise this command) — fall back to whatever
        // PlanFeatureManager::setFeature() already wrote via the registry.
        return [];
    }

    #[Override]
    protected function handle(): int
    {
        $trial = new DateInterval(self::TRIAL_DURATION);
        $created = 0;
        $updated = 0;
        $descriptionsRefreshed = 0;

        $canonicalDescriptions = $this->loadCanonicalFeatureDescriptions();

        foreach (self::PLANS as $spec) {
            $plan = $this->planRepository->findOneBy(['planId' => $spec['planId']]);
            $isNew = ! $plan instanceof Plan;

            if ($isNew) {
                $plan = new Plan();
            }

            $plan->setPlanId($spec['planId']);
            $plan->setName($spec['name']);
            $plan->setPrice($spec['price']);
            $plan->setDescription($spec['description']);
            $plan->setTrialDuration($trial);
            $plan->setDefault($spec['default']);
            $plan->setActive(true);

            // Persist/flush the Plan itself first so it has a generated id
            // before PlanFeatureManager::setFeature() (below) keys its cache
            // and the PlanFeature relation on it.
            $this->planRepository->save($plan);

            foreach (self::TIER_FEATURES[$spec['name']] as $featureKey => $value) {
                // Writes the feature's value/type — this is the one and only
                // place that should ever do so.
                $this->planFeatureManager->setFeature($plan, $featureKey, $value);

                // setFeature() also stamped a description on this row, but
                // from the (possibly stale, container-cached) config
                // registry. Force it to match platform.yaml on disk right
                // now, so a copy edit takes effect the moment this command
                // is re-run — no cache:clear required.
                $canonicalDescription = $canonicalDescriptions[$featureKey] ?? null;

                if ($canonicalDescription !== null) {
                    $planFeature = $this->planFeatureRepository->findOneByPlanAndKey($plan, $featureKey);

                    if ($planFeature instanceof PlanFeature && $planFeature->getDescription() !== $canonicalDescription) {
                        $planFeature->setDescription($canonicalDescription);
                        $this->planFeatureRepository->save($planFeature);
                        ++$descriptionsRefreshed;
                    }
                }
            }

            if ($isNew) {
                ++$created;
                $this->io->writeln(sprintf('Created plan: %s', $spec['planId']));
            } else {
                ++$updated;
                $this->io->writeln(sprintf('Updated plan: %s', $spec['planId']));
            }
        }

        if ($descriptionsRefreshed > 0) {
            $this->io->writeln(sprintf('Refreshed %d feature description(s) from platform.yaml.', $descriptionsRefreshed));
        }

        $this->io->success(sprintf('Provisioned %d SaaS plan(s): %d created, %d updated.', $created + $updated, $created, $updated));

        return self::SUCCESS;
    }
}
