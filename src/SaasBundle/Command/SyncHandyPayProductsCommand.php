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

use Override;
use SolidWorx\Platform\PlatformBundle\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use function is_string;
use function round;
use function rtrim;
use function sprintf;
use function str_starts_with;
use function substr;

/**
 * One-time (per-environment) helper: creates a HandyPay
 * `subscription-product` (POST /subscription-products) for each of
 * AllDigital Invoice's 6 plan/interval combinations, and prints the
 * resulting HandyPay `price.id` values as the exact `HANDYPAY_PRICE_ID_*`
 * env var lines to add to `.env.local` (or your secrets manager).
 *
 * IMPORTANT — HONEST LIMITATION, not glossed over: HandyPay's docs (as
 * fetched from https://tryhandypay.com/docs/api-reference) do not document
 * a "find existing product by name" or "list then match" lookup endpoint,
 * only POST to create and GET to list (shape for a single GET was not
 * shown either). That means this command CANNOT safely no-op on a re-run
 * the way ProvisionSaasPlansCommand does for local `saas_plan` rows —
 * running it twice risks creating 6 duplicate HandyPay products. It is
 * therefore NOT registered as idempotent, is intended to be run manually,
 * once, per HandyPay account (test, then eventually live), and prints a
 * loud confirmation prompt before creating anything.
 *
 * Confirm the created ids against the HandyPay dashboard before wiring
 * them into HANDYPAY_PRICE_ID_* for real, and re-check this command's
 * request/response field names against HandyPay's OpenAPI 3.1 contract
 * before relying on it beyond test mode.
 */
#[AsCommand(
    name: 'saas:handypay:sync-products',
    description: 'Creates the 6 AllDigital Invoice plan/interval HandyPay subscription-products (test mode) and prints the HANDYPAY_PRICE_ID_* values to configure — NOT idempotent, run manually once per HandyPay account',
)]
final class SyncHandyPayProductsCommand extends Command
{
    /**
     * Mirrors ProvisionSaasPlansCommand::PLANS — kept as a separate literal
     * copy rather than a shared dependency because this command's rows also
     * need `interval`/`env_suffix`, which have no meaning in the local
     * `saas_plan` schema. If you change pricing/naming, update both.
     *
     * Prices here are the human-readable USD amount (`priceUsd`, e.g. 12.00
     * for $12/mo) — the same list price ProvisionSaasPlansCommand seeds
     * locally, just expressed in dollars instead of that command's cents
     * integer. This does NOT read or change anything in the local
     * `saas_plan` table; it is only the literal source value this command
     * converts (via self::centsFromDollars()) into the integer-cents
     * `amount` HandyPay's API requires. Nothing here touches AllDigital
     * Invoice's own stored plan prices.
     *
     * @var list<array{planId: string, envSuffix: string, name: string, description: string, priceUsd: float, interval: string}>
     */
    private const array PRODUCTS = [
        ['planId' => 'starter-monthly', 'envSuffix' => 'STARTER_MONTHLY', 'name' => 'Starter (Monthly)', 'description' => 'Everything a solo business needs to quote, invoice and get paid.', 'priceUsd' => 12.00, 'interval' => 'monthly'],
        ['planId' => 'starter-annual', 'envSuffix' => 'STARTER_ANNUAL', 'name' => 'Starter (Annual)', 'description' => 'Everything a solo business needs to quote, invoice and get paid.', 'priceUsd' => 120.00, 'interval' => 'annual'],
        ['planId' => 'business-monthly', 'envSuffix' => 'BUSINESS_MONTHLY', 'name' => 'Business (Monthly)', 'description' => 'Growing teams that need recurring billing, automation and branding.', 'priceUsd' => 25.00, 'interval' => 'monthly'],
        ['planId' => 'business-annual', 'envSuffix' => 'BUSINESS_ANNUAL', 'name' => 'Business (Annual)', 'description' => 'Growing teams that need recurring billing, automation and branding.', 'priceUsd' => 250.00, 'interval' => 'annual'],
        ['planId' => 'branded-monthly', 'envSuffix' => 'BRANDED_MONTHLY', 'name' => 'Branded (Monthly)', 'description' => 'White-label AllDigital Invoice on your own domain, with priority support.', 'priceUsd' => 40.00, 'interval' => 'monthly'],
        ['planId' => 'branded-annual', 'envSuffix' => 'BRANDED_ANNUAL', 'name' => 'Branded (Annual)', 'description' => 'White-label AllDigital Invoice on your own domain, with priority support.', 'priceUsd' => 400.00, 'interval' => 'annual'],
    ];

