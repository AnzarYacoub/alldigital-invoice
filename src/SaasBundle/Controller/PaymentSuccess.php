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

namespace SolidInvoice\SaasBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;

/**
 * Handles the return from HandyPay's hosted checkout. This is ONLY a
 * redirect target — HandyPay's own docs and this app's design both treat a
 * success redirect as advisory, never as proof of payment/subscription
 * activation. Real activation is confirmed exclusively by
 * HandyPayWebhookConsumer processing a signed webhook (which may arrive
 * before, at the same time as, or slightly after this redirect). The copy
 * here is deliberately non-committal for that reason.
 */
class PaymentSuccess extends AbstractController
{
    public function __invoke(): Response
    {
        $this->addFlash('success', "Thanks! We're confirming your subscription with HandyPay now — this usually takes a few seconds.");

        return $this->redirectToRoute('_dashboard');
    }
}
