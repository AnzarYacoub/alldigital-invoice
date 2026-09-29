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

namespace SolidInvoice\SaasBundle\Tests\RemoteEvent;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionProperty;
use SolidInvoice\SaasBundle\RemoteEvent\HandyPayWebhookConsumer;
use SolidWorx\Platform\SaasBundle\Entity\Plan;
use SolidWorx\Platform\SaasBundle\Entity\Subscription;
use SolidWorx\Platform\SaasBundle\Enum\SubscriptionStatus;
use SolidWorx\Platform\SaasBundle\Integration\PaymentIntegrationInterface;
use SolidWorx\Platform\SaasBundle\Repository\PlanRepositoryInterface;
use SolidWorx\Platform\SaasBundle\Repository\SubscriptionRepositoryInterface;
use SolidWorx\Platform\SaasBundle\Repository\WebhookEventLogRepository;
use SolidWorx\Platform\SaasBundle\Subscription\SubscriptionManager;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RemoteEvent\RemoteEvent;
use Symfony\Component\Uid\Ulid;

/**
 * Regression tests for three confirmed real-payload HandyPayWebhookConsumer
 * bugs, all found via live test-mode webhook deliveries:
 *
 *  1. "data was not an object" — the consumer used to read
 *     `$payload['data']['object'] ?? $payload['data']`, which picked up
 *     HandyPay's flat type-discriminator STRING at `data.object` (e.g.
 *     "subscription") instead of the resource itself, because `??` only
 *     falls through on null and that string was never null.
 *  2. `checkout.session.completed` corrupting `subscriptionId` — the
 *     consumer used to read `$resource['id']` as the external subscription
 *     id for EVERY event type. For `checkout.session.completed`, `data.id`
 *     is a checkout SESSION id (`cs_test_...`), not a subscription id, so
 *     this overwrote `saas_subscription.subscriptionId` with a `cs_...`
 *     value — regardless of whether the real `customer.subscription.*`
 *     event (carrying the real `sub_...` id) had already been processed or
 *     not yet arrived.
 *  3. Non-authoritative subscription events mutating an already-attached
 *     local row — repeated checkouts for the SAME local subscription (see
 *     SubscribeController's duplicate-checkout guard) previously created
 *     MULTIPLE distinct HandyPay subscriptions, and this consumer treated
 *     every incoming `data.id` as authoritative unconditionally. Whichever
 *     subscription's event happened to arrive LAST silently overwrote
 *     `subscriptionId` and had its status/dates applied, even when it
 *     belonged to a stale, abandoned external subscription. Fixed by the
 *     consumer's allow-only-the-attached-id guard in consume().
 *
 * All payloads below use the exact shape confirmed against real HandyPay
 * webhook deliveries.
 */
#[CoversClass(HandyPayWebhookConsumer::class)]
final class HandyPayWebhookConsumerTest extends TestCase
{
    public function testSubscriptionCreatedTrialingStartsLocalTrial(): void
    {
        $subscriptionId = new Ulid();
        $subscription = $this->makeSubscription($subscriptionId);
        $consumer = $this->makeConsumer($subscription, $subscriptionId);

        $trialEndTimestamp = (new \DateTimeImmutable('+14 days'))->getTimestamp();

        // Exact shape of a real HandyPay `customer.subscription.created`
        // delivery: the resource is directly under `data` (flat), and
        // `data.object` is a type-discriminator string on that SAME
        // resource, not a nested wrapper.
        $payload = [
            'id' => 'evt_test123',
            'type' => 'customer.subscription.created',
            'data' => [
                'id' => 'sub_test456',
                'object' => 'subscription',
                'status' => 'trialing',
                'trial_end' => $trialEndTimestamp,
                'metadata' => [
                    'subscription_id' => $subscriptionId->toBase58(),
                    'handypay_merchant_id' => 'merchant_test',
                    'handypay_primary_price_id' => 'price_test',
                ],
            ],
        ];

        $event = new RemoteEvent('customer.subscription.created', 'evt_test123', $payload);

        $consumer->consume($event);

        self::assertSame(SubscriptionStatus::TRIAL, $subscription->getStatus());
        self::assertSame($trialEndTimestamp, $subscription->getEndDate()->getTimestamp());
        self::assertSame('sub_test456', $subscription->getSubscriptionId());
    }

