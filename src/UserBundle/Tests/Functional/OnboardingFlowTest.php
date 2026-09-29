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

namespace SolidInvoice\UserBundle\Tests\Functional;

use DateInterval;
use Override;
use PHPUnit\Framework\Attributes\Group;
use SolidInvoice\CoreBundle\Test\Factory\CompanyFactory;
use SolidInvoice\CoreBundle\Test\Traits\DoctrineTestTrait;
use SolidInvoice\InstallBundle\Test\EnsureApplicationInstalled;
use SolidInvoice\InvoiceBundle\Entity\Invoice;
use SolidInvoice\SaasBundle\Tests\SaasTestKernel;
use SolidInvoice\UserBundle\Entity\User;
use SolidInvoice\UserBundle\Enum\UserSettingType;
use SolidInvoice\UserBundle\Onboarding\Manager\OnboardingManager;
use SolidInvoice\UserBundle\Repository\UserRepository;
use SolidInvoice\UserBundle\Repository\UserSettingRepository;
use SolidInvoice\UserBundle\Test\Factory\UserFactory;
use SolidWorx\Platform\SaasBundle\Entity\Plan;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Browser\Test\HasBrowser;
use Zenstruck\Foundry\Test\Factories;

/**
 * Boots with SOLIDINVOICE_PLATFORM=saas via SaasTestKernel (see
 * createKernel() below) rather than the default test kernel. This whole
 * class is about onboarding's interaction with SaaS billing - three tests
 * seed plans and assert the plan-selection redirect the launch-blocker
 * fix added, and the rest exercise the same Onboarding action/OnboardingManager
 * those three do, just without reaching the billing-gated branch. Testing
 * all of them under the SaaS-enabled container matches how this app
 * actually runs in every real deployment (SOLIDINVOICE_PLATFORM=saas is
 * always set outside the default test kernel - see OnboardingManager's
 * constructor docblock), rather than a container shape (SaaS bundle
 * absent) that never occurs outside `phpunit` with no env override. This
 * mirrors the existing SaasTestKernel usage in
 * SolidInvoice\SaasBundle\Tests\Email\OnboardingEmailSnapshotTest and
 * SolidInvoice\SaasBundle\Tests\Functional\FeatureCatalogTest - it isn't
 * a new pattern.
 */
#[Group('functional')]
final class OnboardingFlowTest extends WebTestCase
{
    use HasBrowser;
    use DoctrineTestTrait;
    use Factories;
    use EnsureApplicationInstalled;

    private UserSettingRepository $userSettingRepository;

    /**
     * @param array<string, mixed> $options
     */
    #[Override]
    protected static function createKernel(array $options = []): SaasTestKernel
    {
        $env = $options['environment'] ?? $_ENV['SOLIDINVOICE_ENV'] ?? $_SERVER['SOLIDINVOICE_ENV'] ?? 'test';
        $debug = $options['debug'] ?? (bool) ($_ENV['SOLIDINVOICE_DEBUG'] ?? $_SERVER['SOLIDINVOICE_DEBUG'] ?? true);

        return new SaasTestKernel($env, $debug);
    }

    protected function setUp(): void
    {
        $this->userSettingRepository = self::getContainer()->get(UserSettingRepository::class);
    }

