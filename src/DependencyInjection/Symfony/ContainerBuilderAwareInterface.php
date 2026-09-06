<?php

declare(strict_types=1);

namespace Modufolio\Appkit\DependencyInjection\Symfony;

use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * A module that contributes to the Symfony container programmatically — the
 * bundle half of a module, next to the services() half that feeds the
 * kernel's own container.
 *
 * Optional, and deliberately separate from ModuleInterface so the module
 * contract and AbstractModule stay free of symfony/dependency-injection. A
 * module's `config/container.php` needs no interface at all: ContainerFactory
 * loads it by convention. Implement this one for definitions that depend on
 * the merged config, or to register compiler passes. Only consulted when the
 * application opted in with {@see \Modufolio\Appkit\Core\Kernel::configureContainer()}.
 *
 * Phase contract: build() runs during Kernel::boot(), after every module's
 * services() and before any module's boot(). Register definitions only —
 * the container is compiled after the last module's build() returns.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
interface ContainerBuilderAwareInterface
{
    /**
     * @param array<string, mixed> $config the module's merged configuration
     */
    public function build(ContainerBuilder $container, array $config): void;
}
