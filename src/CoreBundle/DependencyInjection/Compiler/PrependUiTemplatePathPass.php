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

namespace SolidInvoice\CoreBundle\DependencyInjection\Compiler;

use Override;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Gives the app-owned templates/ui/ directory real priority over the vendor
 * solidworx/platform Ui bundle's own templates for the @Ui Twig namespace
 * (used by e.g. @Ui/Security/login.html.twig).
 *
 * @Ui is a CUSTOM Twig namespace, registered by SolidWorxPlatformUiExtension
 * via `prependExtensionConfig('twig', ['paths' => [vendorDir => 'Ui']])`. It
 * is NOT derived from the bundle's name, so Symfony's standard
 * `templates/bundles/<BundleName>/...` override convention never applies to
 * it (that convention only covers a bundle's own default namespace, derived
 * from its class name — `SolidWorxPlatformUiBundle` would be
 * `@SolidWorxPlatformUi`, a namespace nothing in this app actually uses).
 *
 * Adding another `templates/ui -> Ui` entry to `twig.paths` in
 * config/packages/twig.php does NOT win either, even though it looks like
 * "Symfony's supported Twig paths configuration". Symfony's config merging
 * always processes the bundle's prepend()-injected config as the base/left
 * side before the app's own config/packages/* entries (Processor::process()
 * merges the raw configs array in order, and PrototypedArrayNode::mergeValues()
 * appends new keys from the later config after the earlier one's existing
 * keys). So the merged `twig.paths` array always keeps the vendor directory
 * first. TwigExtension::load() then calls
 * FilesystemLoader::addPath() for each entry in that same order, and
 * FilesystemLoader::findTemplate() returns the FIRST match it finds — so the
 * vendor path always wins under plain declarative config, no matter what is
 * added to config/packages/twig.php.
 *
 * The only way to give a path real priority is Twig's own
 * FilesystemLoader::prependPath(), which unshifts it to the FRONT of the
 * namespace's path list (instead of appending it). This pass adds that as an
 * extra method call on the 'twig.loader.native_filesystem' service
 * definition. Compiler passes run after MergeExtensionConfigurationPass
 * (which invokes every extension's load(), including TwigExtension's
 * addPath() calls) — see PassConfig::getPasses(), where the merge pass is
 * unconditionally first — so by the time this pass runs, the definition
 * already has the vendor's addPath() call queued. Appending our
 * prependPath() call after it means it executes LAST at service
 * instantiation time, putting templates/ui/ at the front of the @Ui path
 * list, ahead of the vendor directory, deterministically and regardless of
 * bundle registration order.
 */
final class PrependUiTemplatePathPass implements CompilerPassInterface
{
    #[Override]
    public function process(ContainerBuilder $container): void
    {
        if (! $container->hasDefinition('twig.loader.native_filesystem')) {
            return;
        }

        $container->getDefinition('twig.loader.native_filesystem')
            ->addMethodCall('prependPath', [
                '%kernel.project_dir%/templates/ui',
                'Ui',
            ]);
    }
}
