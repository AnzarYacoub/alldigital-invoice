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

namespace SolidInvoice\SaasBundle\Action;

use RuntimeException;
use SolidInvoice\CoreBundle\Company\CompanySelector;
use SolidInvoice\CoreBundle\Repository\CompanyRepository;
use SolidWorx\Platform\SaasBundle\Entity\Subscription;
use SolidWorx\Platform\SaasBundle\Enum\SubscriptionStatus;
use SolidWorx\Platform\SaasBundle\Exception\PaymentIntegrationException;
use SolidWorx\Platform\SaasBundle\Integration\PaymentIntegrationInterface;
use SolidWorx\Platform\SaasBundle\Subscription\SubscriptionManager;
use SolidWorx\Platform\SaasBundle\Subscription\SubscriptionProviderInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Ulid;
use function sprintf;

/**
 * Lets a subscriber cancel their own subscription — including during the
 * 14-day HandyPay trial, which prevents the first charge from ever
 * happening (HandyPay's `POST /subscriptions/{id}/cancel` is documented as
 * "cancel at end of billing period", which for a trialing subscription IS
 * the trial end, so no charge occurs).
 *
 * HandyPay does not document a hosted self-service customer portal (unlike
 * Lemon Squeezy), so this in-app action is the only cancellation path.
 *
 * The local `saas_subscription` row is updated only after HandyPay confirms
 * the cancellation succeeded (cancelAtPeriodEnd() returning normally) — not
 * merely because the user clicked the button — matching the same
 * "webhook/confirmed-response is the authority, not the UI action" pattern
 * used elsewhere in this bundle. A follow-up `customer.subscription.deleted`
 * webhook will also reach HandyPayWebhookConsumer and is a no-op there
 * (same end state, idempotent).
 */
final class CancelSubscriptionAction extends AbstractController
{
    public function __construct(
        private readonly SubscriptionManager $subscriptionManager,
        private readonly SubscriptionProviderInterface $subscriptionProvider,
        private readonly PaymentIntegrationInterface $paymentIntegration,
        private readonly CompanyRepository $companyRepository,
        private readonly CompanySelector $companySelector,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if (! $this->isCsrfTokenValid('cancel_subscription', (string) $request->request->get('_token', ''))) {
            $this->addFlash('error', 'Invalid security token, please try again.');

            return $this->redirectToRoute('billing_index');
        }

        $subscription = $this->getSubscription();

        if (! $subscription instanceof Subscription) {
            $this->addFlash('error', 'No subscription found');

            return $this->redirectToRoute('_dashboard');
        }

        if ($subscription->getPlan()->isFree() || $subscription->getStatus() === SubscriptionStatus::CANCELLED) {
            return $this->redirectToRoute('billing_index');
        }

        try {
            $endsAt = $this->paymentIntegration->cancelAtPeriodEnd($subscription);
            $this->subscriptionManager->cancelSubscription($subscription, $endsAt);
            $this->addFlash('success', sprintf(
                'Your subscription has been cancelled and will end on %s. You will not be charged again.',
                $endsAt->format('M j, Y'),
            ));
        } catch (PaymentIntegrationException $e) {
            // PaymentIntegrationException extends RuntimeException, so this
            // must be caught before the broader RuntimeException below -
            // otherwise every real HandyPay cancellation failure (bad
            // response shape, HTTP error, etc.) would be swallowed by that
            // catch and silently treated as "no external subscription id
            // yet", marking the subscription cancelled locally even though
            // the upstream cancellation never actually happened.
            $this->addFlash('error', sprintf('Could not cancel your subscription: %s', $e->getMessage()));
        } catch (RuntimeException) {
            // No external subscription id yet (e.g. checkout was started but
            // never confirmed by a HandyPay webhook) — nothing to cancel
            // upstream, so it is safe to just mark it cancelled locally.
            $this->subscriptionManager->cancelSubscription($subscription, $subscription->getEndDate());
            $this->addFlash('success', 'Your subscription has been cancelled.');
        }

        return $this->redirectToRoute('billing_index');
    }

    private function getSubscription(): ?Subscription
    {
        $companyId = $this->companySelector->getCompany();

        if (! $companyId instanceof Ulid) {
            return null;
        }

        $company = $this->companyRepository->find($companyId);

        if ($company === null) {
            return null;
        }

        return $this->subscriptionProvider->getSubscriptionFor($company);
    }
}
