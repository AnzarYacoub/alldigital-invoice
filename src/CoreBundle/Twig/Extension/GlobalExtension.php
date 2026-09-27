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

namespace SolidInvoice\CoreBundle\Twig\Extension;

use Carbon\Carbon;
use DateTime;
use Override;
use SolidInvoice\CoreBundle\Company\CompanySelector;
use SolidInvoice\CoreBundle\Company\ResolvedHost;
use SolidInvoice\CoreBundle\Entity\Company;
use SolidInvoice\CoreBundle\Listener\HostRoutingListener;
use SolidInvoice\CoreBundle\Pdf\Generator;
use SolidInvoice\CoreBundle\SolidInvoiceCoreBundle;
use SolidInvoice\MoneyBundle\Calculator;
use SolidInvoice\SettingsBundle\SystemConfig;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Exception\ServiceCircularReferenceException;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Ulid;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\SyntaxError;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFilter;
use Twig\TwigFunction;
use function implode;

class GlobalExtension extends AbstractExtension implements GlobalsInterface
{

    public function __construct(
        private readonly Calculator $calculator,
        private readonly Generator $pdfGenerator,
        private readonly SystemConfig $systemConfig,
        private readonly RequestStack $requestStack,
        private readonly CompanySelector $companySelector,
        private readonly ?string $installed
    ) {
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException|ServiceCircularReferenceException|ServiceNotFoundException
     */
    public function getGlobals(): array
    {
        return [
            'query' => $this->getQuery(),
            'app_version' => SolidInvoiceCoreBundle::VERSION,
            'app_name' => SolidInvoiceCoreBundle::APP_NAME,
        ];
    }

    /**
     * @return array<string, string>
     *
     * @throws ServiceCircularReferenceException|ServiceNotFoundException
     */
    protected function getQuery(): array
    {
        $request = $this->requestStack->getCurrentRequest();

        if (! $request instanceof Request) {
            return [];
        }

        $params = array_merge($request->query->all(), $request->attributes->all());

        foreach (array_keys($params) as $key) {
            if (str_starts_with($key, '_')) {
                unset($params[$key]);
            }
        }

        return $params;
    }

    /**
     * @return TwigFilter[]
     */
    #[Override]
    public function getFilters(): array
    {
        return [
            new TwigFilter('percentage', $this->calculator->calculatePercentage(...)),

            new TwigFilter('diff', $this->dateDiff(...)),

            new TwigFilter('md5', 'md5'),

            new TwigFilter('repeat', fn (string $string, int $times): string => str_repeat($string, $times)),
        ];
    }

    /**
     * @return TwigFunction[]
     */
    #[Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('icon', $this->displayIcon(...), ['is_safe' => ['html']]),

            new TwigFunction('app_logo', $this->displayAppLogo(...), ['is_safe' => ['html'], 'needs_environment' => true]),

            new TwigFunction('company_name', function (): string {
                if ($this->companySelector->getCompany() instanceof Ulid) {
                    return $this->systemConfig->get('system/company/company_name') ?? SolidInvoiceCoreBundle::APP_NAME;
                }

                return SolidInvoiceCoreBundle::APP_NAME;
            }),

            new TwigFunction('company_id', $this->companySelector->getCompany(...)),

            new TwigFunction('can_print_pdf', $this->pdfGenerator->canPrintPdf(...)),

            new TwigFunction('is_custom_domain', $this->isCustomDomain(...)),
        ];
    }

    public function isCustomDomain(): bool
    {
        $request = $this->requestStack->getMainRequest();
        $resolved = $request?->attributes->get(HostRoutingListener::REQUEST_ATTR);

        return $resolved instanceof ResolvedHost && $resolved->isCustomDomain();
    }

    /**
     * @throws InvalidArgumentException|ServiceCircularReferenceException|ServiceNotFoundException|LoaderError|SyntaxError
     */
    public function displayAppLogo(Environment $env, string $width = 'auto', ?Company $company = null, bool $showDefault = false, bool $showOnlyAppIcon = false): string
    {
        $logo = null;

        if ($this->installed && ! $showOnlyAppIcon) {
            $logo = $this->companySelector->getCompany() instanceof Ulid ? $this->systemConfig->get('system/company/logo', $company) : null;
        }

        if (null === $logo) {
            if (! $showDefault) {
                return '';
            }

            // No company logo has been uploaded yet: fall back to a clean text-based brand
            // treatment rather than an image asset (temporary, until a final AllDigital
            // Invoice logo/favicon is supplied).
            return $env->createTemplate('<span class="navbar-brand-text fw-bold m-2">{{ text }}</span>')
                ->render(['text' => $showOnlyAppIcon ? 'AI' : SolidInvoiceCoreBundle::APP_NAME]);
        }

        [$type, $logo] = explode('|', $logo);

        return $env->createTemplate('<img src="data:image/{{ type }};base64,{{ logo }}" class="navbar-brand-image m-2" width="' . $width . '"/>')->render(['type' => $type, 'logo' => $logo]);
    }

    /**
     * @param list<string> $options
     */
    public function displayIcon(string $iconName, array $options = []): string
    {
        $class = sprintf('fa fa-%s', $iconName);

        if ([] !== $options) {
            $class .= ' ' . implode(' ', $options);
        }

        return sprintf('<i class="%s"></i>', $class);
    }

    /**
     * Returns a human-readable diff for dates.
     */
    public function dateDiff(DateTime $date): string
    {
        return Carbon::instance($date)->diffForHumans();
    }
}