    /**
     * Converts a USD dollar amount to an integer number of cents the way
     * HandyPay's `amount` field requires — safely, without floating-point
     * truncation. A naive `(int) ($dollars * 100)` can undercount: IEEE-754
     * binary floats can't represent most decimal fractions exactly (e.g.
     * `2.90 * 100` evaluates to `289.99999999999994`, which truncates to
     * 289 instead of 290). Rounding to the nearest cent BEFORE casting to
     * int avoids that — `round()` corrects the float back onto the nearest
     * whole number first, so the cast never has a fractional remainder to
     * chop off in the wrong direction.
     */
    private static function centsFromDollars(float $dollars): int
    {
        return (int) round($dollars * 100);
    }

    public function __construct(
        #[Autowire('%env(HANDYPAY_API_KEY)%')]
        private readonly string $apiKey,
        #[Autowire('%env(HANDYPAY_API_BASE_URL)%')]
        private readonly string $baseUrl,
        private readonly HttpClientInterface $httpClient,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function handle(): int
    {
        if ($this->apiKey === '') {
            $this->io->error('HANDYPAY_API_KEY is not set. Add a hp_test_... key to .env.local first.');

            return self::FAILURE;
        }

        if (! str_starts_with($this->apiKey, 'hp_test_')) {
            $this->io->error(sprintf(
                'HANDYPAY_API_KEY does not start with "hp_test_" (found a key starting with "%s..."). ' .
                'Refusing to run against a live key from this command — create test-mode products by ' .
                'hand in the HandyPay dashboard, or switch HANDYPAY_API_KEY to a hp_test_ key.',
                substr($this->apiKey, 0, 8),
            ));

            return self::FAILURE;
        }

        $this->io->warning(
            'This will create 6 NEW subscription-products in HandyPay test mode. HandyPay\'s docs do not ' .
            'document a way to find-or-reuse an existing product, so re-running this command will create ' .
            'duplicates. Only continue if you have not already created these test-mode products.',
        );

        if (! $this->io->confirm('Create 6 HandyPay test-mode subscription-products now?', false)) {
            $this->io->comment('Aborted — nothing was created.');

            return self::SUCCESS;
        }

        $client = $this->httpClient->withOptions([
            'base_uri' => rtrim($this->baseUrl, '/') . '/',
            'headers' => [
                'Authorization' => sprintf('Bearer %s', $this->apiKey),
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'X-API-Version' => '2025-01-01',
            ],
        ]);

        $rows = [];
        $envLines = [];

        foreach (self::PRODUCTS as $product) {
            $payload = [
                'name' => $product['name'],
                'description' => $product['description'],
                // HandyPay's create-subscription-product endpoint validates
                // this as "a positive integer in the smallest currency unit
                // (e.g., cents)" under the key `amount` — confirmed against
                // the live test API (an earlier `amount_cents` key was
                // rejected). Converted from the dollar list price above via
                // centsFromDollars(), never a hand-typed cents literal.
                'amount' => self::centsFromDollars($product['priceUsd']),
                'currency' => 'usd',
                'interval' => $product['interval'],
                'trial_period_days' => 14,
                'metadata' => ['plan_id' => $product['planId']],
            ];

            $response = $client->request('POST', 'subscription-products', ['json' => $payload]);
            $body = $response->toArray(false);

            if (($body['success'] ?? true) === false || $response->getStatusCode() >= 400) {
                $this->io->error(sprintf(
                    'Failed to create HandyPay product for plan "%s" (HTTP %d): %s',
                    $product['planId'],
                    $response->getStatusCode(),
                    $body['error']['message'] ?? 'unknown error',
                ));

                return self::FAILURE;
            }

            $data = $body['data'] ?? $body;
            $priceId = $data['subProduct']['price']['id'] ?? $data['price']['id'] ?? $data['id'] ?? null;

            if (! is_string($priceId) || $priceId === '') {
                $this->io->error(sprintf(
                    'HandyPay did not return a recognisable price id for plan "%s". ' .
                    'Check the real POST /subscription-products response shape against HandyPay\'s OpenAPI contract.',
                    $product['planId'],
                ));

                return self::FAILURE;
            }

            $rows[] = [$product['planId'], $priceId];
            $envLines[] = sprintf('HANDYPAY_PRICE_ID_%s=%s', $product['envSuffix'], $priceId);
        }

        $table = new Table($this->io);
        $table->setHeaders(['Plan ID', 'HandyPay price_id']);
        $table->setRows($rows);
        $table->render();

        $this->io->section('Add these lines to .env.local:');
        $this->io->writeln($envLines);

        $this->io->success('Created 6 HandyPay test-mode subscription-products. Verify them in the HandyPay dashboard before going further.');

        return self::SUCCESS;
    }
}
