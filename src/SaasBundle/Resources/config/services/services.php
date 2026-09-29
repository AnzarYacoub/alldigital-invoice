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

use SolidInvoice\CoreBundle\Contracts\EmailVerificationGateInterface;
use SolidInvoice\CoreBundle\Feature\UpgradePromptProvider;
use SolidInvoice\DashboardBundle\Checklist\ChecklistItemInterface;
use SolidInvoice\SaasBundle\Email\SaasEmailVerificationGate;
use SolidInvoice\SaasBundle\Feature\UpgradePromptRenderer;
use SolidInvoice\SaasBundle\Integration\HandyPay;
use SolidInvoice\SaasBundle\SolidInvoiceSaasBundle;
use SolidWorx\Platform\SaasBundle\Integration\PaymentIntegrationInterface;
use SolidWorx\Platform\SaasBundle\Subscription\SubscriptionManager;
use SolidWorx\Platform\SaasBundle\Subscription\SubscriptionProviderInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $containerConfigurator): void {
    $services = $containerConfigurator->services();

    $services
        ->defaults()
        ->autowire()
        ->autoconfigure()
        ->private()
    ;

    // Tag checklist items BEFORE load()
    $services
        ->instanceof(ChecklistItemInterface::class)
        ->tag('dashboard.checklist_item');

    $services
        ->load(SolidInvoiceSaasBundle::NAMESPACE . '\\', dirname(__DIR__, 3))
        ->exclude(dirname(__DIR__, 3) . '/{DependencyInjection,Entity,Message,Resources,Tests}');

    $services->alias(
        EmailVerificationGateInterface::class,
        SaasEmailVerificationGate::class,
    );

    $services->alias(
        UpgradePromptProvider::class,
        UpgradePromptRenderer::class,
    );

    // AllDigital Invoice's active billing provider. The vendor LemonSqueezy
    // service is left intact and still compiles (see config/packages/saas/
    // services.php) — it is simply no longer the interface's alias target.
    $services->alias(
        PaymentIntegrationInterface::class,
        HandyPay::class,
    );

    // Explicit alias for OnboardingManager's (UserBundle) nullable
    // SubscriptionProviderInterface dependency. Not strictly required for
    // this to autowire - SubscriptionManager is the only service
    // implementing this interface, and Symfony resolves an unaliased
    // interface automatically in that case - but it's made explicit here,
    // matching the PaymentIntegrationInterface/EmailVerificationGateInterface
    // aliases above, so this doesn't depend on "exactly one implementation"
    // staying true as the vendor package evolves.
    $services->alias(
        SubscriptionProviderInterface::class,
        SubscriptionManager::class,
    );
};
