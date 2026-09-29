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

use SolidInvoice\CoreBundle\Company\CompanySelector;
use SolidInvoice\CoreBundle\Repository\CompanyRepository;
use SolidInvoice\SaasBundle\Subscription\ExternalBillingPlanChangeGuard;
use SolidInvoice\SaasBundle\Subscription\PlanChangeGuardResult;
use SolidWorx\Platform\SaasBundle\Entity\Plan;
use SolidWorx\Platform\SaasBundle\Entity\Subscription;
use SolidWorx\Platform\SaasBundle\Enum\SubscriptionStatus;
use SolidWorx\Platform\SaasBundle\Repository\PlanRepositoryInterface;
use SolidWorx\Platform\SaasBundle\Subscription\SubscriptionManager;
use SolidWorx\Platform\SaasBundle\Subscription\SubscriptionProviderInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Ulid;

final class ConfirmPlanChangeAction extends AbstractController
{
    public function __construct(
        private readonly PlanRepositoryInterface $planRepository,
        private readonly SubscriptionManager $subscriptionManager,
        private readonly SubscriptionProviderInterface $subscriptionProvider,
        private readonly CompanyRepository $companyRepository,
        private readonly CompanySelector $companySelector,
        private readonly ExternalBillingPlanChangeGuard $externalBillingGuard,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if (! $this->isCsrfTokenValid('change_plan', (string) $request->request->get('_token', ''))) {
            $this->addFlash('error', 'Invalid security token, please try again.');

            return $this->redirectToRoute('saas_subscription_change');
        }

        $subscription = $this->getSubscription();

        if (! $subscription instanceof Subscription) {
            $this->addFlash('error', 'No subscription found.');

            return $this->redirectToRoute('saas_subscription_plans');
        }

        $planId = (string) $request->request->get('plan', '');
        $plan = $planId === '' ? null : $this->planRepository->find($planId);

        if (! $plan instanceof Plan || ! $plan->isActive()) {
            $this->addFlash('error', 'The selected plan is invalid.');

            return $this->redirectToRoute('saas_subscription_change');
        }

        // Re-selecting the plan already in effect is only a no-op while
        // that plan is actually still active. Once the subscription has
        // been CANCELLED, the "same" plan is no longer in effect — it must
        // be allowed through as a resubscribe, exactly like picking a
        // different plan (see ExternalBillingPlanChangeGuard below, which
        // also stands down for a CANCELLED subscription).
        if (
            $subscription->isExternallyBilled()
            && $subscription->getStatus() !== SubscriptionStatus::CANCELLED
            && $plan->getPlanId() === $subscription->getPlan()->getPlanId()
        ) {
            return $this->redirectToRoute('billing_index');
        }

        $isDowngrade = $plan->getPrice() < $subscription->getPlan()->getPrice();
        $confirmed = $request->request->getBoolean('confirmed');

        if ($isDowngrade && ! $confirmed) {
            return $this->render('@SolidInvoiceSaas/subscription/_change_confirm.html.twig', [
                'subscription' => $subscription,
                'currentPlan' => $subscription->getPlan(),
                'newPlan' => $plan,
            ]);
        }

        // ROOT CAUSE (confirmed live) of "changing plan is not saving": this
        // used to only gate on `status === ACTIVE && isExternallyBilled()`.
        // A TRIAL subscription with a real HandyPay subscription attached
        // (the normal state for every paid trial under the
        // card-required-upfront flow) fell through to the "no external
        // billing yet" branch below instead, which — for a paid target plan
        // — redirected to `saas_subscription_checkout`. That route now
        // refuses to start a second HandyPay subscription once one is
        // already attached (SubscribeController's duplicate-checkout
        // guard), so the redirect silently bounced straight back here with
        // an unrelated flash message and no plan change ever took effect.
        //
        // ExternalBillingPlanChangeGuard now handles EVERY externally-billed
        // subscription the same way regardless of status: a downgrade to
        // Free safely schedules a real HandyPay cancel-at-period-end (the
        // one plan-change HandyPay does support today); anything else is
        // explicitly blocked rather than silently no-op'd, faked locally, or
        // routed through checkout to spawn a second subscription.
        $guardResult = $this->externalBillingGuard->handle($subscription, $plan);

        if ($guardResult instanceof PlanChangeGuardResult) {
            $this->addFlash($guardResult->flashType, $guardResult->message);

            return $this->redirectToRoute('billing_index');
        }

        // From here the subscription has no external billing yet (never
        // checked out, or currently on the free plan) — safe to commit
        // locally or send to checkout exactly as before.
        if ($plan->isFree()) {
            $this->subscriptionManager->changePlan($subscription, $plan);
            $this->subscriptionManager->activate($subscription);
            $this->addFlash('success', 'Your plan has been changed.');

            return $this->redirectToRoute('billing_index');
        }

        return $this->redirectToRoute('saas_subscription_checkout', [
            ChoosePlanAction::PENDING_PLAN_QUERY_PARAMETER => $plan->getPlanId(),
        ]);
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
