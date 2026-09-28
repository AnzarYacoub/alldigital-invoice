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

use SolidInvoice\SaasBundle\Webhook\HandyPayRequestParser;
use Symfony\Config\FrameworkConfig;
use function Symfony\Component\DependencyInjection\Loader\Configurator\env;
use function Symfony\Component\DependencyInjection\Loader\Configurator\param;

return static function (FrameworkConfig $config): void {
    $config
        ->secret(env('SOLIDINVOICE_APP_SECRET'))
        ->phpErrors()
        ->log(true)
    ;

    $config->trustedHeaders([
        'x-forwarded-for',
        'x-forwarded-proto',
        'x-forwarded-port',
        'x-forwarded-host',
        'x-forwarded-prefix',
    ]);

    $config->session()
        ->name('SOLIDINVOICE_APP');

    $config
        ->assets()
        ->jsonManifestPath(param('kernel.project_dir') . '/public/static/manifest.json')
    ;

    $config->secrets()
        ->enabled(true)
        ->vaultDirectory(env('SOLIDINVOICE_CONFIG_DIR'))
    ;

    // HandyPay subscription-lifecycle webhooks. Lemon Squeezy's own webhook
    // route is wired by the vendor WebhookCompilerPass (hardcoded to
    // "lemon_squeezy", since a reusable bundle can't know this app's env
    // var names) — this is the app-level equivalent for HandyPay, using
    // symfony/webhook's own native config instead of touching vendor code.
    // Route: POST /webhook/handypay (the generic /webhook/{type} route is
    // registered in config/routes/webhook.yaml).
    //
    // ROOT CAUSE fix (confirmed live): this used to run unconditionally, but
    // HandyPayRequestParser is a SaasBundle service, and SaasBundle is only
    // registered when SOLIDINVOICE_PLATFORM=saas (see config/bundles.php).
    // That env var comes from .env.local in dev, but Symfony's Dotenv
    // deliberately does NOT load .env.local in the test environment (so
    // test runs are reproducible regardless of a developer's local
    // overrides) — and tests/bootstrap.php's own initial kernel boot (for
    // doctrine:database:create/schema:update, before any test class loads)
    // uses the plain Kernel, not SaasTestKernel, so nothing else sets it
    // either. The result: SaasBundle wasn't registered, HandyPayRequestParser
    // didn't exist as a service, yet this config still told the webhook
    // component to reference it — a hard container-compile failure, not a
    // test failure. Gated the same way config/bundles.php and
    // config/services_test.php already gate SaaS-only wiring, so: dev/SaaS
    // production (SOLIDINVOICE_PLATFORM=saas via .env.local or the real
    // environment) keeps registering this route exactly as before, and any
    // boot where SaasBundle isn't loaded simply doesn't declare a route that
    // has nothing to serve it — instead of declaring one that can't compile.
    if (($_ENV['SOLIDINVOICE_PLATFORM'] ?? $_SERVER['SOLIDINVOICE_PLATFORM'] ?? null) === 'saas') {
        $config->webhook()
            ->routing('handypay')
            ->service(HandyPayRequestParser::class)
            ->secret(env('HANDYPAY_WEBHOOK_SECRET'));
    }
};
