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

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $containerConfigurator): void {
    $parameters = $containerConfigurator->parameters();

    // AllDigital Invoice's active billing provider is HandyPay (see the
    // PaymentIntegrationInterface alias to HandyPay::class in
    // src/SaasBundle/Resources/config/services/services.php). Lemon Squeezy
    // is NOT used for billing, but its vendor service still must compile —
    // its $apiKey/$storeId constructor params are typed as non-nullable
    // `string`, and Symfony's CheckTypeDeclarationsPass fatally rejects a
    // `null` default here (that was the original SaaS-mode boot crash). An
    // empty string satisfies that type and leaves the integration inert:
    // any real Lemon Squeezy API call would fail cleanly at the HTTP layer,
    // and nothing autowires LemonSqueezy directly any more. This is not a
    // placeholder credential — it is the explicit absence of one.
    $parameters->set('env(SOLIDINVOICE_LEMON_SQUEEZY_API_KEY)', '');
    $parameters->set('env(SOLIDINVOICE_LEMON_SQUEEZY_STORE_ID)', '');
    $parameters->set('env(SOLIDINVOICE_LEMON_SQUEEZY_WEBHOOK_SECRET)', '');
    $parameters->set('env(SOLIDINVOICE_SAAS_ONBOARDING_COUPON_CODE)', '');

    // HandyPay (https://tryhandypay.com) — test-mode only (hp_test_... key).
    // Empty-string defaults so the container still compiles with none of
    // these set; HandyPay::checkout()/cancelAtPeriodEnd() etc. fail with a
    // clear exception rather than silently succeeding if actually called
    // without real values configured. Never set a live (hp_live_...) key
    // here or in any committed file — real values belong in .env.local /
    // the deployment secret store only.
    $parameters->set('env(HANDYPAY_API_KEY)', '');
    $parameters->set('env(HANDYPAY_API_BASE_URL)', 'https://api.handypay.me/api/v1');
    $parameters->set('env(HANDYPAY_WEBHOOK_SECRET)', '');
    $parameters->set('env(HANDYPAY_PRICE_ID_STARTER_MONTHLY)', '');
    $parameters->set('env(HANDYPAY_PRICE_ID_STARTER_ANNUAL)', '');
    $parameters->set('env(HANDYPAY_PRICE_ID_BUSINESS_MONTHLY)', '');
    $parameters->set('env(HANDYPAY_PRICE_ID_BUSINESS_ANNUAL)', '');
    $parameters->set('env(HANDYPAY_PRICE_ID_BRANDED_MONTHLY)', '');
    $parameters->set('env(HANDYPAY_PRICE_ID_BRANDED_ANNUAL)', '');

    // Maps this app's internal Plan.planId slug to the real HandyPay
    // price_id for that plan/interval (see HandyPay::checkout()'s
    // docblock for why this indirection exists instead of repurposing
    // Plan.planId itself). Populate the env vars above by running
    // `bin/console saas:handypay:sync-products` once per environment.
    $parameters->set('solidinvoice.saas.handypay.price_ids', [
        'starter-monthly' => '%env(HANDYPAY_PRICE_ID_STARTER_MONTHLY)%',
        'starter-annual' => '%env(HANDYPAY_PRICE_ID_STARTER_ANNUAL)%',
        'business-monthly' => '%env(HANDYPAY_PRICE_ID_BUSINESS_MONTHLY)%',
        'business-annual' => '%env(HANDYPAY_PRICE_ID_BUSINESS_ANNUAL)%',
        'branded-monthly' => '%env(HANDYPAY_PRICE_ID_BRANDED_MONTHLY)%',
        'branded-annual' => '%env(HANDYPAY_PRICE_ID_BRANDED_ANNUAL)%',
    ]);
};
