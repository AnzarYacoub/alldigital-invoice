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

namespace SolidInvoice\SaasBundle\Integration;

use DateInterval;
use DateTimeImmutable;
use LogicException;
use Override;
use RuntimeException;
use SolidWorx\Platform\SaasBundle\Dto\IntegrationProduct;
use SolidWorx\Platform\SaasBundle\Entity\Plan;
use SolidWorx\Platform\SaasBundle\Entity\Subscription;
use SolidWorx\Platform\SaasBundle\Exception\PaymentIntegrationException;
use SolidWorx\Platform\SaasBundle\Integration\Options;
use SolidWorx\Platform\SaasBundle\Integration\PaymentIntegrationInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;
use function is_array;
use function is_int;
use function is_string;
use function rtrim;
use function sprintf;
use function str_replace;
use function strtoupper;

/**
 * HandyPay implementation of the provider-neutral
 * {@see PaymentIntegrationInterface} used by SubscriptionManager. This is
 * AllDigital Invoice's active billing provider; Lemon Squeezy's own
 * implementation ({@see \SolidWorx\Platform\SaasBundle\Integration\LemonSqueezy})
 * is left untouched in vendor and simply no longer aliased to the interface
 * (see the alias in src/SaasBundle/Resources/config/services/services.php).
 *
 * API reference used: https://tryhandypay.com/docs/api-reference (fetched
 * directly, not summarized, to confirm it is real documentation and not
 * fabricated). Base URI defaults to the one given for this integration:
 * https://api.handypay.me/api/v1
 *
 * IMPORTANT — confirmed vs. inferred:
 *  - Auth, /subscription-products, /subscription-sessions (both the
 *    saved-price and inline-pricing forms), /customers, webhook
 *    registration/signing, and test-mode key prefixes are confirmed from the
 *    fetched docs page.
 *  - The exact JSON shape of GET /subscriptions (list), a single
 *    GET /subscriptions/{id}, and the request/response body of
 *    POST /subscriptions/{id}/cancel were NOT shown in what was fetched —
 *    only endpoint existence and a one-line description ("cancel at end of
 *    billing period"). This class parses those responses defensively
 *    (several fallback field names) and throws a clear
 *    PaymentIntegrationException naming the field it expected if the shape
 *    doesn't match, rather than silently mishandling it.
 *  - HandyPay's docs page did not show a documented "change the price on an
 *    existing subscription" endpoint (only a quantity-change endpoint) or a
 *    hosted self-service customer-portal endpoint. Both are left as
 *    documented, explicit failures below rather than guessed at — see
 *    changePlan() / getCustomerPortalUrl() / resume().
 *
 * Before going live, pull the OpenAPI 3.1 contract HandyPay's docs mention
 * and cross-check the response-shape assumptions marked above.
 */
final class HandyPay implements PaymentIntegrationInterface
{
    /**
     * The trial length AllDigital Invoice offers on every plan today — kept
     * in step with ProvisionSaasPlansCommand::TRIAL_DURATION ('P14D') and
     * SyncHandyPayProductsCommand::PRODUCTS' `trial_period_days`. Sent
     * explicitly on every checkout session rather than derived from
     * Plan::getTrialDuration()'s DateInterval — see checkout()'s inline
     * comment for the DateInterval::format('%a') bug that made deriving it
     * actively wrong (it produced a 1-day trial, not 14).
     */
    private const int TRIAL_PERIOD_DAYS = 14;

    private readonly HttpClientInterface $httpClient;