    /**
     * checkout.session.completed arriving AFTER customer.subscription.created
     * must not overwrite the already-correct sub_... id with the checkout
     * session's cs_... id.
     */
    public function testCheckoutSessionCompletedDoesNotOverwriteExistingSubscriptionId(): void
    {
        $subscriptionId = new Ulid();
        $subscription = $this->makeSubscription($subscriptionId);
        // Simulate customer.subscription.created having already been
        // processed for this subscription.
        $subscription->setSubscriptionId('sub_real789');

        $consumer = $this->makeConsumer($subscription, $subscriptionId);

        // Exact shape of a real HandyPay `checkout.session.completed`
        // delivery: `data.id` is a checkout SESSION id, and `status` is
        // "complete" — not one of the subscription lifecycle statuses.
        $payload = [
            'id' => 'evt_checkout_test',
            'type' => 'checkout.session.completed',
            'data' => [
                'id' => 'cs_test_abc123',
                'object' => 'checkout.session',
                'status' => 'complete',
                'metadata' => [
                    'subscription_id' => $subscriptionId->toBase58(),
                ],
            ],
        ];

        $event = new RemoteEvent('checkout.session.completed', 'evt_checkout_test', $payload);

        $consumer->consume($event);

        self::assertSame(
            'sub_real789',
            $subscription->getSubscriptionId(),
            'checkout.session.completed must never overwrite subscriptionId with a checkout session id.',
        );
    }

    /**
     * Same corruption, opposite delivery order: checkout.session.completed
     * arrives BEFORE customer.subscription.created. Final local state must
     * still end up with the real sub_... id — proving the fix is
     * order-independent, not just "the last event wins".
     */
    public function testWebhookOrderingDoesNotAffectFinalSubscriptionId(): void
    {
        $subscriptionId = new Ulid();
        $subscription = $this->makeSubscription($subscriptionId);
        $consumer = $this->makeConsumer($subscription, $subscriptionId);

        $checkoutPayload = [
            'id' => 'evt_checkout_first',
            'type' => 'checkout.session.completed',
            'data' => [
                'id' => 'cs_test_xyz999',
                'object' => 'checkout.session',
                'status' => 'complete',
                'metadata' => [
                    'subscription_id' => $subscriptionId->toBase58(),
                ],
            ],
        ];

        $consumer->consume(new RemoteEvent('checkout.session.completed', 'evt_checkout_first', $checkoutPayload));

        self::assertNull(
            $subscription->getSubscriptionId(),
            'checkout.session.completed arriving first must not set subscriptionId to a cs_... id.',
        );

        $trialEndTimestamp = (new \DateTimeImmutable('+14 days'))->getTimestamp();
        $subscriptionCreatedPayload = [
            'id' => 'evt_sub_created_second',
            'type' => 'customer.subscription.created',
            'data' => [
                'id' => 'sub_test456',
                'object' => 'subscription',
                'status' => 'trialing',
                'trial_end' => $trialEndTimestamp,
                'metadata' => [
                    'subscription_id' => $subscriptionId->toBase58(),
                ],
            ],
        ];

        $consumer->consume(new RemoteEvent('customer.subscription.created', 'evt_sub_created_second', $subscriptionCreatedPayload));

        self::assertSame('sub_test456', $subscription->getSubscriptionId());
        self::assertSame(SubscriptionStatus::TRIAL, $subscription->getStatus());
    }

    /**
     * Requirement 1: local row has sub_A attached, an event for a
     * DIFFERENT sub_B arrives → subscriptionId must not be overwritten.
     */
    public function testEventForDifferentSubscriptionDoesNotOverwriteAttachedId(): void
    {
        $subscriptionId = new Ulid();
        $subscription = $this->makeSubscription($subscriptionId);
        $subscription->setSubscriptionId('sub_A');
        $subscription->setStatus(SubscriptionStatus::TRIAL);

        $consumer = $this->makeConsumer($subscription, $subscriptionId);

        $payload = [
            'id' => 'evt_sub_b_updated',
            'type' => 'customer.subscription.updated',
            'data' => [
                'id' => 'sub_B',
                'object' => 'subscription',
                'status' => 'active',
                'current_period_end' => (new \DateTimeImmutable('+1 month'))->getTimestamp(),
                'metadata' => [
                    'subscription_id' => $subscriptionId->toBase58(),
                ],
            ],
        ];

        $consumer->consume(new RemoteEvent('customer.subscription.updated', 'evt_sub_b_updated', $payload));

        self::assertSame(
            'sub_A',
            $subscription->getSubscriptionId(),
            'An event for a different external subscription must never overwrite the attached sub_...  id.',
        );
    }

