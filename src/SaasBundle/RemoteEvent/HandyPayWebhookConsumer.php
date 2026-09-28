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

namespace SolidInvoice\SaasBundle\RemoteEvent;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Override;
use Psr\Log\LoggerInterface;
use SolidWorx\Platform\SaasBundle\Entity\Subscription;
use SolidWorx\Platform\SaasBundle\Entity\WebhookEventLog;
use SolidWorx\Platform\SaasBundle\Enum\SubscriptionStatus;
use SolidWorx\Platform\SaasBundle\Enum\WebhookEventStatus;
use SolidWorx\Platform\SaasBundle\Repository\SubscriptionRepositoryInterface;
use SolidWorx\Platform\SaasBundle\Repository\WebhookEventLogRepository;
use SolidWorx\Platform\SaasBundle\Subscription\SubscriptionManager;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RemoteEvent\Attribute\AsRemoteEventConsumer;
use Symfony\Component\RemoteEvent\Consumer\ConsumerInterface;
use Symfony\Component\RemoteEvent\RemoteEvent;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Webhook\Exception\RejectWebhookException;
use function in_array;
use function is_array;
use function is_int;
use function is_string;

/**
 * Applies HandyPay webhook events to the local `saas_subscription` /
 * `saas_trial` state via {@see SubscriptionManager} — the same
 * provider-neutral state-transition API Lemon Squeezy would use, so this is
 * the "own listener following this same shape" that
 * SubscriptionPlanSyncListener's docblock anticipates for other providers.
 *
 * Handled event types (per HandyPay's docs, Stripe-compatible naming):
 *  - customer.subscription.created (status: trialing)               → start trial
 *  - customer.subscription.updated (status: active)                 → renew
 *  - customer.subscription.updated (status: past_due)                → past due
 *  - customer.subscription.updated (status: unpaid)                  → unpaid
 *  - payment_intent.payment_failed                                   → past due
 *  - customer.subscription.deleted                                   → cancelled
 *  - checkout.session.completed                                      → acknowledged
 *    only; never mutates saas_subscription.subscriptionId (see below)
 *
 * IMPORTANT — confirmed real-payload bug fixed here: `checkout.session.completed`'s
 * `data.id` is a HandyPay/Stripe *checkout session* id (`cs_test_...`), not a
 * subscription id. Only `customer.subscription.created` / `.updated` /
 * `.deleted` carry the real external subscription id (`sub_...`) in
 * `data.id`, confirmed against a real HandyPay delivery. Treating
 * `resource['id']` as authoritative for every event type — as this consumer
 * previously did — let a later-or-earlier `checkout.session.completed`
 * overwrite `saas_subscription.subscriptionId` with a `cs_...` value.
 * `SUBSCRIPTION_RESOURCE_EVENTS` below is the explicit allow-list that fixes
 * this; it is order-independent, since only the subscription-resource
 * events are ever allowed to write that column, regardless of whether
 * `checkout.session.completed` is delivered before or after them.
 *
 * IMPORTANT — confirmed real-payload bug #2 fixed here: repeated checkout
 * attempts for the SAME local subscription (double-clicks, back-button
 * resubmits, etc. — see SubscribeController) each created a brand new
 * HandyPay subscription, so the same local `saas_subscription` row received
 * `customer.subscription.*` events for MULTIPLE different `sub_...` ids over
 * time. This consumer used to treat every subscription-resource event's
 * `data.id` as authoritative unconditionally, so whichever event arrived
 * last — not necessarily the one the user actually completed checkout with
 * most recently — silently overwrote `subscriptionId`, and its status/dates
 * were applied even though it belonged to a stale, abandoned external
 * subscription. The authoritative-id guard below fixes this: once a
 * local row has a real `sub_...` attached, only events for THAT SAME
 * `sub_...` may attach a new id or change status/dates; events for any other
 * `sub_...` are logged and ignored rather than allowed to mutate state. A
 * local row with no `subscriptionId` yet may still attach the first real
 * `sub_...` it sees, per the requirement that a fresh checkout must be able
 * to complete normally.
 *
 * GAP (flagged, not guessed at): HandyPay's docs did not show a distinct
 * "trial ended, first charge succeeded" event name — only the generic
 * `customer.subscription.updated` / `payment_intent.succeeded` events. This
 * consumer infers "trial converted to paid" from the subscription object's
 * own `status` field turning from trialing to active, which is the
 * Stripe-compatible convention HandyPay's docs reference elsewhere on the
 * same page. Confirm this against a real test-mode webhook payload before
 * relying on it in production — see the integration report for how to
 * capture one safely.
 *
 * Idempotent by construction: every field this consumer sets (subscription
 * status, end date, external id) is an absolute value, not an increment, so
 * re-delivering the same webhook converges to the same state. It also skips
 * re-applying entirely once a WebhookEventLog row for the same HandyPay
 * event id has already reached PROCESSED, so a retried delivery is a no-op
 * rather than merely harmless.
 */