    /**
     * Launch-blocker fix (requirement 1): a brand-new regular user's
     * first-ever trial (silently started by SaasBundle's
     * CompanyEventSubscriber when their company is created) is never
     * externally billed - see OnboardingManager::hasExternallyBilledSubscription().
     * Onboarding completion must therefore route through plan selection
     * (saas_subscription_plans) instead of straight to the invoice/dashboard.
     * Two active plans are seeded so SelectPlanAction actually renders the
     * picker instead of its own single-plan shortcut into checkout, which
     * would otherwise call out to HandyPay - out of scope for this test.
     */
    public function testCompleteOnboardingWithAllSteps(): void
    {
        $this->seedPlans();

        $user = $this->createUser('test@example.com', 'password');

        $this->browser()
            ->actingAs($user)
            ->visit('/onboarding')
            ->assertSuccessful()
            ->assertOn('/onboarding')
            ->assertSee("What we're setting up:")
            ->assertSee('Company Name')
            // Fill company step
            ->fillField('onboarding[company][companyName]', 'Acme Corporation')
            ->selectFieldOption('onboarding[company][companyCurrency]', 'USD')
            ->click('Continue')
            // Client step
            ->assertSuccessful()
            ->assertSee('Add your first client')
            ->fillField('onboarding[client][clientName]', 'John Doe')
            ->fillField('onboarding[client][clientEmail]', 'john@example.com')
            ->click('Continue')
            // Invoice step
            ->assertSuccessful()
            ->assertSee('Create your first invoice')
            ->fillField('onboarding[invoice][invoiceDescription]', 'Website Design')
            ->fillField('onboarding[invoice][invoiceAmount]', '1500.00')
            ->interceptRedirects()
            ->click('Create & View My Invoice')
            // Must be sent to choose a plan, not straight to the invoice.
            ->assertRedirectedTo('/subscription/plans')
        ;

        // Refresh user
        $user = self::getContainer()->get(UserRepository::class)->find($user->getId());

        // Verify onboarding is marked complete
        $setting = $this->userSettingRepository->findOneBy([
            'user' => $user,
            'key' => UserSettingType::OnboardComplete,
        ]);
        self::assertNotNull($setting);
        self::assertSame('true', $setting->getValue());

        // Verify company was created
        self::assertCount(1, $user->getCompanies());

        // Verify the invoice was still created - only the redirect target
        // changed, not whether onboarding actually completes the data.
        $invoices = $this->em->getRepository(Invoice::class)->findBy(['company' => $user->getCompanies()->first()]);
        self::assertCount(1, $invoices);
        self::assertSame('Website Design', $invoices[0]->getLines()->first()->getDescription());
    }

    /**
     * Launch-blocker fix (requirement 1): same as
     * testCompleteOnboardingWithAllSteps, but via the "skip everything"
     * path - a first-ever trial must still route through plan selection
     * rather than straight to the dashboard.
     */
    public function testSkipClientStep(): void
    {
        $this->seedPlans();

        $user = $this->createUser('test2@example.com', 'password');

        $this->browser()
            ->actingAs($user)
            ->visit('/onboarding')
            ->assertSuccessful()
            // Company step
            ->fillField('onboarding[company][companyName]', 'Test Company')
            ->selectFieldOption('onboarding[company][companyCurrency]', 'USD')
            ->click('Continue')
            // Skip client step
            ->assertSuccessful()
            ->assertSee('Add your first client')
            ->click('#onboarding_navigator_skip')
            // Should go to complete step (invoice auto-skipped)
            ->assertSuccessful()
            ->assertSee("You're all set!")
            ->interceptRedirects()
            ->click('Go to Dashboard')
            // Must be sent to choose a plan, not straight to the dashboard.
            ->assertRedirectedTo('/subscription/plans')
        ;

        // Verify both client and invoice were skipped
        $setting = $this->userSettingRepository->findOneBy([
            'user' => $user,
            'key' => UserSettingType::OnboardingSkipped,
        ]);
        self::assertNotNull($setting);
        $skipped = json_decode((string) $setting->getValue(), true);
        self::assertContains('client', $skipped);
        self::assertContains('invoice', $skipped);
    }

    /**
     * Launch-blocker fix (requirement 1): same as
     * testCompleteOnboardingWithAllSteps, but skipping only the invoice
     * step - a first-ever trial must still route through plan selection
     * rather than straight to the dashboard.
     */
    public function testSkipInvoiceStepOnly(): void
    {
        $this->seedPlans();

        $user = $this->createUser('test3@example.com', 'password');

        $this->browser()
            ->actingAs($user)
            ->visit('/onboarding')
            ->assertSuccessful()
            // Company step
            ->fillField('onboarding[company][companyName]', 'Test Company')
            ->selectFieldOption('onboarding[company][companyCurrency]', 'USD')
            ->click('Continue')
            // Client step
            ->assertSuccessful()
            ->fillField('onboarding[client][clientName]', 'Jane Smith')
            ->fillField('onboarding[client][clientEmail]', 'jane@example.com')
            ->click('Continue')
            // Skip invoice step
            ->assertSuccessful()
            ->assertSee('Create your first invoice')
            ->click("I'll do this later")
            // Should go to complete step
            ->assertSuccessful()
            ->assertSee("You're all set!")
            ->interceptRedirects()
            ->click('Go to Dashboard')
            // Must be sent to choose a plan, not straight to the dashboard.
            ->assertRedirectedTo('/subscription/plans')
        ;

        // Verify only invoice was skipped
        $setting = $this->userSettingRepository->findOneBy([
            'user' => $user,
            'key' => UserSettingType::OnboardingSkipped,
        ]);
        self::assertNotNull($setting);
        $skipped = json_decode((string) $setting->getValue(), true);
        self::assertContains('invoice', $skipped);
        self::assertNotContains('client', $skipped);
    }

