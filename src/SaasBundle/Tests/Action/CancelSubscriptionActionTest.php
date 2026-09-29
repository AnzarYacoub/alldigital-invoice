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

namespace SolidInvoice\SaasBundle\Tests\Action;

use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use SolidInvoice\CoreBundle\Company\CompanySelector;
use SolidInvoice\CoreBundle\Entity\Company;
use SolidInvoice\CoreBundle\Repository\CompanyRepository;
use SolidInvoice\SaasBundle\Action\CancelSubscriptionAction;
use SolidWorx\Platform\SaasBundle\Entity\Plan;
use SolidWorx\Platform\SaasBundle\Entity\Subscription;
use SolidWorx\Platform\SaasBundle\Enum\SubscriptionStatus;
use SolidWorx\Platform\SaasBundle\Exception\PaymentIntegrationException;
use SolidWorx\Platform\SaasBundle\Integration\PaymentIntegrationInterface;
use SolidWorx\Platform\SaasBundle\Repository\PlanRepositoryInterface;
use SolidWorx\Platform\SaasBundle\Repository\SubscriptionRepositoryInterface;
use SolidWorx\Platform\SaasBundle\Subscription\SubscriptionManager;
use SolidWorx\Platform\SaasBundle\Subscription\SubscriptionProviderInterface;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Uid\Ulid;

/**
 * Requirement 1: cancel action persists local cancelled state.
 *
 * ROOT CAUSE context (see HandyPayWebhookConsumerTest for the other half of
 * this bug): CancelSubscriptionAction itself has always correctly persisted
 * SubscriptionStatus::CANCELLED via SubscriptionManager::cancelSubscription()
 * on a successful HandyPay cancellation — the reason cancellation "disappears
 * after refresh" was never that this action failed to save, but that a
 * LATER HandyPay webhook (customer.subscription.updated, still reporting
 * status "trialing" because HandyPay's cancel-at-period-end keeps that
 * status until the period actually ends) silently reverted it. These tests
 * confirm this action's own half of the contract holds.
 */
#[CoversClass(CancelSubscriptionAction::class)]
final class CancelSubscriptionActionTest extends TestCase
{
    public function testSuccessfulCancellationPersistsCancelledStatusAndEndDate(): void
    {
        $endsAt = new DateTimeImmutable('+11 days');
        $subscription = $this->makeSubscription('sub_real123');

        $paymentIntegration = $this->createMock(PaymentIntegrationInterface::class);
        $paymentIntegration->method('cancelAtPeriodEnd')->willReturn($endsAt);

        $subscriptionRepository = $this->createMock(SubscriptionRepositoryInterface::class);
        $subscriptionRepository->expects(self::atLeastOnce())->method('save');

        [$action, $session] = $this->buildAction($subscription, $subscriptionRepository, $paymentIntegration);

        $action($this->makeRequest());

        self::assertSame(SubscriptionStatus::CANCELLED, $subscription->getStatus());
        self::assertSame($endsAt->getTimestamp(), $subscription->getEndDate()->getTimestamp());
        // The external sub_... id is the historical record of what was
        // actually billed — cancelling must never clear it.
        self::assertSame('sub_real123', $subscription->getSubscriptionId());
        self::assertNotEmpty($session->getFlashBag()->get('success'));
    }

    /**
     * A checkout was started but never confirmed by a HandyPay webhook
     * (no external id yet) — nothing to cancel upstream, but the local row
     * must still end up CANCELLED rather than left in whatever state it
     * was in.
     */
    public function testCancellationWithNoExternalSubscriptionIdStillPersistsCancelledLocally(): void
    {
        $subscription = $this->makeSubscription(null);

        $paymentIntegration = $this->createMock(PaymentIntegrationInterface::class);
        $paymentIntegration->method('cancelAtPeriodEnd')->willThrowException(
            new RuntimeException('Subscription has no external HandyPay subscription id yet.'),
        );

        $subscriptionRepository = $this->createMock(SubscriptionRepositoryInterface::class);
        $subscriptionRepository->expects(self::atLeastOnce())->method('save');

        [$action, $session] = $this->buildAction($subscription, $subscriptionRepository, $paymentIntegration);

        $action($this->makeRequest());

        self::assertSame(SubscriptionStatus::CANCELLED, $subscription->getStatus());
        self::assertNotEmpty($session->getFlashBag()->get('success'));
    }