    /**
     * Requirement 2: local row has no external subscription yet, a valid
     * event for sub_A arrives → sub_A is attached.
     */
    public function testEmptyLocalSubscriptionAttachesFirstExternalId(): void
    {
        $subscriptionId = new Ulid();
        $subscription = $this->makeSubscription($subscriptionId);
        self::assertNull($subscription->getSubscriptionId());

        $consumer = $this->makeConsumer($subscription, $subscriptionId);

        $payload = [
            'id' => 'evt_sub_a_updated',
            'type' => 'customer.subscription.updated',
            'data' => [
                'id' => 'sub_A',
                'object' => 'subscription',
                'status' => 'active',
                'current_period_end' => (new \DateTimeImmutable('+1 month'))->getTimestamp(),
                'metadata' => [
                    'subscription_id' => $subscriptionId->toBase58(),
                ],
            ],
        ];

        $consumer->consume(new RemoteEvent('customer.subscription.updated', 'evt_sub_a_updated', $payload));

        self::assertSame('sub_A', $subscription->getSubscriptionId());
        self::assertSame(SubscriptionStatus::ACTIVE, $subscription->getStatus());
    }

    /**
     * Requirement 3: an update/cancellation event for the currently-attached
     * sub_A must still update local status normally.
     */
    public function testUpdateForAttachedSubscriptionUpdatesLocalStatus(): void
    {
        $subscriptionId = new Ulid();
        $subscription = $this->makeSubscription($subscriptionId);
        $subscription->setSubscriptionId('sub_A');
        $subscription->setStatus(SubscriptionStatus::TRIAL);

        $consumer = $this->makeConsumer($subscription, $subscriptionId);

        $periodEndTimestamp = (new \DateTimeImmutable('+1 month'))->getTimestamp();
        $payload = [
            'id' => 'evt_sub_a_activated',
            'type' => 'customer.subscription.updated',
            'data' => [
                'id' => 'sub_A',
                'object' => 'subscription',
                'status' => 'active',
                'current_period_end' => $periodEndTimestamp,
                'metadata' => [
                    'subscription_id' => $subscriptionId->toBase58(),
                ],
            ],
        ];

        $consumer->consume(new RemoteEvent('customer.subscription.updated', 'evt_sub_a_activated', $payload));

        self::assertSame(SubscriptionStatus::ACTIVE, $subscription->getStatus());
        self::assertSame($periodEndTimestamp, $subscription->getEndDate()->getTimestamp());
        self::assertSame('sub_A', $subscription->getSubscriptionId());
    }

    /**
     * Requirement 4: a cancellation (or update) event for sub_B must not
     * change status/dates on a local row that is bound to sub_A.
     */
    public function testCancellationForNonAuthoritativeSubscriptionDoesNotAffectLocalRow(): void
    {
        $subscriptionId = new Ulid();
        $subscription = $this->makeSubscription($subscriptionId);
        $subscription->setSubscriptionId('sub_A');
        $subscription->setStatus(SubscriptionStatus::TRIAL);
        $originalEndDate = new \DateTimeImmutable('+10 days');
        $subscription->setEndDate($originalEndDate);

        $consumer = $this->makeConsumer($subscription, $subscriptionId);

        $payload = [
            'id' => 'evt_sub_b_deleted',
            'type' => 'customer.subscription.deleted',
            'data' => [
                'id' => 'sub_B',
                'object' => 'subscription',
                'status' => 'canceled',
                'metadata' => [
                    'subscription_id' => $subscriptionId->toBase58(),
                ],
            ],
        ];

        $consumer->consume(new RemoteEvent('customer.subscription.deleted', 'evt_sub_b_deleted', $payload));

        self::assertSame(
            SubscriptionStatus::TRIAL,
            $subscription->getStatus(),
            'A cancellation for a non-authoritative external subscription must not cancel the local row.',
        );
        self::assertSame('sub_A', $subscription->getSubscriptionId());
        self::assertSame($originalEndDate->getTimestamp(), $subscription->getEndDate()->getTimestamp());
    }

