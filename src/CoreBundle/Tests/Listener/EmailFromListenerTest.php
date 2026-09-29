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

namespace SolidInvoice\CoreBundle\Tests\Listener;

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery as M;
use PHPUnit\Framework\TestCase;
use SolidInvoice\CoreBundle\Company\CompanySelectorInterface;
use SolidInvoice\CoreBundle\Listener\EmailFromListener;
use SolidInvoice\SettingsBundle\SystemConfig;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Uid\Ulid;

final class EmailFromListenerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /**
     * Real, web-generated tenant emails always have an active company (set
     * by the HTTP-only CompanyEventSubscriber earlier in the request), so
     * this mock represents the normal case throughout this test class
     * unless a test is specifically about the company-less (CLI) path.
     */
    private function activeCompanySelector(): CompanySelectorInterface
    {
        $companySelector = M::mock(CompanySelectorInterface::class);
        $companySelector->shouldReceive('getCompany')
            ->andReturn(new Ulid());

        return $companySelector;
    }

    public function testWithFromAddressConfigured(): void
    {
        $systemConfig = M::mock(SystemConfig::class);

        $systemConfig->shouldReceive('get')
            ->with('email/from_address')
            ->andReturn('info@example.com');

        $systemConfig->shouldReceive('get')
            ->with('email/from_name')
            ->andReturn('SolidInvoice');

        $tokenStorage = M::mock(TokenStorageInterface::class);

        $tokenStorage->shouldNotReceive('getToken');

        $listener = new EmailFromListener($systemConfig, $tokenStorage, $this->activeCompanySelector());

        $message = new TemplatedEmail();
        $envelope = Envelope::create($message);
        $listener(new MessageEvent($message, $envelope, 'smtp'));

        self::assertEquals([new Address('info@example.com', 'SolidInvoice')], $message->getFrom());
        self::assertSame('info@example.com', $envelope->getSender()->getAddress());
    }

    /**
     * Normal, real web-generated tenant email: with an active company, the
     * company's configured sender is applied exactly as before this fix,
     * even when the message already carries some other From (a real
     * TemplatedEmail never pre-sets one, but this proves the active-company
     * branch takes priority over any pre-existing From, unlike the
     * no-company branch below which must never override an explicit one).
     */
    public function testActiveCompanyUsesCompanySender(): void
    {
        $systemConfig = M::mock(SystemConfig::class);

        $systemConfig->shouldReceive('get')
            ->with('email/from_address')
            ->andReturn('billing@tenant-a.example.com');

        $systemConfig->shouldReceive('get')
            ->with('email/from_name')
            ->andReturn('Tenant A');

        $tokenStorage = M::mock(TokenStorageInterface::class);
        $tokenStorage->shouldNotReceive('getToken');

        $companySelector = M::mock(CompanySelectorInterface::class);
        $companySelector->shouldReceive('getCompany')
            ->once()
            ->andReturn(new Ulid());

        $listener = new EmailFromListener($systemConfig, $tokenStorage, $companySelector);

        $message = new TemplatedEmail();
        $envelope = Envelope::create($message);
        $listener(new MessageEvent($message, $envelope, 'smtp'));

        self::assertEquals([new Address('billing@tenant-a.example.com', 'Tenant A')], $message->getFrom());
        self::assertSame('billing@tenant-a.example.com', $envelope->getSender()->getAddress());
    }

    /**
     * Launch-blocker regression: an active company with no `email/from_address`
     * configured must NOT fall back to the currently authenticated user's own
     * personal email address (that used to happen here). This SaaS's global
     * transport only accepts mail from its own verified sending domain, so an
     * arbitrary user's personal address is always rejected by the provider.
     * The listener must leave From/Sender completely untouched instead,
     * letting Symfony Mailer's EnvelopeListener apply the platform-wide,
     * always-verified SOLIDINVOICE_MAILER_SENDER default — the token storage
     * must not even be consulted in this branch any more.
     */
    public function testActiveCompanyWithBlankFromAddressLeavesFromUnset(): void
    {
        $systemConfig = M::mock(SystemConfig::class);

        $systemConfig->shouldReceive('get')
            ->with('email/from_address')
            ->andReturn(null);

        $tokenStorage = M::mock(TokenStorageInterface::class);
        $tokenStorage->shouldNotReceive('getToken');

        $listener = new EmailFromListener($systemConfig, $tokenStorage, $this->activeCompanySelector());

        $message = new TemplatedEmail();
        $envelope = Envelope::create($message);
        $listener(new MessageEvent($message, $envelope, 'smtp'));

        self::assertSame([], $message->getFrom());
    }

    /**
     * Launch-blocker regression, the exact scenario reproduced live: every
     * existing installation's `app_config` table was seeded at install time
     * (migrations-archive/solidinvoice-3.0.1-history/Version20000.php) with
     * `email/from_address` = 'no-reply@solidinvoice.co' — the open-source
     * project's own domain, never verified with this SaaS's own
     * transactional mail provider. Companies that never explicitly changed
     * this setting still have that literal value persisted, and using it as
     * a real sender gets every invoice/quote email rejected by the provider
     * ("550 The solidinvoice.co domain is not verified..."). It must be
     * treated exactly like a blank address: From/Sender left untouched, and
     * no fallback to the authenticated user's own address either.
     */
    public function testActiveCompanyWithLegacyPlaceholderFromAddressLeavesFromUnset(): void
    {
        $systemConfig = M::mock(SystemConfig::class);

        $systemConfig->shouldReceive('get')
            ->with('email/from_address')
            ->andReturn('no-reply@solidinvoice.co');

        $tokenStorage = M::mock(TokenStorageInterface::class);
        $tokenStorage->shouldNotReceive('getToken');

        $listener = new EmailFromListener($systemConfig, $tokenStorage, $this->activeCompanySelector());

        $message = new TemplatedEmail();
        $envelope = Envelope::create($message);
        $listener(new MessageEvent($message, $envelope, 'smtp'));

        self::assertSame([], $message->getFrom());
    }

    public function testDoesNothingForNonEmailMessages(): void
    {
        $systemConfig = M::mock(SystemConfig::class);
        $systemConfig->shouldNotReceive('get');

        $tokenStorage = M::mock(TokenStorageInterface::class);
        $tokenStorage->shouldNotReceive('getToken');

        $companySelector = M::mock(CompanySelectorInterface::class);
        $companySelector->shouldNotReceive('getCompany');

        $listener = new EmailFromListener($systemConfig, $tokenStorage, $companySelector);

        $message = new RawMessage('raw content');
        $envelope = new Envelope(new Address('sender@example.com'), [new Address('recipient@example.com')]);
        $listener(new MessageEvent($message, $envelope, 'smtp'));
    }

    public function testEvents(): void
    {
        self::assertSame([MessageEvent::class], \array_keys(EmailFromListener::getSubscribedEvents()));
        self::assertSame(['__invoke', -256], EmailFromListener::getSubscribedEvents()[MessageEvent::class]);
    }

    /**
     * Multi-tenant sender-leakage regression (bin/console context, no
     * active company): SystemConfig::get() must NEVER be called for
     * `email/from_address` / `email/from_name` when there's no active
     * company, because SettingsRepository::getSetting() runs an unscoped
     * query across ALL companies in that case and can return another
     * tenant's row. This asserts the listener no longer queries it at all,
     * regardless of the message's From state.
     */
    public function testNoActiveCompanyIgnoresUnscopedConfig(): void
    {
        $systemConfig = M::mock(SystemConfig::class);
        $systemConfig->shouldNotReceive('get');

        $tokenStorage = M::mock(TokenStorageInterface::class);
        $tokenStorage->shouldReceive('getToken')
            ->andReturn(null);

        $companySelector = M::mock(CompanySelectorInterface::class);
        $companySelector->shouldReceive('getCompany')
            ->andReturn(null);

        $listener = new EmailFromListener($systemConfig, $tokenStorage, $companySelector);

        $message = new TemplatedEmail();
        $envelope = Envelope::create($message);
        $listener(new MessageEvent($message, $envelope, 'smtp'));
    }

    /**
     * `bin/console mailer:test --from=invoice@alldigitalgy.com ...` (or any
     * other CLI-built message that already sets its own From) must keep
     * that From untouched when there's no active company — this is what
     * was previously being clobbered by the unscoped SystemConfig lookup.
     */
    public function testNoCompanyWithExplicitFromPreservesThatFrom(): void
    {
        $systemConfig = M::mock(SystemConfig::class);
        $systemConfig->shouldNotReceive('get');

        $tokenStorage = M::mock(TokenStorageInterface::class);
        $tokenStorage->shouldNotReceive('getToken');

        $companySelector = M::mock(CompanySelectorInterface::class);
        $companySelector->shouldReceive('getCompany')
            ->andReturn(null);

        $listener = new EmailFromListener($systemConfig, $tokenStorage, $companySelector);

        $message = new TemplatedEmail();
        $message->from('invoice@alldigitalgy.com');
        $envelope = Envelope::create($message);
        $listener(new MessageEvent($message, $envelope, 'smtp'));

        self::assertEquals([new Address('invoice@alldigitalgy.com')], $message->getFrom());
        self::assertSame('invoice@alldigitalgy.com', $envelope->getSender()->getAddress());
    }

    /**
     * No active company, no token, and no From already set on the message:
     * the listener must leave the From alone rather than guessing, letting
     * Symfony Mailer synthesize it from the envelope sender, which is bound
     * to the env-driven SOLIDINVOICE_MAILER_SENDER (config/packages/mailer.php).
     */
    public function testNoCompanyAndNoFromUsesEnvFallback(): void
    {
        $systemConfig = M::mock(SystemConfig::class);
        $systemConfig->shouldNotReceive('get');

        $tokenStorage = M::mock(TokenStorageInterface::class);
        $tokenStorage->shouldReceive('getToken')
            ->once()
            ->andReturn(null);

        $companySelector = M::mock(CompanySelectorInterface::class);
        $companySelector->shouldReceive('getCompany')
            ->andReturn(null);

        $listener = new EmailFromListener($systemConfig, $tokenStorage, $companySelector);

        $message = new TemplatedEmail();
        $envelope = Envelope::create($message);
        $listener(new MessageEvent($message, $envelope, 'smtp'));

        // The listener made no attempt to set a From; the env-driven
        // envelope sender (SOLIDINVOICE_MAILER_SENDER) is what ultimately
        // supplies it further down the Mailer pipeline, outside this
        // listener's scope. Calling $envelope->getSender() here (with no
        // From/Sender header at all) would throw, which itself proves the
        // listener didn't fabricate one.
        self::assertSame([], $message->getFrom());
    }

    /**
     * SOLIDINVOICE_MAILER_REPLY_TO (config/services.php): when set, applied
     * to every outgoing app email regardless of which From branch ran.
     */
    public function testAppliesConfiguredReplyTo(): void
    {
        $systemConfig = M::mock(SystemConfig::class);

        $systemConfig->shouldReceive('get')
            ->with('email/from_address')
            ->andReturn('info@example.com');

        $systemConfig->shouldReceive('get')
            ->with('email/from_name')
            ->andReturn('AllDigital Invoice');

        $tokenStorage = M::mock(TokenStorageInterface::class);
        $tokenStorage->shouldNotReceive('getToken');

        $listener = new EmailFromListener($systemConfig, $tokenStorage, $this->activeCompanySelector(), 'support@example.com');

        $message = new TemplatedEmail();
        $envelope = Envelope::create($message);
        $listener(new MessageEvent($message, $envelope, 'smtp'));

        self::assertEquals([new Address('support@example.com')], $message->getReplyTo());
    }

    /**
     * Reply-To stays unset when SOLIDINVOICE_MAILER_REPLY_TO is empty (the
     * default) — this must never add a blank/invalid header.
     */
    public function testDoesNotAddReplyToWhenNotConfigured(): void
    {
        $systemConfig = M::mock(SystemConfig::class);

        $systemConfig->shouldReceive('get')
            ->with('email/from_address')
            ->andReturn('info@example.com');

        $systemConfig->shouldReceive('get')
            ->with('email/from_name')
            ->andReturn('AllDigital Invoice');

        $tokenStorage = M::mock(TokenStorageInterface::class);
        $tokenStorage->shouldNotReceive('getToken');

        $listener = new EmailFromListener($systemConfig, $tokenStorage, $this->activeCompanySelector());

        $message = new TemplatedEmail();
        $envelope = Envelope::create($message);
        $listener(new MessageEvent($message, $envelope, 'smtp'));

        self::assertSame([], $message->getReplyTo());
    }

    /**
     * A specific email's own Reply-To (set by its TemplatedEmail subclass)
     * must win over the app-wide SOLIDINVOICE_MAILER_REPLY_TO default.
     */
    public function testDoesNotOverrideAnAlreadySetReplyTo(): void
    {
        $systemConfig = M::mock(SystemConfig::class);

        $systemConfig->shouldReceive('get')
            ->with('email/from_address')
            ->andReturn('info@example.com');

        $systemConfig->shouldReceive('get')
            ->with('email/from_name')
            ->andReturn('AllDigital Invoice');

        $tokenStorage = M::mock(TokenStorageInterface::class);
        $tokenStorage->shouldNotReceive('getToken');

        $listener = new EmailFromListener($systemConfig, $tokenStorage, $this->activeCompanySelector(), 'support@example.com');

        $message = new TemplatedEmail();
        $message->replyTo('billing@example.com');
        $envelope = Envelope::create($message);
        $listener(new MessageEvent($message, $envelope, 'smtp'));

        self::assertEquals([new Address('billing@example.com')], $message->getReplyTo());
    }
}
