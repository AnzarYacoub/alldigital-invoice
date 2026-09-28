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

namespace SolidInvoice\SettingsBundle\Tests;

use const DATE_ATOM;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Money\Currency;
use PHPUnit\Framework\TestCase;
use SolidInvoice\CoreBundle\Test\Traits\DoctrineTestTrait;
use SolidInvoice\SettingsBundle\Entity\Setting;
use SolidInvoice\SettingsBundle\SystemConfig;
use function date;

final class SystemConfigTest extends TestCase
{
    use DoctrineTestTrait;
    use MockeryPHPUnitIntegration;

    public function testGet(): void
    {
        $config = new SystemConfig(date(DATE_ATOM), $this->em->getRepository(Setting::class));

        self::assertSame('SolidInvoice', $config->get('email/from_name'));
    }

    public function testGetCurrency(): void
    {
        $config = new SystemConfig(date(DATE_ATOM), $this->em->getRepository(Setting::class));

        self::assertInstanceOf(Currency::class, $config->getCurrency());
        self::assertSame('USD', $config->getCurrency()->getCode());
    }

    public function testGetAll(): void
    {
        $config = new SystemConfig(date(DATE_ATOM), $this->em->getRepository(Setting::class));

        self::assertSame([
            // ROOT CAUSE fix (confirmed live): MailerBundle's ConfigProvider used
            // to default 'email/from_address' to the literal
            // 'no-reply@solidinvoice.co', which DefaultData::createAppConfig()
            // seeds as the SEEDED VALUE (not just a display default) for every
            // new company — EmailFromListener then treated that as an explicit
            // admin-set from-address and sent every transactional email as
            // SolidInvoice, permanently overriding the correctly env-driven
            // SOLIDINVOICE_MAILER_SENDER default. Now null until an admin
            // explicitly sets one in Settings > Email.
            'email/from_address' => null,
            'email/from_name' => 'SolidInvoice',
            'email/sending_options/provider' => null,
            'invoice/bcc_address' => null,
            'invoice/email_subject' => 'New Invoice - #{id}',
            'invoice/id_generation/id_prefix' => '',
            'invoice/id_generation/id_suffix' => '',
            'invoice/id_generation/strategy' => 'auto_increment',
            'invoice/reminder/enabled' => '1',
            'invoice/reminder/pre_due_days' => '3',
            'invoice/reminder/pre_due_enabled' => '1',
            'invoice/watermark' => '1',
            'quote/bcc_address' => null,
            'quote/email_subject' => 'New Quotation - #{id}',
            'quote/id_generation/id_prefix' => '',
            'quote/id_generation/id_suffix' => '',
            'quote/id_generation/strategy' => 'auto_increment',
            'quote/watermark' => '1',
            'system/company/company_name' => 'SolidInvoice',
            'system/company/contact_details/address' => null,
            'system/company/contact_details/email' => null,
            'system/company/contact_details/phone_number' => null,
            'system/company/currency' => 'USD',
            'system/company/logo' => null,
        ], $config->getAll());
    }

    public function testInvalidGet(): void
    {
        $config = new SystemConfig(date(DATE_ATOM), $this->em->getRepository(Setting::class));

        self::assertNull($config->get('some/invalid/key'));
    }
}
