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

namespace SolidInvoice\UserBundle\Tests\Onboarding\Manager;

use PHPUnit\Framework\Attributes\CoversClass;
use SolidInvoice\ClientBundle\Repository\ClientRepository;
use SolidInvoice\CoreBundle\Repository\CompanyRepository;
use SolidInvoice\CoreBundle\Test\Traits\DoctrineTestTrait;
use SolidInvoice\InstallBundle\Test\EnsureApplicationInstalled;
use SolidInvoice\InvoiceBundle\Entity\Invoice;
use SolidInvoice\InvoiceBundle\Repository\InvoiceRepository;
use SolidInvoice\UserBundle\Entity\User;
use SolidInvoice\UserBundle\Enum\UserSettingType;
use SolidInvoice\UserBundle\Onboarding\DTO\OnboardingData;
use SolidInvoice\UserBundle\Onboarding\Manager\OnboardingManager;
use SolidInvoice\UserBundle\Repository\UserSettingRepository;
use SolidWorx\Platform\SaasBundle\Entity\Subscription;
use SolidWorx\Platform\SaasBundle\Subscription\SubscriptionProviderInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(OnboardingManager::class)]
final class OnboardingManagerTest extends KernelTestCase
{
    use DoctrineTestTrait;
    use EnsureApplicationInstalled;

    private OnboardingManager $manager;

    private UserSettingRepository $userSettingRepository;

    private ClientRepository $clientRepository;

    private InvoiceRepository $invoiceRepository;

    /**
     * Mocked rather than the real service: hasExternallyBilledSubscription()
     * is tested purely as manager-level logic here (does it correctly read
     * whatever the provider returns), independent of how a Subscription
     * actually gets persisted/associated with a Company in the vendor
     * schema - that wiring is already covered by SaasBundle's own
     * CompanyEventSubscriber/SubscribeController tests.
     */
    private SubscriptionProviderInterface&\PHPUnit\Framework\MockObject\MockObject $subscriptionProvider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userSettingRepository = self::getContainer()->get(UserSettingRepository::class);
        $companyRepository = self::getContainer()->get(CompanyRepository::class);
        $this->clientRepository = self::getContainer()->get(ClientRepository::class);
        $this->invoiceRepository = self::getContainer()->get(InvoiceRepository::class);
        $this->subscriptionProvider = $this->createMock(SubscriptionProviderInterface::class);