    public function __construct(
        #[Autowire('%env(HANDYPAY_API_KEY)%')]
        string $apiKey,
        #[Autowire('%env(HANDYPAY_API_BASE_URL)%')]
        string $baseUrl,
        #[Autowire(param: 'solidworx_platform.saas.payment.return_route')]
        private readonly string $returnRoute,
        /**
         * Maps this app's internal Plan.planId slug ('starter-monthly', …)
         * to the real HandyPay `price_id` returned by
         * POST /subscription-products. Plan.planId is architecturally meant
         * by the vendor to BE the provider's price/variant id (see
         * SubscriptionPlanSyncListener), but this app already uses it
         * elsewhere as a human-readable slug (tier grouping in
         * SelectPlanAction, Twig templates, etc.), so introducing a second,
         * parallel id here — rather than repurposing planId — avoids a
         * much larger refactor. Populated from HANDYPAY_PRICE_ID_* env vars
         * in services.php; run `saas:handypay:sync-products` once per
         * environment to obtain the real values.
         *
         * @var array<string, string>
         */
        #[Autowire(param: 'solidinvoice.saas.handypay.price_ids')]
        private readonly array $priceIdMap,
        private readonly UrlGeneratorInterface $router,
    ) {
        $this->httpClient = HttpClient::createForBaseUri(
            rtrim($baseUrl, '/') . '/',
            [
                'headers' => [
                    'Authorization' => sprintf('Bearer %s', $apiKey),
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'X-API-Version' => '2025-01-01',
                ],
            ],
        );
    }

