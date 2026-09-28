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

namespace SolidInvoice\SaasBundle\Subscription;

/**
 * Outcome of {@see ExternalBillingPlanChangeGuard::handle()} when it has
 * already handled (or explicitly blocked) a plan change itself. The caller
 * (ChoosePlanAction / ConfirmPlanChangeAction) only needs to flash this
 * message and redirect — it must not do any further plan/checkout logic.
 */
final readonly class PlanChangeGuardResult
{
    public function __construct(
        public string $flashType,
        public string $message,
    ) {
    }
}
