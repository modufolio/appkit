<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Module;

use Modufolio\Appkit\Core\AppInterface;
use Modufolio\Appkit\DependencyInjection\DefinitionsInterface;
use Modufolio\Appkit\DependencyInjection\ServiceConfigurator;

/**
 * A definition set listed in config/modules.php, carried as a module so it
 * takes the module contract's place in the override order and the manifest's
 * validation — duplicate names, `requires()`, environments — without
 * implementing a contract it has no use for.
 *
 * ```php
 * // config/modules.php
 * return [
 *     \Acme\Panel\PanelDefinitions::class,   // a DefinitionsInterface
 *     \Acme\Blog\BlogModule::class,          // a ModuleInterface
 * ];
 * ```
 *
 * {@see ModuleRegistry} wraps the set on load. Its name is the class short
 * name minus a trailing "Definitions", lowercased, like a module's; its path
 * is the class's directory, which is what `modules:list` shows. It contributes
 * services only: no controllers, no paths, no lifecycle. A set that needs any
 * of those is a module, and a module that wants the array shape loads its set
 * with {@see ServiceConfigurator::load()} from `loadServices()`.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
final class DefinitionsModule implements ModuleInterface
{
    public function __construct(private readonly DefinitionsInterface $definitions)
    {
    }

    public function definitions(): DefinitionsInterface
    {
        return $this->definitions;
    }

    public function name(): string
    {
        $short = (new \ReflectionClass($this->definitions))->getShortName();

        return strtolower(preg_replace('/Definitions$/', '', $short) ?: $short);
    }

    public function path(): string
    {
        $file = (new \ReflectionClass($this->definitions))->getFileName();

        return \is_string($file) ? \dirname($file) : '';
    }

    public function services(ServiceConfigurator $services, array $config): void
    {
        if ([] !== $config) {
            throw new \LogicException(sprintf('%s takes no configuration; %s declared in config/modules.php.', $this->definitions::class, json_encode(array_keys($config))));
        }

        $services->load($this->definitions);
    }

    public function requires(): array
    {
        return [];
    }

    public function config(): array
    {
        return [];
    }

    public function controllers(): array
    {
        return [];
    }

    public function boot(AppInterface $app): void
    {
    }

    public function reset(): void
    {
    }

    public function entityPaths(): array
    {
        return [];
    }

    public function controllerPaths(): array
    {
        return [];
    }

    public function migrationPaths(): array
    {
        return [];
    }

    public function templatePaths(): array
    {
        return [];
    }

    public function translationPaths(): array
    {
        return [];
    }
}