    /**
     * ROOT CAUSE regression (Bug: "cancellation disappears after refresh"):
     * HandyPay's cancelAtPeriodEnd() cancels "at end of billing period" —
     * for a subscription still trialing, the subscription object it
     * confirms back keeps `status: "trialing"` right up until the trial
     * actually ends; only `cancel_at_period_end: true` (Stripe-compatible
     * naming) signals the cancellation. A `customer.subscription.updated`
     * delivery with that shape must cancel locally, NOT call startTrial()
     * and revert the CANCELLED state CancelSubscriptionAction just set.
     */
    public function testTrialingWithCancelAtPeriodEndDoesNotRevertLocalStateToTrial(): void
    {
        $subscriptionId = new Ulid();
        $subscription = $this->makeSubscription($subscriptionId);
        $subscription->setSubscriptionId('sub_A');
        // Simulate CancelSubscriptionAction having already run.
        $subscription->setStatus(SubscriptionStatus::CANCELLED);
        $accessEndDate = new \DateTimeImmutable('+12 days');
        $subscription->setEndDate($accessEndDate);

        $consumer = $this->makeConsumer($subscription, $subscriptionId);

        $payload = [
            'id' => 'evt_cancel_confirmed',
            'type' => 'customer.subscription.updated',
            'data' => [
                'id' => 'sub_A',
                'object' => 'subscription',
                'status' => 'trialing',
                'cancel_at_period_end' => true,
                'cancel_at' => $accessEndDate->getTimestamp(),
                'metadata' => [
                    'subscription_id' => $subscriptionId->toBase58(),
                ],
            ],
        ];

        $consumer->consume(new RemoteEvent('customer.subscription.updated', 'evt_cancel_confirmed', $payload));

        self::assertSame(
            SubscriptionStatus::CANCELLED,
            $subscription->getStatus(),
            'A "trialing" update carrying cancel_at_period_end=true must not revert local state to TRIAL.',
        );
        self::assertSame('sub_A', $subscription->getSubscriptionId());
    }

    /**
     * Same bug, proven from a fresh (not-yet-cancelled) subscription this
     * time: the very webhook that confirms a cancellation request must
     * cancel locally on its own, even though its `status` field alone would
     * otherwise read as "still trialing".
     */
    public function testCancelAtPeriodEndSignalCancelsLocallyEvenWhileStillTrialing(): void
    {
        $subscriptionId = new Ulid();
        $subscription = $this->makeSubscription($subscriptionId);
        $subscription->setSubscriptionId('sub_A');
        $subscription->setStatus(SubscriptionStatus::TRIAL);

        $consumer = $this->makeConsumer($subscription, $subscriptionId);

        $accessEndTimestamp = (new \DateTimeImmutable('+9 days'))->getTimestamp();
        $payload = [
            'id' => 'evt_cancel_requested',
            'type' => 'customer.subscription.updated',
            'data' => [
                'id' => 'sub_A',
                'object' => 'subscription',
                'status' => 'trialing',
                'cancel_at_period_end' => true,
                'cancel_at' => $accessEndTimestamp,
                'metadata' => [
                    'subscription_id' => $subscriptionId->toBase58(),
                ],
            ],
        ];

        $consumer->consume(new RemoteEvent('customer.subscription.updated', 'evt_cancel_requested', $payload));

        self::assertSame(SubscriptionStatus::CANCELLED, $subscription->getStatus());
        self::assertSame($accessEndTimestamp, $subscription->getEndDate()->getTimestamp());
    }

    /**
     * Requirement 7: a new authoritative sub_... may replace the old one
     * ONLY when local state is CANCELLED — i.e. a deliberate resubscribe,
     * not a stray/duplicate event for some other subscription. This is the
     * exact scenario SubscribeController's relaxed duplicate-checkout guard
     * now allows: cancel, then intentionally start a fresh checkout.
     */
    public function testResubscribeAfterCancellationAdoptsTheNewExternalId(): void
    {
        $subscriptionId = new Ulid();
        $subscription = $this->makeSubscription($subscriptionId);
        $subscription->setSubscriptionId('sub_OLD_cancelled');
        $subscription->setStatus(SubscriptionStatus::CANCELLED);

        $consumer = $this->makeConsumer($subscription, $subscriptionId);

        $trialEndTimestamp = (new \DateTimeImmutable('+14 days'))->getTimestamp();
        $payload = [
            'id' => 'evt_resubscribe_created',
            'type' => 'customer.subscription.created',
            'data' => [
                'id' => 'sub_NEW_resubscribed',
                'object' => 'subscription',
                'status' => 'trialing',
                'trial_end' => $trialEndTimestamp,
                'metadata' => [
                    'subscription_id' => $subscriptionId->toBase58(),
                ],
            ],
        ];

        $consumer->consume(new RemoteEvent('customer.subscription.created', 'evt_resubscribe_created', $payload));

        self::assertSame(
            'sub_NEW_resubscribed',
            $subscription->getSubscriptionId(),
            'A new subscription created for a CANCELLED local row must be adopted as authoritative.',
        );
        self::assertSame(SubscriptionStatus::TRIAL, $subscription->getStatus());
    }

