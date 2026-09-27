/*
 * This file is part of SolidInvoice project.
 *
 * (c) Pierre du Plessis <open-source@solidworx.co>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

import { Controller } from '@hotwired/stimulus';

/**
 * Billing Interval Controller
 *
 * Toggles the SaaS pricing grid between monthly and yearly price/CTA
 * display. Purely client-side — no page reload, no data fetch, no fake
 * checkout. Each tier card already renders both a monthly and a yearly
 * price/form pair (see _tiered_plans_grid.html.twig); this controller
 * just shows one pair per card and hides the other.
 */
export default class extends Controller {
    static targets = [
        'switch',
        'monthlyLabel',
        'annualLabel',
        'monthlyPrice',
        'annualPrice',
        'monthlyForm',
        'annualForm',
    ];

    declare readonly switchTarget: HTMLElement;
    declare readonly monthlyLabelTarget: HTMLElement;
    declare readonly annualLabelTarget: HTMLElement;
    declare readonly monthlyPriceTargets: HTMLElement[];
    declare readonly annualPriceTargets: HTMLElement[];
    declare readonly monthlyFormTargets: HTMLElement[];
    declare readonly annualFormTargets: HTMLElement[];

    private annual = false;

    toggle(): void {
        this.annual = !this.annual;
        this.render();
    }

    private render(): void {
        this.switchTarget.setAttribute('aria-checked', this.annual ? 'true' : 'false');
        this.switchTarget.classList.toggle('is-annual', this.annual);
        this.monthlyLabelTarget.classList.toggle('is-active', !this.annual);
        this.annualLabelTarget.classList.toggle('is-active', this.annual);

        this.monthlyPriceTargets.forEach((el) => el.classList.toggle('d-none', this.annual));
        this.annualPriceTargets.forEach((el) => el.classList.toggle('d-none', !this.annual));
        this.monthlyFormTargets.forEach((el) => el.classList.toggle('d-none', this.annual));
        this.annualFormTargets.forEach((el) => el.classList.toggle('d-none', !this.annual));
    }
}