        // Manually create OnboardingManager since it may not be public in test container
        $this->manager = new OnboardingManager(
            $this->em,
            $companyRepository,
            $this->clientRepository,
            $this->invoiceRepository,
            $this->userSettingRepository,
            $this->subscriptionProvider,
        );
    }

    public function testIsOnboardingCompleteReturnsFalseWhenNotComplete(): void
    {
        $user = $this->createUser('test@example.com');
        $this->em->persist($user);
        $this->em->flush();

        self::assertFalse($this->manager->isOnboardingComplete($user));
    }

    public function testIsOnboardingCompleteReturnsTrueWhenComplete(): void
    {
        $user = $this->createUser('test2@example.com');
        $this->em->persist($user);
        $this->em->flush();

        $this->manager->dismissOnboarding($user);

        self::assertTrue($this->manager->isOnboardingComplete($user));
    }

    public function testGetCurrentStepReturnsNullWhenNotSet(): void
    {
        $user = $this->createUser('test3@example.com');
        $this->em->persist($user);
        $this->em->flush();

        self::assertNull($this->manager->getCurrentStep($user));
    }

    public function testGetCurrentStepReturnsStepName(): void
    {
        $user = $this->createUser('test4@example.com');
        $this->em->persist($user);
        $this->em->flush();

        $this->manager->setCurrentStep($user, 'client');

        self::assertSame('client', $this->manager->getCurrentStep($user));
    }

    public function testSetCurrentStepSavesSetting(): void
    {
        $user = $this->createUser('test5@example.com');
        $this->em->persist($user);
        $this->em->flush();

        $this->manager->setCurrentStep($user, 'invoice');

        $setting = $this->userSettingRepository->findOneBy([
            'user' => $user,
            'key' => UserSettingType::OnboardingStep,
        ]);

        self::assertNotNull($setting);
        self::assertSame('invoice', $setting->getValue());
    }

    public function testMarkStepSkippedAddsStepToSkippedList(): void
    {
        $user = $this->createUser('test6@example.com');
        $this->em->persist($user);
        $this->em->flush();

        $this->manager->markStepSkipped($user, 'client');

        $setting = $this->userSettingRepository->findOneBy([
            'user' => $user,
            'key' => UserSettingType::OnboardingSkipped,
        ]);

        self::assertNotNull($setting);
        $skipped = json_decode((string) $setting->getValue(), true);
        self::assertSame(['client'], $skipped);
    }

    public function testStartOnboardingSetsInitialStep(): void
    {
        $user = $this->createUser('test7@example.com');
        $this->em->persist($user);
        $this->em->flush();

        $this->manager->startOnboarding($user);

        self::assertSame('company', $this->manager->getCurrentStep($user));

        $startedAtSetting = $this->userSettingRepository->findOneBy([
            'user' => $user,
            'key' => UserSettingType::OnboardingStartedAt,
        ]);
        self::assertNotNull($startedAtSetting);
    }

    public function testCompleteOnboardingWithFullData(): void
    {
        $user = $this->createUser('test8@example.com');
        $this->em->persist($user);
        $this->em->flush();

        $data = new OnboardingData();
        $data->companyName = 'Test Company';
        $data->companyCurrency = 'USD';
        $data->clientName = 'Test Client';
        $data->clientEmail = 'client@example.com';
        $data->invoiceDescription = 'Test Service';
        $data->invoiceAmount = '1000.00';

        $invoice = $this->manager->completeOnboarding($user, $data);

        self::assertInstanceOf(Invoice::class, $invoice);
        self::assertTrue($this->manager->isOnboardingComplete($user));

        // Verify company was created
        self::assertCount(1, $user->getCompanies());
        $company = $user->getCompanies()->first();
        self::assertSame('Test Company', $company->getName());

        // Verify client was created
        $clients = $this->clientRepository->findBy(['company' => $company]);
        self::assertCount(1, $clients);
        self::assertSame('Test Client', $clients[0]->getName());

        // Verify invoice was created
        $invoices = $this->invoiceRepository->findBy(['company' => $company]);
        self::assertCount(1, $invoices);
        self::assertSame('Test Service', $invoices[0]->getLines()->first()->getDescription());
    }

    public function testCompleteOnboardingWithoutClientAndInvoice(): void
    {
        $user = $this->createUser('test9@example.com');
        $this->em->persist($user);
        $this->em->flush();

        $data = new OnboardingData();
        $data->companyName = 'Test Company';
        $data->companyCurrency = 'EUR';

        $invoice = $this->manager->completeOnboarding($user, $data);

        self::assertNull($invoice);
        self::assertTrue($this->manager->isOnboardingComplete($user));

        // Verify company was created
        self::assertCount(1, $user->getCompanies());
        $company = $user->getCompanies()->first();
        self::assertSame('Test Company', $company->getName());
        self::assertSame('EUR', $company->currency);

        // Verify no clients or invoices were created
        self::assertCount(0, $this->clientRepository->findBy(['company' => $company]));
        self::assertCount(0, $this->invoiceRepository->findBy(['company' => $company]));

        // Verify steps were marked as skipped
        $setting = $this->userSettingRepository->findOneBy([
            'user' => $user,
            'key' => UserSettingType::OnboardingSkipped,
        ]);
        self::assertNotNull($setting);
        $skipped = json_decode((string) $setting->getValue(), true);
        self::assertContains('client', $skipped);
        self::assertContains('invoice', $skipped);
    }

    public function testCompleteOnboardingWithClientButNoInvoice(): void
    {
        $user = $this->createUser('test10@example.com');
        $this->em->persist($user);
        $this->em->flush();

        $data = new OnboardingData();
        $data->companyName = 'Test Company';
        $data->companyCurrency = 'GBP';
        $data->clientName = 'Jane Doe';
        $data->clientEmail = 'jane@example.com';

        $invoice = $this->manager->completeOnboarding($user, $data);

        self::assertNull($invoice);
        self::assertTrue($this->manager->isOnboardingComplete($user));

        // Verify company and client were created
        $company = $user->getCompanies()->first();
        self::assertCount(1, $this->clientRepository->findBy(['company' => $company]));

        // Verify no invoice was created
        self::assertCount(0, $this->invoiceRepository->findBy(['company' => $company]));

        // Verify only invoice step was skipped
        $setting = $this->userSettingRepository->findOneBy([
            'user' => $user,
            'key' => UserSettingType::OnboardingSkipped,
        ]);
        self::assertNotNull($setting);
        $skipped = json_decode((string) $setting->getValue(), true);
        self::assertContains('invoice', $skipped);
        self::assertNotContains('client', $skipped);
    }

    public function testDismissOnboardingMarksAsComplete(): void
    {
        $user = $this->createUser('test11@example.com');
        $this->em->persist($user);
        $this->em->flush();

        $this->manager->dismissOnboarding($user);

        $setting = $this->userSettingRepository->findOneBy([
            'user' => $user,
            'key' => UserSettingType::OnboardComplete,
        ]);

        self::assertNotNull($setting);
        self::assertSame('dismissed', $setting->getValue());
        self::assertTrue($this->manager->isOnboardingComplete($user));
    }

    /**
     * Launch-blocker requirement 1/3 (new regular user): a freshly
     * onboarded company with no subscription at all (e.g. no default plan
     * configured) must be reported as NOT externally billed - the caller
     * (Onboarding action) must send this user to choose a plan.
     */
    public function testHasExternallyBilledSubscriptionReturnsFalseWhenNoSubscriptionExists(): void
    {
        $user = $this->createUser('no-subscription@example.com');
        $this->em->persist($user);
        $this->em->flush();

        $this->manager->completeOnboarding($user, $this->minimalOnboardingData('No Sub Co'));

        $this->subscriptionProvider->method('getSubscriptionFor')->willReturn(null);

        self::assertFalse($this->manager->hasExternallyBilledSubscription($user));
    }

    /**
     * Launch-blocker requirement 1 (new regular user): a subscription that
     * exists locally (e.g. the silently-granted first-ever trial) but was
     * never actually checked out via HandyPay is NOT externally billed -
     * this is the exact scenario the launch-blocker fix targets.
     */
    public function testHasExternallyBilledSubscriptionReturnsFalseForLocalOnlySubscription(): void
    {
        $user = $this->createUser('local-trial-only@example.com');
        $this->em->persist($user);
        $this->em->flush();

        $this->manager->completeOnboarding($user, $this->minimalOnboardingData('Local Trial Co'));

        // No setSubscriptionId() call: never checked out via HandyPay.
        $subscription = new Subscription();

        $this->subscriptionProvider->method('getSubscriptionFor')->willReturn($subscription);

        self::assertFalse($this->manager->hasExternallyBilledSubscription($user));
    }

    /**
     * Launch-blocker requirement 3 (existing active/trial externally billed
     * subscription must not be sent into another checkout): once HandyPay
     * has confirmed a real subscription id, hasExternallyBilledSubscription()
     * must report true so the Onboarding action lets the user straight
     * through to the dashboard/invoice instead of saas_subscription_plans.
     */
    public function testHasExternallyBilledSubscriptionReturnsTrueOnceExternallyBilled(): void
    {
        $user = $this->createUser('already-billed@example.com');
        $this->em->persist($user);
        $this->em->flush();

        $this->manager->completeOnboarding($user, $this->minimalOnboardingData('Already Billed Co'));

        $subscription = new Subscription();
        $subscription->setSubscriptionId('sub_real_123');

        $this->subscriptionProvider->method('getSubscriptionFor')->willReturn($subscription);

        self::assertTrue($this->manager->hasExternallyBilledSubscription($user));
    }

    /**
     * Defensive edge case: a user with no company at all (should not be
     * reachable in practice - completeOnboarding() always adds one) must
     * not be treated as externally billed, and the subscription provider
     * must not even be consulted since there's no company to look one up
     * for.
     */
    public function testHasExternallyBilledSubscriptionReturnsFalseWhenUserHasNoCompany(): void
    {
        $user = $this->createUser('no-company@example.com');
        $this->em->persist($user);
        $this->em->flush();

        $this->subscriptionProvider->expects(self::never())->method('getSubscriptionFor');

        self::assertFalse($this->manager->hasExternallyBilledSubscription($user));
    }

    private function minimalOnboardingData(string $companyName): OnboardingData
    {
        $data = new OnboardingData();
        $data->companyName = $companyName;
        $data->companyCurrency = 'USD';

        return $data;
    }

    private function createUser(string $email): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setPassword('dummy-password');
        return $user;
    }
}