    /**
     * A real HandyPay cancellation failure (PaymentIntegrationException,
     * which extends RuntimeException) must NOT be treated as "no external
     * subscription id yet" - the two catch blocks must stay in the right
     * order (PaymentIntegrationException before the broader
     * RuntimeException) or every real upstream cancellation failure would
     * be silently swallowed and the subscription marked cancelled locally
     * even though HandyPay never actually cancelled it.
     */
    public function testPaymentIntegrationExceptionDoesNotCancelLocally(): void
    {
        $subscription = $this->makeSubscription('sub_real123');
        $statusBeforeAttempt = $subscription->getStatus();

        $paymentIntegration = $this->createMock(PaymentIntegrationInterface::class);
        $paymentIntegration->method('cancelAtPeriodEnd')->willThrowException(
            new PaymentIntegrationException('HandyPay did not return a recognisable period-end field.'),
        );

        $subscriptionRepository = $this->createMock(SubscriptionRepositoryInterface::class);
        $subscriptionRepository->expects(self::never())->method('save');

        [$action, $session] = $this->buildAction($subscription, $subscriptionRepository, $paymentIntegration);

        $action($this->makeRequest());

        // Status is unchanged - specifically NOT CANCELLED - and the
        // external id (the historical billing record) is untouched.
        self::assertSame($statusBeforeAttempt, $subscription->getStatus());
        self::assertSame('sub_real123', $subscription->getSubscriptionId());
        self::assertEmpty($session->getFlashBag()->get('success'));
        self::assertNotEmpty($session->getFlashBag()->get('error'));
    }

    private function makeSubscription(?string $externalSubscriptionId): Subscription
    {
        $subscription = new Subscription();
        new ReflectionProperty(Subscription::class, 'id')->setValue($subscription, new Ulid());
        $subscription->setPlan(
            new Plan()
                ->setName('Starter')
                ->setPlanId('starter-monthly')
                ->setPrice(1200)
                ->setActive(true),
        );
        $subscription->setStatus(SubscriptionStatus::TRIAL);
        $subscription->setEndDate(new DateTimeImmutable('+14 days'));

        if ($externalSubscriptionId !== null) {
            $subscription->setSubscriptionId($externalSubscriptionId);
        }

        return $subscription;
    }

    /**
     * @return array{0: CancelSubscriptionAction, 1: Session}
     */
    private function buildAction(
        Subscription $subscription,
        SubscriptionRepositoryInterface $subscriptionRepository,
        PaymentIntegrationInterface $paymentIntegration,
    ): array {
        $subscriptionManager = new SubscriptionManager(
            $subscriptionRepository,
            $this->createStub(PlanRepositoryInterface::class),
            $paymentIntegration,
        );

        $subscriptionProvider = $this->createMock(SubscriptionProviderInterface::class);
        $subscriptionProvider->method('getSubscriptionFor')->willReturn($subscription);

        $companyRepository = $this->createMock(CompanyRepository::class);
        $companyRepository->method('find')->willReturn(new Company());

        $companySelector = new CompanySelector($this->createStub(ManagerRegistry::class));
        new ReflectionProperty(CompanySelector::class, 'companyId')->setValue($companySelector, new Ulid());

        $action = new CancelSubscriptionAction(
            $subscriptionManager,
            $subscriptionProvider,
            $paymentIntegration,
            $companyRepository,
            $companySelector,
        );

        $csrfTokenManager = $this->createMock(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('isTokenValid')->willReturn(true);

        $router = $this->createMock(RouterInterface::class);
        $router->method('generate')->willReturn('/billing/');

        $session = new Session(new MockArraySessionStorage());
        $request = $this->makeRequest();
        $request->setSession($session);
        $requestStack = new RequestStack([$request]);

        $container = new Container();
        $container->set('security.csrf.token_manager', $csrfTokenManager);
        $container->set('router', $router);
        $container->set('request_stack', $requestStack);

        $action->setContainer($container);

        return [$action, $session];
    }

    private function makeRequest(): Request
    {
        $request = Request::create('/billing/subscription/cancel', Request::METHOD_POST, [
            '_token' => 'token',
        ]);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }
}