#[AsRemoteEventConsumer('handypay')]
final readonly class HandyPayWebhookConsumer implements ConsumerInterface
{
    /**
     * Event types whose `data.id` is a confirmed real HandyPay/Stripe
     * subscription id (`sub_...`) and is therefore safe to persist into
     * `saas_subscription.subscriptionId`. Deliberately does NOT include
     * `checkout.session.completed` — that event's `data.id` is a checkout
     * session id (`cs_...`), confirmed against a real delivery, and no
     * separate real subscription-id field on that event's payload has been
     * confirmed, so none is guessed at here.
     *
     * @var list<string>
     */
    private const array SUBSCRIPTION_RESOURCE_EVENTS = [
        'customer.subscription.created',
        'customer.subscription.updated',
        'customer.subscription.deleted',
    ];

    public function __construct(
        private SubscriptionManager $subscriptionManager,
        private SubscriptionRepositoryInterface $subscriptionRepository,
        private WebhookEventLogRepository $webhookEventLogRepository,
        private RequestStack $requestStack,
        private LoggerInterface $logger,
    ) {
    }

    #[Override]
    public function consume(RemoteEvent $event): void
    {
        $payload = $event->getPayload();
        $eventType = $event->getName();
        $eventId = $event->getId();

        if ($this->alreadyProcessed($eventId)) {
            $this->logger->info('Ignoring already-processed HandyPay webhook event.', ['event_id' => $eventId]);
            $this->tagLog($eventType, $eventId, null);

            return;
        }

        // ROOT CAUSE (confirmed against a real HandyPay delivery): the
        // resource lives directly under `data` — e.g.
        // {"type":"customer.subscription.created","data":{"id":"sub_...",
        // "object":"subscription","status":"trialing","metadata":{...}}}.
        // `data.object` is a flat type-discriminator STRING on that same
        // resource (Stripe's own resource objects carry the same kind of
        // "object":"subscription" field), NOT a nested
        // {data: {object: {...}}} wrapper. The previous
        // `$payload['data']['object'] ?? $payload['data']` fallback picked
        // up that discriminator string first — `??` only falls through on
        // null, and the string was never null — so `$resource` ended up
        // being the STRING "subscription" instead of the resource array,
        // and the is_array() guard below rejected every real delivery with
        // "data was not an object". `data` itself is always the resource;
        // Symfony's Request::getPayload() decodes JSON into associative
        // arrays (json_decode(..., true)), never stdClass, and
        // HandyPayRequestParser already relies on that, so array access is
        // correct and consistent throughout this consumer.
        $resource = $payload['data'] ?? [];

        if (! is_array($resource)) {
            throw new RejectWebhookException(message: 'HandyPay webhook payload "data" was not an object.');
        }

        $subscriptionId = $this->resolveLocalSubscriptionId($resource, $payload);

        if (! $subscriptionId instanceof Ulid) {
            // No metadata.subscription_id — this can legitimately happen for
            // event types AllDigital Invoice doesn't originate a checkout
            // for (e.g. a refund/dispute event). Log and accept the webhook
            // rather than rejecting it, so HandyPay doesn't retry forever.
            $this->logger->info('HandyPay webhook has no subscription_id metadata; ignoring.', ['event_type' => $eventType]);
            $this->tagLog($eventType, $eventId, null);

            return;
        }

        $subscription = $this->subscriptionRepository->findOneBy(['id' => $subscriptionId]);

        if (! $subscription instanceof Subscription) {
            $this->logger->warning('HandyPay webhook references an unknown local subscription.', [
                'event_type' => $eventType,
                'subscription_id' => $subscriptionId->toBase58(),
            ]);
            $this->tagLog($eventType, $eventId, $subscriptionId);

            return;
        }

        // ROOT CAUSE (confirmed against a real HandyPay delivery): this used
        // to read `$resource['id']` unconditionally for every event type.
        // For `checkout.session.completed`, `data.id` is a checkout SESSION
        // id (`cs_test_...`), not a subscription id — persisting it here
        // corrupted `saas_subscription.subscriptionId`. Only the
        // subscription-resource events in SUBSCRIPTION_RESOURCE_EVENTS carry
        // a confirmed real `sub_...` id in `data.id`, so only those are
        // allowed to write this column.
        $isSubscriptionResourceEvent = in_array($eventType, self::SUBSCRIPTION_RESOURCE_EVENTS, true);
        $incomingExternalId = $isSubscriptionResourceEvent ? ($resource['id'] ?? null) : null;
        $incomingExternalId = is_string($incomingExternalId) && $incomingExternalId !== '' ? $incomingExternalId : null;

        $currentExternalId = $subscription->getSubscriptionId();
        $currentExternalId = $currentExternalId !== null && $currentExternalId !== '' ? $currentExternalId : null;

        if ($isSubscriptionResourceEvent) {
            if ($incomingExternalId === null) {
                // A subscription-resource event with no usable resource id —
                // nothing to attach or compare against. Log and accept so
                // HandyPay doesn't retry forever, but do not fall through to
                // the status-mutating match below.
                $this->logger->info('HandyPay subscription event had no resource id; ignoring.', [
                    'event_type' => $eventType,
                ]);
                $this->tagLog($eventType, $eventId, $subscriptionId, null);

                return;
            }

            // ROOT CAUSE (confirmed against real HandyPay deliveries):
            // repeated checkouts for the SAME local subscription row (see
            // SubscribeController's new duplicate-checkout guard) previously
            // created MULTIPLE distinct HandyPay subscriptions over time.
            // This consumer used to treat every incoming `data.id` as
            // authoritative unconditionally, so whichever subscription's
            // event happened to arrive LAST — not necessarily the one the
            // user actually completed most recently — silently overwrote
            // `subscriptionId`, and its status/dates were applied even
            // though it belonged to a stale, abandoned external
            // subscription. Once a local row already has a real `sub_...`
            // attached, an event for a DIFFERENT `sub_...` must never
            // silently replace it or mutate status/dates; only a local row
            // with no external subscription yet may attach the first real
            // `sub_...` it sees.
            //
            // EXCEPTION (confirmed live, second bug fixed here): a genuine
            // resubscribe — the user cancels, then intentionally starts a
            // fresh checkout (see SubscribeController, which now allows
            // that once local state is CANCELLED) — produces a brand new
            // `sub_...` for the SAME local row while the old, already-ended
            // one is still attached (it is never cleared on cancellation).
            // Without this exception, the guard above would reject that new
            // subscription's events as "non-authoritative" forever, since
            // `currentExternalId` is never null after a first subscription.
            // A local row that is CANCELLED has, by definition, no live
            // external subscription to protect — so a DIFFERENT incoming id
            // there is adopted as the new authoritative one instead of
            // rejected.
            $isResubscribeAfterCancellation = $currentExternalId !== null
                && $currentExternalId !== $incomingExternalId
                && $subscription->getStatus() === SubscriptionStatus::CANCELLED;

            if ($currentExternalId !== null && $currentExternalId !== $incomingExternalId && ! $isResubscribeAfterCancellation) {
                $this->logger->info('Ignoring HandyPay webhook for a non-authoritative external subscription.', [
                    'event_type' => $eventType,
                    'local_subscription_id' => $subscriptionId->toBase58(),
                    'attached_external_subscription_id' => $currentExternalId,
                    'event_external_subscription_id' => $incomingExternalId,
                ]);
                $this->tagLog($eventType, $eventId, $subscriptionId, $incomingExternalId);

                return;
            }

            if ($currentExternalId === null || $isResubscribeAfterCancellation) {
                if ($isResubscribeAfterCancellation) {
                    $this->logger->info('Adopting a new HandyPay subscription id after a confirmed resubscribe.', [
                        'event_type' => $eventType,
                        'local_subscription_id' => $subscriptionId->toBase58(),
                        'previous_external_subscription_id' => $currentExternalId,
                        'new_external_subscription_id' => $incomingExternalId,
                    ]);
                }

                $subscription->setSubscriptionId($incomingExternalId);
            }
        } else {
            $isResubscribeAfterCancellation = false;
        }

        $status = $resource['status'] ?? null;

        // ROOT CAUSE (confirmed live) of "cancellation disappears after
        // refresh": HandyPay's cancelAtPeriodEnd() (used by
        // CancelSubscriptionAction) is documented as "cancel at end of
        // billing period" — for a subscription still inside its trial, that
        // means the subscription object HandyPay reports back keeps
        // `status: "trialing"` (or "active", once the trial has converted)
        // right up until the period actually ends; only a
        // cancel_at_period_end / cancel_at — style field (Stripe-compatible
        // naming, which HandyPay's own docs describe using — see this
        // class's docblock) marks it as scheduled to end. This consumer
        // used to match on `status` alone, so a `customer.subscription.updated`
        // delivery confirming the cancellation request (still "trialing",
        // now with cancellation scheduled) hit the `$status === 'trialing'`
        // arm below and called startTrial() — silently reverting the
        // CANCELLED state CancelSubscriptionAction had just persisted back
        // to TRIAL. `isScheduledForCancellation` below is checked BEFORE
        // the plain status arms so a cancellation-scheduled update is never
        // misread as a fresh/renewed subscription.
        $isScheduledForCancellation = $this->resourceIndicatesScheduledCancellation($resource);

        // Belt-and-suspenders: once local state is already CANCELLED for
        // THIS SAME external subscription (not a resubscribe — see
        // $isResubscribeAfterCancellation above), no further
        // subscription-resource status update for it may change local
        // state at all. This covers out-of-order delivery (an earlier,
        // pre-cancellation "trialing" update arriving after the
        // cancellation one) even if a future HandyPay payload uses a
        // cancellation-signal field name this consumer doesn't yet check.
        $isStaleUpdateForAlreadyCancelledSubscription = $isSubscriptionResourceEvent
            && ! $isResubscribeAfterCancellation
            && $subscription->getStatus() === SubscriptionStatus::CANCELLED;

        match (true) {
            $eventType === 'checkout.session.completed' => $this->logger->info(
                'HandyPay checkout.session.completed acknowledged; subscription state is ' .
                'derived only from customer.subscription.* events, since this event\'s ' .
                '"id" is a checkout session id (cs_...), not a subscription id.',
                ['event_type' => $eventType, 'checkout_session_id' => $resource['id'] ?? null],
            ),
            $eventType === 'customer.subscription.deleted' => $this->subscriptionManager->cancelSubscription(
                $subscription,
                CarbonImmutable::now('UTC'),
            ),
            $eventType === 'payment_intent.payment_failed' => $this->subscriptionManager->markAsPastDue($subscription),
            $isStaleUpdateForAlreadyCancelledSubscription => $this->logger->info(
                'Ignoring HandyPay status update for an already-cancelled local subscription (same external id).',
                ['event_type' => $eventType, 'status' => $status, 'local_subscription_id' => $subscriptionId->toBase58()],
            ),
            $isScheduledForCancellation => $this->subscriptionManager->cancelSubscription(
                $subscription,
                $this->resolveCancellationEffectiveDate($resource) ?? $subscription->getEndDate(),
            ),
            $status === 'trialing' => $this->subscriptionManager->startTrial(
                $subscription,
                $this->resolveTrialEnd($resource),
            ),
            $status === 'active' => $this->subscriptionManager->renewSubscription(
                $subscription,
                $this->resolvePeriodEnd($resource) ?? CarbonImmutable::now('UTC')->addMonth(),
            ),
            $status === 'past_due' => $this->subscriptionManager->markAsPastDue($subscription),
            $status === 'unpaid' => $this->subscriptionManager->markAsUnpaid($subscription),
            $status === 'canceled', $status === 'cancelled' => $this->subscriptionManager->cancelSubscription(
                $subscription,
                CarbonImmutable::now('UTC'),
            ),
            default => $this->logger->info('Unhandled HandyPay webhook event/status combination.', [
                'event_type' => $eventType,
                'status' => $status,
            ]),
        };

        $this->tagLog($eventType, $eventId, $subscriptionId, $incomingExternalId);
    }

    private function alreadyProcessed(string $eventId): bool
    {
        $existing = $this->webhookEventLogRepository->findOneBy([
            'gateway' => 'handypay',
            'gatewayEventId' => $eventId,
            'status' => WebhookEventStatus::PROCESSED,
        ]);

        return $existing instanceof WebhookEventLog;
    }

    /**
     * @param array<string, mixed> $resource
     * @param array<string, mixed> $payload
     */
    private function resolveLocalSubscriptionId(array $resource, array $payload): ?Ulid
    {
        $raw = $resource['metadata']['subscription_id']
            ?? $payload['metadata']['subscription_id']
            ?? null;

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            return Ulid::fromString($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Detects that a subscription resource has a cancellation scheduled
     * for the end of its current period, even though its `status` field
     * itself still reads "trialing" or "active" until that period actually
     * ends. HandyPay's docs (as fetched) describe its webhook payloads as
     * following Stripe-compatible naming (see this class's own docblock,
     * and HandyPay::cancelAtPeriodEnd()'s docblock for the same "docs
     * didn't show the exact field" gap on the request side) — Stripe's own
     * subscription resource signals this via `cancel_at_period_end: true`
     * plus a `cancel_at` timestamp. The exact field HandyPay uses was not
     * shown in the fetched docs beyond "cancel at end of billing period",
     * so every Stripe-compatible spelling is checked defensively rather
     * than guessing a single one.
     *
     * GAP (flagged, not guessed at): confirm the real field name against a
     * captured HandyPay `customer.subscription.updated` payload sent after
     * calling `POST /subscriptions/{id}/cancel`, then narrow this once
     * confirmed.
     *
     * @param array<string, mixed> $resource
     */
    private function resourceIndicatesScheduledCancellation(array $resource): bool
    {
        if (($resource['cancel_at_period_end'] ?? null) === true) {
            return true;
        }

        foreach (['cancel_at', 'canceled_at', 'cancelled_at'] as $field) {
            $value = $resource[$field] ?? null;

            if ($value !== null && $value !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $resource
     */
    private function resolveCancellationEffectiveDate(array $resource): ?DateTimeImmutable
    {
        $value = $resource['cancel_at']
            ?? $resource['current_period_end']
            ?? null;

        return is_int($value) ? (new DateTimeImmutable())->setTimestamp($value) : null;
    }

    /**
     * @param array<string, mixed> $resource
     */
    private function resolveTrialEnd(array $resource): ?DateTimeImmutable
    {
        $trialEnd = $resource['trial_end'] ?? null;

        return is_int($trialEnd) ? (new DateTimeImmutable())->setTimestamp($trialEnd) : null;
    }

    /**
     * @param array<string, mixed> $resource
     */
    private function resolvePeriodEnd(array $resource): ?DateTimeImmutable
    {
        $periodEnd = $resource['current_period_end'] ?? null;

        return is_int($periodEnd) ? (new DateTimeImmutable())->setTimestamp($periodEnd) : null;
    }

    private function tagLog(string $eventType, string $eventId, ?Ulid $subscriptionId, ?string $externalSubscriptionId = null): void
    {
        $log = $this->requestStack->getCurrentRequest()?->attributes->get('_webhook_event_log');

        if (! $log instanceof WebhookEventLog) {
            return;
        }

        $log->setEventType($eventType);
        $log->setGatewayEventId($eventId);

        if ($subscriptionId instanceof Ulid) {
            $log->setExternalSubscriptionId($externalSubscriptionId ?? $subscriptionId->toBase58());
        }
    }
}
