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

    // No billing provider is configured yet (Lemon Squeezy is not used for
    // AllDigital Invoice; HandyPay integration comes later). LemonSqueezy is
    // still the sole implementation of PaymentIntegrationInterface that
    // SubscriptionManager depends on, so its service must still compile —
    // but its $apiKey/$storeId constructor params are typed as non-nullable
    // `string`, and Symfony's CheckTypeDeclarationsPass fatally rejects a
    // `null` default here (that was the SaaS-mode boot crash). An empty
    // string satisfies that type and leaves the integration inert: any real
    // Lemon Squeezy API call (checkout, getPlans, etc.) will fail cleanly at
    // the HTTP layer rather than pretending to succeed, and none of the
    // trial/plan/feature-gate/pricing flows call into it at all. This is not
    // a placeholder credential — it is the explicit absence of one.
    $parameters->set('env(SOLIDINVOICE_LEMON_SQUEEZY_API_KEY)', '');
    $parameters->set('env(SOLIDINVOICE_LEMON_SQUEEZY_STORE_ID)', '');
    $parameters->set('env(SOLIDINVOICE_LEMON_SQUEEZY_WEBHOOK_SECRET)', '');
    $parameters->set('env(SOLIDINVOICE_SAAS_ONBOARDING_COUPON_CODE)', '');
};
