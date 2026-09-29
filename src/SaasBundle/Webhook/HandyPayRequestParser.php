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

namespace SolidInvoice\SaasBundle\Webhook;

use Override;
use SensitiveParameter;
use Symfony\Component\HttpFoundation\ChainRequestMatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcher\IsJsonRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcher\MethodRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RemoteEvent\RemoteEvent;
use Symfony\Component\Webhook\Client\AbstractRequestParser;
use Symfony\Component\Webhook\Exception\RejectWebhookException;
use function hash_equals;
use function hash_hmac;
use function is_string;
use function str_starts_with;
use function substr;

/**
 * Verifies and parses HandyPay webhooks.
 *
 * HEADER NAME — CURRENTLY UNCERTAIN, HANDLED DEFENSIVELY:
 * HandyPay's published API-reference page (https://tryhandypay.com/docs/api-reference)
 * describes `X-HandyPay-Signature: sha256={hex}`, and that is still what
 * this class checked first. But real production deliveries observed
 * against this integration are failing verification with HTTP 401, and
 * HandyPay's own current webhook example (per direct confirmation, not
 * this class's guess) instead shows the request carrying the signature
 * under `X-Payment-Signature`. Re-fetching the public docs pages
 * (api-reference, changelog, recipes) found no corroboration of
 * `X-Payment-Signature` there, so the two sources disagree — the public
 * docs page may simply be stale relative to what HandyPay actually sends.
 *
 * Rather than guess which one is authoritative, this class accepts EITHER
 * header name, and applies the *identical* strict check to whichever one
 * is present: HMAC-SHA256 of the raw request body with the configured
 * webhook secret, `sha256=` prefix stripped, compared with hash_equals().
 * No signature under either header still rejects with 401 — this does not
 * weaken or bypass verification, it only widens which header name is
 * accepted. Once you've confirmed with HandyPay (dashboard/support) which
 * header name is the real, permanent one, drop the other from
 * SIGNATURE_HEADERS below.
 *
 * Algorithm/signed-content details (HMAC-SHA256 of the raw body, `sha256=`
 * prefix) are unchanged from what the api-reference page confirmed, and
 * are not in question — only the header NAME was ever in doubt. This
 * mirrors Lemon Squeezy's own X-Signature scheme closely enough that
 * LemonSqueezyRequestParser (vendor) was a useful structural reference,
 * but the header name/format and the payload shape are HandyPay's own and
 * are NOT vendor code.
 *
 * Payload shape per the docs: {id, type, created, data}. The exact
 * shape of `data` for subscription/payment events (e.g. whether it is the
 * raw resource or a Stripe-style {object: {...}} wrapper) was not shown in
 * what was fetched, so HandyPayWebhookConsumer reads both.
 */
final class HandyPayRequestParser extends AbstractRequestParser
{
    /**
     * Checked in this order — the first one present on the request wins.
     * `X-Payment-Signature` is listed first because it's what HandyPay's
     * real deliveries are reportedly using now; `X-HandyPay-Signature`
     * stays as a fallback since it's what the published docs still say.
     * Both are verified with the exact same HMAC-SHA256 check below.
     *
     * @var list<string>
     */
    private const array SIGNATURE_HEADERS = [
        'X-Payment-Signature',
        'X-HandyPay-Signature',
    ];

    #[Override]
    protected function getRequestMatcher(): RequestMatcherInterface
    {
        return new ChainRequestMatcher([
            new IsJsonRequestMatcher(),
            new MethodRequestMatcher('POST'),
        ]);
    }

    #[Override]
    protected function doParse(Request $request, #[SensitiveParameter] string $secret): RemoteEvent
    {
        // Symfony's HeaderBag lookup is case-insensitive, so this also
        // matches a lowercase `x-payment-signature` / `x-handypay-signature`
        // exactly as HandyPay sends it.
        $signatureHeader = '';

        foreach (self::SIGNATURE_HEADERS as $headerName) {
            $value = (string) $request->headers->get($headerName, '');

            if ($value !== '') {
                $signatureHeader = $value;

                break;
            }
        }

        // Documented format is "sha256={hex}".
        $providedHash = str_starts_with($signatureHeader, 'sha256=')
            ? substr($signatureHeader, 7)
            : $signatureHeader;

        $expectedHash = hash_hmac('sha256', $request->getContent(), $secret);

        // Same strict check regardless of which header carried it: no
        // signature at all, or a mismatch, still rejects with 401.
        if ($providedHash === '' || ! hash_equals($expectedHash, $providedHash)) {
            throw new RejectWebhookException(Response::HTTP_UNAUTHORIZED, 'Invalid HandyPay webhook signature.');
        }

        // InputBag::all() already returns array (never anything else), so
        // the is_array() check PHPStan flagged as redundant is gone - the
        // required-field validation below is unchanged.
        $payload = $request->getPayload()->all();

        if (! isset($payload['type'], $payload['id'])) {
            throw new RejectWebhookException(Response::HTTP_BAD_REQUEST, 'Request payload does not contain required fields.');
        }

        $eventType = $payload['type'];
        $eventId = $payload['id'];

        if (! is_string($eventType) || ! is_string($eventId)) {
            throw new RejectWebhookException(Response::HTTP_BAD_REQUEST, 'Request payload "type"/"id" must be strings.');
        }

        // HandyPayWebhookConsumer does the event-type -> domain-event mapping
        // and pulls our local subscription id back out of `data`/`metadata`;
        // the parser's job is just signature verification + basic shape
        // validation, matching AbstractRequestParser's separation of concerns.
        return new RemoteEvent($eventType, $eventId, $payload);
    }
}
