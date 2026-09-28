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

namespace SolidInvoice\MailerBundle\Config;

use SolidInvoice\SettingsBundle\Config\ProviderInterface;
use SolidInvoice\SettingsBundle\DTO\Config;
use SolidInvoice\SettingsBundle\Form\Type\MailTransportType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;

final class ConfigProvider implements ProviderInterface
{
    /**
     * @return Config[]
     */
    public function provide(array $data): array
    {
        return [
            // ROOT CAUSE fix (confirmed live): this used to default to the
            // literal 'no-reply@solidinvoice.co'. Once seeded into the
            // settings table (at install), EmailFromListener treats ANY
            // non-empty email/from_address as authoritative and sends every
            // system email as SolidInvoice, permanently overriding the
            // correctly env-driven SOLIDINVOICE_MAILER_SENDER default (see
            // config/services.php) — so fixing only the env var's brand text
            // was not enough on its own. Defaulting to null here means a
            // fresh install leaves this setting empty until an admin
            // explicitly sets one in Settings > Email, and system email
            // correctly falls through to SOLIDINVOICE_MAILER_SENDER instead.
            new Config(
                'email/from_address',
                null,
                null,
                EmailType::class,
                ['trial_restricted' => true]
            ),
            new Config('email/from_name', $data['company_name'] ?? '', null, TextType::class),
            new Config(
                'email/sending_options/provider',
                null,
                null,
                MailTransportType::class,
                ['trial_restricted' => true]
            ),
        ];
    }
}