    /**
     * Creates a HandyPay hosted subscription checkout session and returns
     * its URL. HandyPay collects and stores the card itself — AllDigital
     * Invoice never sees or stores raw card data.
     *
     * The internal Plan.planId ('starter-monthly' etc., seeded by
     * ProvisionSaasPlansCommand) is translated to a real HandyPay
     * `price_id` via $priceIdMap (HANDYPAY_PRICE_ID_* env vars). Run
     * `saas:handypay:sync-products` first to obtain those ids.
     */
    #[Override]
    public function checkout(Subscription $subscription, ?Options $options = null): string
    {
        $planId = $subscription->getPlan()->getPlanId();
        $priceId = $this->priceIdMap[$planId] ?? null;

        if (! is_string($priceId) || $priceId === '') {
            throw new PaymentIntegrationException(sprintf(
                'No HandyPay price id is configured for plan "%s". Run `saas:handypay:sync-products` ' .
                'and set HANDYPAY_PRICE_ID_%s in your environment.',
                $planId,
                strtoupper(str_replace('-', '_', $planId)),
            ));
        }

        $skipTrial = $options?->getValue(Options::SKIP_TRIAL) === true;
        $email = $options?->getValue(Options::EMAIL);

        $successUrl = $this->router->generate(
            $this->returnRoute,
            ['session_id' => '{CHECKOUT_SESSION_ID}'],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
        // Symfony's router percent-encodes the literal placeholder braces;
        // HandyPay's docs show them unescaped in success_url, so restore them.
        $successUrl = str_replace(['%7B', '%7D'], ['{', '}'], $successUrl);

        $cancelUrl = $this->router->generate('saas_subscription_plans', [], UrlGeneratorInterface::ABSOLUTE_URL);

        $payload = [
            'price_id' => $priceId,
            'quantity' => 1,
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => [
                // Read back by HandyPayRequestParser/HandyPayWebhookConsumer
                // to map a webhook event back to our local Subscription row.
                'subscription_id' => $subscription->getId()->toBase58(),
            ],
        ];

        if (! $skipTrial) {
            // ROOT CAUSE of the "1 day free" bug: DateInterval::format('%a')
            // (total days) is only populated when the interval was produced
            // by DateTime::diff() — PHP's own docs call it "(unknown)"
            // otherwise. Plan::getTrialDuration() is built by
            // ProvisionSaasPlansCommand as `new DateInterval('P14D')`, a
            // directly-constructed interval, so `%a` really did return the
            // literal string "(unknown)" here. `(int) '(unknown)'` casts to
            // 0 (PHP's int-cast of a non-numeric string), and
            // `max(1, 0)` === 1 — exactly the 1-day trial HandyPay's
            // checkout showed, every single time, regardless of the plan.
            //
            // Fixed by not deriving this from Plan's DateInterval at all.
            // AllDigital Invoice offers one trial length across every plan
            // today (also hardcoded as `trial_period_days: 14` in
            // SyncHandyPayProductsCommand's product-creation payload, which
            // was never affected by this bug), so the checkout SESSION now
            // sends that same literal value explicitly, as a defensive
            // override on top of whatever trial_period_days the
            // subscription-product itself was created with — per HandyPay's
            // docs, a saved-price subscription-session accepts
            // trial_period_days directly, and a session-level value takes
            // precedence over the product's own default.
            $payload['trial_period_days'] = self::TRIAL_PERIOD_DAYS;
        }

        if (is_string($email) && $email !== '') {
            $payload['customer_email'] = $email;
        }

        $response = $this->httpClient->request(Request::METHOD_POST, 'subscription-sessions', [
            'json' => $payload,
        ]);

        $data = $this->decode($response, 'create checkout session');
        $url = $data['session']['url'] ?? $data['url'] ?? null;

        if (! is_string($url) || $url === '') {
            throw new PaymentIntegrationException(
                'HandyPay did not return a checkout session URL (expected data.session.url). ' .
                'Verify the current /subscription-sessions response shape against HandyPay\'s OpenAPI contract.',
            );
        }

        return $url;
    }

    /**
     * Lists HandyPay subscription products. Endpoint existence for a GET
     * list on /subscription-products was not directly confirmed in the
     * fetched docs (only POST was shown) — this defensively no-ops (yields
     * nothing) on a 404/405 rather than throwing, so callers that merely
     * want to display already-known local Plan data are not broken by an
     * unconfirmed endpoint.
     *
     * @return iterable<IntegrationProduct>
     */
    #[Override]
    public function getPlans(): iterable
    {
        try {
            $response = $this->httpClient->request(Request::METHOD_GET, 'subscription-products');
        } catch (Throwable) {
            return;
        }

        if ($response->getStatusCode() >= 400) {
            return;
        }

        $data = $this->decode($response, 'list subscription products');
        // decode() returns array (never null), so the trailing '?? []' was
        // dead code - $data is always a valid fallback on its own.
        $products = $data['subscription_products'] ?? $data['products'] ?? $data;

        if (! is_array($products)) {
            return;
        }

        foreach ($products as $product) {
            if (! is_array($product)) {
                continue;
            }

            $price = $product['price'] ?? $product;
            $priceId = $price['id'] ?? $product['id'] ?? null;

            if (! is_string($priceId)) {
                continue;
            }

            yield new IntegrationProduct(
                id: $priceId,
                name: (string) ($product['name'] ?? ''),
                description: (string) ($product['description'] ?? ''),
                price: (int) ($price['amount'] ?? $product['amount'] ?? 0),
                interval: new DateInterval(
                    match ($price['interval'] ?? $product['interval'] ?? 'monthly') {
                        'annual' => 'P1Y',
                        'semi-annual' => 'P6M',
                        'quarterly' => 'P3M',
                        'bi-monthly' => 'P2M',
                        'weekly' => 'P1W',
                        'bi-weekly' => 'P2W',
                        default => 'P1M',
                    },
                ),
            );
        }
    }

    /**
     * HandyPay's docs (as fetched) do not show a documented hosted
     * self-service customer portal endpoint, unlike Lemon Squeezy's
     * `urls.customer_portal`. Rather than guess at an undocumented
     * endpoint, this fails loudly: AllDigital Invoice's own "Cancel
     * subscription" action (CancelSubscriptionAction) calls
     * cancelAtPeriodEnd() below directly instead of routing through a
     * portal. If HandyPay's OpenAPI contract turns out to document one,
     * wire it in here.
     */
    #[Override]
    public function getCustomerPortalUrl(Subscription $subscription): string
    {
        throw new LogicException(
            'HandyPay has no documented self-service customer portal. Use the in-app ' .
            '"Cancel subscription" action instead of a provider-hosted portal link.',
        );
    }

    /**
     * HandyPay's docs (as fetched) document a quantity-change endpoint
     * (PATCH /subscriptions/{id}/quantity) but not a price/plan-change
     * endpoint for an existing subscription. Fabricating one would violate
     * "do not hardcode undocumented request/response shapes", so this is a
     * deliberate, clearly-labelled gap rather than a guess. Until HandyPay's
     * OpenAPI contract confirms a real endpoint for this, an in-trial or
     * active HandyPay subscriber who wants a different plan must cancel and
     * start a fresh checkout for the new plan.
     */
    #[Override]
    public function changePlan(Subscription $subscription, Plan $newPlan): DateTimeImmutable
    {
        throw new PaymentIntegrationException(
            'HandyPay does not document a plan/price-change endpoint for an existing subscription ' .
            '(only a quantity-change endpoint was found). Cancel and start a new checkout for the ' .
            'new plan instead, or confirm the real endpoint against HandyPay\'s OpenAPI contract ' .
            'before implementing this.',
        );
    }

    /**
     * Cancels at the end of the current billing period (trial or paid) —
     * per HandyPay's docs, "cancel at end of billing period" is exactly
     * this endpoint's documented behaviour, which also satisfies "cancel
     * during trial prevents the first charge".
     */
    #[Override]
    public function cancelAtPeriodEnd(Subscription $subscription): DateTimeImmutable
    {
        $subscriptionId = $this->requireSubscriptionId($subscription);

        $response = $this->httpClient->request(
            Request::METHOD_POST,
            sprintf('subscriptions/%s/cancel', $subscriptionId),
        );

        $data = $this->decode($response, 'cancel subscription');

        // Response body shape for this endpoint was not shown in the fetched
        // docs beyond "cancel at end of billing period" — try the field
        // names HandyPay uses elsewhere (Stripe-style current_period_end /
        // cancel_at) before giving up.
        $endsAt = $data['current_period_end']
            ?? $data['cancel_at']
            ?? $data['ends_at']
            ?? $data['subscription']['current_period_end']
            ?? null;

        if ($endsAt === null) {
            throw new PaymentIntegrationException(sprintf(
                'HandyPay did not return a recognisable period-end field when cancelling subscription "%s". ' .
                'Check the real /subscriptions/%%s/cancel response shape against HandyPay\'s OpenAPI contract.',
                $subscriptionId,
            ));
        }

        return $this->toDateTime($endsAt, $subscriptionId);
    }

    /**
     * HandyPay's docs (as fetched) do not show a documented "resume a
     * scheduled cancellation" endpoint. Left as an explicit failure rather
     * than a guess — see changePlan()'s docblock for the same rationale.
     */
    #[Override]
    public function resume(Subscription $subscription): DateTimeImmutable
    {
        throw new PaymentIntegrationException(
            'HandyPay does not document a "resume subscription" endpoint. Confirm the real endpoint ' .
            'against HandyPay\'s OpenAPI contract before implementing this.',
        );
    }

    private function requireSubscriptionId(Subscription $subscription): string
    {
        $subscriptionId = $subscription->getSubscriptionId();

        if ($subscriptionId === null || $subscriptionId === '') {
            throw new RuntimeException(sprintf(
                'Subscription "%s" has no external HandyPay subscription id yet — it has not been ' .
                'confirmed by a HandyPay webhook.',
                $subscription->getId()->toBase58(),
            ));
        }

        return $subscriptionId;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response, string $action): array
    {
        $body = $response->toArray(false);

        if (($body['success'] ?? true) === false) {
            $message = $body['error']['message'] ?? 'unknown error';
            throw new PaymentIntegrationException(sprintf('HandyPay %s failed: %s', $action, $message));
        }

        $status = $response->getStatusCode();

        if ($status >= 400) {
            throw new PaymentIntegrationException(sprintf('HandyPay %s failed (HTTP %d).', $action, $status));
        }

        // Every successful response is documented as {success, data, request_id}.
        return $body['data'] ?? $body;
    }

    private function toDateTime(mixed $value, string $subscriptionId): DateTimeImmutable
    {
        if (is_string($value)) {
            return new DateTimeImmutable($value);
        }

        if (is_int($value)) {
            return (new DateTimeImmutable())->setTimestamp($value);
        }

        throw new PaymentIntegrationException(sprintf(
            'HandyPay returned an unrecognisable date value for subscription "%s".',
            $subscriptionId,
        ));
    }
}