    public function testCompanyStepHasNoSkipButton(): void
    {
        $user = $this->createUser('test4@example.com', 'password');

        $browser = $this->browser()
            ->actingAs($user)
            ->visit('/onboarding')
            ->assertSuccessful()
            ->assertSee('Company Name')
        ;

        // Verify skip button is not present on company step
        $browser->assertNotSee("I'll do this later");
    }

    public function testInvitedUserDoesNotSeeOnboarding(): void
    {
        // Create a company first
        $company = CompanyFactory::createOne();

        // Create a user with an existing company (invited user)
        $user = UserFactory::createOne([
            'email' => 'invited@example.com',
            'companies' => [$company],
        ])->_real();

        // Hash the password
        $passwordHasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPassword($passwordHasher->hashPassword($user, 'password'));
        $this->em->flush();

        $this->browser()
            ->visit('/login')
            ->assertSuccessful()
            ->fillField('_username', 'invited@example.com')
            ->fillField('_password', 'password')
            ->click('Sign in')
            ->followRedirect()
            // Should go to dashboard, not onboarding
            ->assertOn('/dashboard')
        ;
    }

    public function testNewUserRedirectedToOnboardingAfterLogin(): void
    {
        $this->createUser('newuser@example.com', 'password');

        $this->browser()
            ->visit('/login')
            ->assertSuccessful()
            ->fillField('_username', 'newuser@example.com')
            ->fillField('_password', 'password')
            ->click('Sign in')
            ->followRedirect()
            // Should be redirected to onboarding
            ->assertOn('/onboarding')
            ->assertSee('Company Name')
        ;
    }

    public function testOnboardingRedirectsToDashboardIfAlreadyComplete(): void
    {
        $user = $this->createUser('complete@example.com', 'password');

        // Mark onboarding as complete
        $manager = self::getContainer()->get(OnboardingManager::class);
        $manager->dismissOnboarding($user);

        $this->browser()
            ->actingAs($user)
            ->interceptRedirects()
            ->visit('/onboarding')
            ->assertRedirectedTo('/create-company')
        ;
    }

    public function testCanNavigateBackBetweenSteps(): void
    {
        $user = $this->createUser('navigator@example.com', 'password');

        $this->browser()
            ->actingAs($user)
            ->visit('/onboarding')
            ->assertSuccessful()
            // Fill and submit company step
            ->fillField('onboarding[company][companyName]', 'Test Company')
            ->selectFieldOption('onboarding[company][companyCurrency]', 'EUR')
            ->click('Continue')
            // On client step now
            ->assertSee('Add your first client')
            ->fillField('onboarding[client][clientName]', 'Test Client')
            ->click('Continue')
            // On invoice step
            ->assertSee('Create your first invoice')
            // Click back
            ->click('#onboarding_navigator_back')
            // Should be on client step again
            ->assertSee("Let's get you set up")
            // Data should be preserved
            //->assertFieldEquals('onboarding[client][clientName]', 'Test Client')
        ;
    }

    /**
     * Seeds two active plans (mirroring SaasBundle\DataFixtures\ORM\LoadPlans'
     * real Starter/Business shape, minimally) so SaasBundle's
     * CompanyEventSubscriber has a default plan to attach to a newly
     * onboarded company, AND so SelectPlanAction actually renders the
     * picker instead of its own single-plan shortcut straight into
     * checkout (which would call out to HandyPay - out of scope here).
     */
    private function seedPlans(): void
    {
        $trial = new DateInterval('P14D');

        $starter = new Plan()
            ->setName('Starter')
            ->setPlanId('starter-monthly')
            ->setPrice(1200)
            ->setTrialDuration($trial)
            ->setDefault(true)
            ->setActive(true);

        $business = new Plan()
            ->setName('Business')
            ->setPlanId('business-monthly')
            ->setPrice(2500)
            ->setTrialDuration($trial)
            ->setActive(true);

        $this->em->persist($starter);
        $this->em->persist($business);
        $this->em->flush();
    }

    private function createUser(string $email, string $password): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setEnabled(true);
        $user->setVerified(true);

        $passwordHasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPassword($passwordHasher->hashPassword($user, $password));

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