    /**
     * Same requirement, negative case: while the local row is still
     * TRIAL/ACTIVE (not cancelled), an event for a DIFFERENT sub_... must
     * continue to be rejected exactly as before — the resubscribe exception
     * must not weaken the original non-authoritative-event guard.
     */
    public function testDifferentSubscriptionIdIsStillRejectedWhenNotCancelled(): void
    {
        $subscriptionId = new Ulid();
        $subscription = $this->makeSubscription($subscriptionId);
        $subscription->setSubscriptionId('sub_A');
        $subscription->setStatus(SubscriptionStatus::TRIAL);

        $consumer = $this->makeConsumer($subscription, $subscriptionId);

        $payload = [
            'id' => 'evt_sub_c_updated',
            'type' => 'customer.subscription.updated',
            'data' => [
                'id' => 'sub_C',
                'object' => 'subscription',
                'status' => 'active',
                'current_period_end' => (new \DateTimeImmutable('+1 month'))->getTimestamp(),
                'metadata' => [
                    'subscription_id' => $subscriptionId->toBase58(),
                ],
            ],
        ];

        $consumer->consume(new RemoteEvent('customer.subscription.updated', 'evt_sub_c_updated', $payload));

        self::assertSame('sub_A', $subscription->getSubscriptionId());
        self::assertSame(SubscriptionStatus::TRIAL, $subscription->getStatus());
    }

    private function makeSubscription(Ulid $subscriptionId): Subscription
    {
        $subscription = new Subscription();
        new ReflectionProperty(Subscription::class, 'id')->setValue($subscription, $subscriptionId);
        $subscription->setPlan(
            new Plan()
                ->setName('Starter')
                ->setPlanId('starter-monthly')
                ->setPrice(1200)
                ->setActive(true),
        );

        return $subscription;
    }

    private function makeConsumer(Subscription $subscription, Ulid $subscriptionId): HandyPayWebhookConsumer
    {
        $subscriptionRepository = $this->createMock(SubscriptionRepositoryInterface::class);
        // willReturnCallback() instead of ->with(...)->willReturn(...): PHPStan
        // (with this PHPUnit version's split Stub/InvocationStubber builder
        // interfaces) cannot resolve with() chained directly off method()
        // here. A callback keeps the exact same behaviour - $subscription is
        // returned only for this specific criteria, null otherwise - without
        // relying on that chain.
        //
        // Comparing by toBase58(), not ===: HandyPayWebhookConsumer::
        // resolveLocalSubscriptionId() rebuilds the id via
        // Ulid::fromString($raw), which is a different Ulid object instance
        // than $subscriptionId even when it's the same underlying value -
        // strict array comparison (=== on the whole criteria array) compares
        // objects by identity, so that always failed and made every test
        // here go through the "unknown local subscription" no-op path
        // instead of ever exercising the real update logic.
        $subscriptionRepository
            ->method('findOneBy')
            ->willReturnCallback(
                static function (array $criteria) use ($subscriptionId, $subscription): ?Subscription {
                    $id = $criteria['id'] ?? null;

                    return $id instanceof Ulid && $id->toBase58() === $subscriptionId->toBase58()
                        ? $subscription
                        : null;
                },
            );
        $subscriptionRepository->method('save');

        $subscriptionManager = new SubscriptionManager(
            $subscriptionRepository,
            $this->createStub(PlanRepositoryInterface::class),
            $this->createStub(PaymentIntegrationInterface::class),
        );

        $webhookEventLogRepository = $this->createMock(WebhookEventLogRepository::class);
        $webhookEventLogRepository->method('findOneBy')->willReturn(null);

        return new HandyPayWebhookConsumer(
            $subscriptionManager,
            $subscriptionRepository,
            $webhookEventLogRepository,
            new RequestStack(),
            new NullLogger(),
        );
    }
}
