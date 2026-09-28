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
use SolidInvoice\CoreBundle\Company\CompanySelector;
use SolidInvoice\CoreBundle\Entity\Company;
use SolidInvoice\CoreBundle\Repository\CompanyRepository;
use SolidInvoice\SaasBundle\Action\SubscriptionOverviewAction;
use SolidWorx\Platform\SaasBundle\Entity\Plan;
use SolidWorx\Platform\SaasBundle\Entity\Subscription;
use SolidWorx\Platform\SaasBundle\Enum\SubscriptionStatus;
use SolidWorx\Platform\SaasBundle\Subscription\SubscriptionProviderInterface;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Uid\Ulid;
use Twig\Environment;

/**
 * Requirement 2: refresh after cancellation does not show the "Cancel
 * subscription" button again.
 *
 * overview.html.twig hides the "Cancel subscription" form/button and swaps
 * the primary action to "Subscribe again" purely off the `isCancelled`
 * variable this action computes. These tests pin down that this action
 * itself reports the right value for every relevant status — the other
 * half of the guarantee (that overview.html.twig actually branches on it
 * correctly) is a template-level concern, not something a PHP unit test can
 * cover without booting a full Twig environment.
 */
#[CoversClass(SubscriptionOverviewAction::class)]
final class SubscriptionOverviewActionTest extends TestCase
{
    public function testCancelledSubscriptionReportsIsCancelledTrue(): void
    {
        $subscription = $this->makeSubscription(SubscriptionStatus::CANCELLED, 'sub_real123');

        $parameters = $this->invokeAndCaptureViewParameters($subscription);

        self::assertTrue($parameters['isCancelled']);
        self::assertSame('cancelled', $parameters['subscription']->getStatus()->value);
    }

    /**
     * The negative case, for every OTHER status a subscription can be in —
     * none of these should ever report isCancelled === true, which is what
     * would incorrectly hide the "Cancel subscription" button / show
     * "Subscribe again" for a subscription that is still live.
     */
    public function testNonCancelledStatusesReportIsCancelledFalse(): void
    {
        foreach ([SubscriptionStatus::TRIAL, SubscriptionStatus::ACTIVE, SubscriptionStatus::PAST_DUE, SubscriptionStatus::PAUSED] as $status) {
            $subscription = $this->makeSubscription($status, 'sub_real123');

            $parameters = $this->invokeAndCaptureViewParameters($subscription);

            self::assertFalse($parameters['isCancelled'], sprintf('Status "%s" must not report isCancelled=true.', $status->value));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function invokeAndCaptureViewParameters(Subscription $subscription): array
    {
        $subscriptionProvider = $this->createMock(SubscriptionProviderInterface::class);
        $subscriptionProvider->method('getSubscriptionFor')->willReturn($subscription);

        $companyRepository = $this->createMock(CompanyRepository::class);
        $companyRepository->method('find')->willReturn(new Company());

        $companySelector = new CompanySelector($this->createStub(ManagerRegistry::class));
        new ReflectionProperty(CompanySelector::class, 'companyId')->setValue($companySelector, new Ulid());

        $action = new SubscriptionOverviewAction(
            $subscriptionProvider,
            $companyRepository,
            $companySelector,
        );

        $captured = null;
        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturnCallback(function (string $view, array $parameters) use (&$captured): string {
            $captured = $parameters;

            return '<html></html>';
        });

        $container = new Container();
        $container->set('twig', $twig);
        $action->setContainer($container);

        $action->__invoke();

        self::assertIsArray($captured);

        return $captured;
    }

    private function makeSubscription(SubscriptionStatus $status, ?string $externalSubscriptionId): Subscription
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
        $subscription->setStatus($status);
        $subscription->setStartDate(new DateTimeImmutable('-3 days'));
        $subscription->setEndDate(new DateTimeImmutable('+11 days'));

        if ($externalSubscriptionId !== null) {
            $subscription->setSubscriptionId($externalSubscriptionId);
        }

        return $subscription;
    }
}
