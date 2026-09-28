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

namespace SolidInvoice\UserBundle\Action\ForgotPassword;

use Doctrine\Persistence\ManagerRegistry;
use SolidInvoice\UserBundle\Entity\User;
use SolidInvoice\UserBundle\Form\Type\ChangePasswordFormType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use SymfonyCasts\Bundle\ResetPassword\Controller\ResetPasswordControllerTrait;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;
use function sprintf;

final class Reset extends AbstractController
{
    use ResetPasswordControllerTrait;

    public function __construct(
        private readonly ResetPasswordHelperInterface $resetPasswordHelper,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly TranslatorInterface $translator,
        private readonly ManagerRegistry $registry,
        private readonly Security $security,
    ) {
    }

    public function __invoke(Request $request, ?string $token = null): Response
    {
        if ($token) {
            // We store the token in session and remove it from the URL, to avoid the URL being
            // loaded in a browser and potentially leaking the token to 3rd party JavaScript.
            $this->storeTokenInSession($token);

            return $this->redirectToRoute('_user_password_reset');
        }

        $token = $this->getTokenFromSession();

        if (null === $token) {
            throw $this->createNotFoundException('No reset password token found in the URL or in the session.');
        }

        try {
            /** @var User $user */
            $user = $this->resetPasswordHelper->validateTokenAndFetchUser($token);
        } catch (ResetPasswordExceptionInterface $e) {
            $this->addFlash('error', sprintf(
                '%s - %s',
                $this->translator->trans(ResetPasswordExceptionInterface::MESSAGE_PROBLEM_VALIDATE, [], 'ResetPasswordBundle'),
                $this->translator->trans($e->getReason(), [], 'ResetPasswordBundle')
            ));

            return $this->redirectToRoute('_user_forgot_password');
        }

        // The token is valid; allow the user to change their password.
        $form = $this->createForm(ChangePasswordFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // A password reset token should be used only once, remove it.
            $this->resetPasswordHelper->removeResetRequest($token);

            /** @var string $plainPassword */
            $plainPassword = $form->get('plainPassword')->getData();

            // Encode(hash) the plain password, and set it.
            $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
            $this->registry->getManagerForClass(User::class)?->flush();

            // The session is cleaned up after the password has been changed.
            $this->cleanSessionAfterReset();

            // If the person completing this reset is currently authenticated
            // (e.g. they still had a logged-in session in this browser, or
            // reset their own password while signed in), that Security token
            // is untouched by everything above it and would otherwise survive
            // into the redirect below. Symfony's own CompanyEventSubscriber
            // then treats the next request as an authenticated, company-less
            // user and bounces it to Create Company instead of showing the
            // login form. Explicitly logging out (rather than relying on
            // Symfony's own credential-change session invalidation, which is
            // not guaranteed to apply here) guarantees the person always
            // lands on a clean, anonymous /login. Security::logout() throws
            // if there is no logged-in user, so this only runs when needed;
            // it invalidates the session (via SessionLogoutListener), so it
            // must run BEFORE the flash message below, not after.
            if ($this->security->getUser() instanceof UserInterface) {
                $this->security->logout(false);
            }

            $this->addFlash('success', 'Your password has been changed successfully. You can now log in.');

            return $this->redirectToRoute('_login_main');
        }

        return $this->render('@SolidInvoiceUser/ForgotPassword/reset.html.twig', [
            'form' => $form,
        ]);
    }
}
