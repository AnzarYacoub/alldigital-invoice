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

namespace SolidInvoice\CoreBundle\Listener;

use SolidInvoice\CoreBundle\Company\CompanySelectorInterface;
use SolidInvoice\SettingsBundle\SystemConfig;
use SolidInvoice\UserBundle\Entity\User;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * @see \SolidInvoice\CoreBundle\Tests\Listener\EmailFromListenerTest
 */
final readonly class EmailFromListener implements EventSubscriberInterface
{
    public function __construct(
        private SystemConfig $config,
        private TokenStorageInterface $tokenStorage,
        // Used to guard the SystemConfig lookup below: `email/from_address`
        // and `email/from_name` are per-company settings, and
        // SettingsRepository::getSetting() runs an UNSCOPED query (no
        // company filter at all) whenever no company is active — which is
        // always true in CLI contexts (bin/console commands never go
        // through the HTTP-only CompanyEventSubscriber that switches the
        // active company). Querying SystemConfig in that situation can
        // return an arbitrary OTHER company's sender address/name. See
        // EmailFromListenerTest for the exact leakage scenario this guards
        // against.
        private CompanySelectorInterface $companySelector,
        // Central, app-wide Reply-To (SOLIDINVOICE_MAILER_REPLY_TO) — kept
        // separate from the From address so support replies can route to a
        // different inbox. Optional: '' (the default) disables it entirely.
        // Defaulted here (not just in the DI bind) so existing callers that
        // construct this listener directly, such as tests, keep working
        // unchanged.
        private string $mailerReplyTo = '',
    ) {
    }

    public function __invoke(MessageEvent $event): void
    {
        $message = $event->getMessage();

        if (! $message instanceof Email) {
            return;
        }

        if (null !== $this->companySelector->getCompany()) {
            // Active company (the normal case for real, web-generated
            // tenant emails): apply that company's configured sender
            // exactly as before.
            $this->applyCompanySender($message, $event);
        } elseif ([] === $message->getFrom()) {
            // No active company (e.g. a bin/console command) and the
            // message doesn't already have a From set. It is NOT safe to
            // read `email/from_address` / `email/from_name` from
            // SystemConfig here (unscoped, cross-tenant query — see the
            // constructor doc above), so we deliberately never call it in
            // this branch. Fall back to the authenticated user if one
            // somehow exists in this CLI context (edge case, mirrors the
            // pre-existing fallback), otherwise leave the From unset:
            // Symfony Mailer synthesizes it from the envelope sender,
            // which is bound to the env-driven SOLIDINVOICE_MAILER_SENDER
            // (see config/packages/mailer.php).
            $this->applyAuthenticatedUserFallback($message, $event);
        }
        // else: no active company, but the message already has an
        // explicit From (for example `bin/console mailer:test
        // --from=...`, or even its own `from@example.org` default) — leave
        // it completely unchanged and skip SystemConfig entirely, so
        // mailer:test behaves the way Symfony's own command documents it.

        // Applied last, independent of which From branch ran above, and
        // only when the specific email didn't already set its own
        // Reply-To (an individual TemplatedEmail subclass's own choice
        // always wins over this app-wide default).
        if ('' !== $this->mailerReplyTo && [] === $message->getReplyTo()) {
            $message->replyTo(Address::create($this->mailerReplyTo));
        }
    }

    private function applyCompanySender(Email $message, MessageEvent $event): void
    {
        $fromAddress = (string) $this->config->get('email/from_address');

        if ('' !== $fromAddress) {
            $fromName = (string) $this->config->get('email/from_name');
            $from = new Address($fromAddress, $fromName);
            $message->from($from);
            $event->getEnvelope()->setSender($from);
            $message->getHeaders()->remove('Sender');

            return;
        }

        $this->applyAuthenticatedUserFallback($message, $event);
    }

    private function applyAuthenticatedUserFallback(Email $message, MessageEvent $event): void
    {
        $token = $this->tokenStorage->getToken();

        if ($token instanceof TokenInterface) {
            /** @var User $user */
            $user = $token->getUser();
            $from = Address::create($user->getEmail());
            $message->from($from);
            $event->getEnvelope()->setSender($from);
            $message->getHeaders()->remove('Sender');
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            MessageEvent::class => ['__invoke', -256],
        ];
    }
}
