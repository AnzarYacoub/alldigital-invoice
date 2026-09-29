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

namespace SolidInvoice\MailerBundle\Tests\Factory;

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery as M;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use SolidInvoice\MailerBundle\Configurator\SesConfigurator;
use SolidInvoice\MailerBundle\Factory\MailerConfigFactory;
use SolidInvoice\SettingsBundle\SystemConfig;
use Symfony\Component\Mailer\Bridge\Amazon\Transport\SesApiAsyncAwsTransport;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\NullTransport;
use Symfony\Component\Mailer\Transport\Transports;

final class MailerConfigFactoryTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /**
     * The SaaS launch-blocker path: a company that has never configured its
     * own mail provider (`email/sending_options/provider` is null — the
     * "Mail Provider" dropdown in Settings > Email shown blank) must
     * transparently fall back to the platform-wide, env-driven
     * SOLIDINVOICE_MAILER_DSN transport, exactly as if this decorator
     * didn't exist. No customer should ever need this SaaS's own mail
     * provider credentials just to send an invoice.
     */
    public function testFromStringsFallsBackToGlobalDsnWhenNoProviderConfigured(): void
    {
        $systemConfig = M::mock(SystemConfig::class);

        $systemConfig->shouldReceive('get')
            ->with('email/sending_options/provider')
            ->andReturn(null);

        $factory = new MailerConfigFactory(new Transport(Transport::getDefaultFactories()), $systemConfig, [new SesConfigurator()]);

        $transport = $factory->fromStrings(['null://null']);

        // Symfony's own Transport::fromStrings() always wraps its result in
        // a Transports aggregate, even for a single-element $dsns array
        // (see vendor/symfony/mailer/Transport.php: it always calls `new
        // Transports($transports)`) - asserting a bare NullTransport here
        // would not match its real, documented return type (`fromStrings():
        // Transports`) and would make this test lie about the contract
        // it's actually verifying.
        self::assertInstanceOf(Transports::class, $transport);

        // Confirm the delegation really happened with the global DSN we
        // gave it, rather than just asserting *a* Transports came back:
        // Transports exposes no public accessor for its wrapped
        // transport(s), so unwrap the one underlying transport via
        // reflection and check it's the NullTransport that 'null://null'
        // resolves to.
        $wrapped = (new ReflectionProperty(Transports::class, 'transports'))->getValue($transport);

        self::assertCount(1, $wrapped);
        self::assertInstanceOf(NullTransport::class, reset($wrapped));
    }

    public function testFromStrings(): void
    {
        $systemConfig = M::mock(SystemConfig::class);

        $factory = new MailerConfigFactory(new Transport(Transport::getDefaultFactories()), $systemConfig, [new SesConfigurator()]);

        $systemConfig->shouldReceive('get')
            ->with('email/sending_options/provider')
            ->andReturn('{"provider": "Amazon SES", "config": {"accessKey": "foobar", "accessSecret": "baz"}}');

        self::assertInstanceOf(SesApiAsyncAwsTransport::class, $factory->fromStrings());
    }

    public function testFromStringsWithNoConfigurators(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid mailer config');

        $systemConfig = M::mock(SystemConfig::class);

        $factory = new MailerConfigFactory(new Transport(Transport::getDefaultFactories()), $systemConfig, []);

        $systemConfig->shouldReceive('get')
            ->with('email/sending_options/provider')
            ->andReturn('{"provider": "Amazon SES", "config": {"accessKey": "foobar", "accessSecret": "baz"}}');

        $factory->fromStrings();
    }
}
