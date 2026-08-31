<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Module;

/**
 * Single source of truth for the registered modules.
 *
 * Loads `config/modules.php` once per base directory. Entries may be either a
 * bare class name or a `class => config` pair:
 *
 * ```php
 * return [
 *     \Modufolio\User\UserModule::class,                      // no config
 *     \Acme\Blog\BlogModule::class => ['per_page' => 20],     // with config
 * ];
 * ```
 *
 * The kernel, `config/routes.php` and `config/doctrine.php` all query this
 * registry so module services, routes, entities and migrations stay in
 * lock-step. Modules are instantiated with no constructor arguments.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
final class ModuleRegistry
{
    /** @var array<string, list<ModuleInterface>> keyed by base directory */
    private static array $modules = [];

    /** @var array<string, array<string, array<string, mixed>>> config by baseDir, then module name */
    private static array $config = [];

    /**
     * @return list<ModuleInterface>
     */
    public static function modules(string $baseDir): array
    {
        return self::$modules[$baseDir] ?? self::load($baseDir, $baseDir.'/config/modules.php');
    }

    /**
     * Load a specific manifest file for a base directory. Only needed when the
     * manifest lives outside the conventional config/modules.php location
     * (e.g. a test app); afterwards every modules($baseDir) lookup returns the
     * same instances.
     *
     * @return list<ModuleInterface>
     */
    public static function load(string $baseDir, string $file): array
    {
        if (isset(self::$modules[$baseDir])) {
            return self::$modules[$baseDir];
        }

        $entries = is_file($file) ? (array) require $file : [];

        $modules = [];
        $config = [];
        $errors = [];
        $seen = [];

        foreach ($entries as $key => $value) {
            // Normalize the bare-class and class => config forms.
            if (is_int($key)) {
                $class = $value;
                $moduleConfig = [];
            } else {
                $class = $key;
                $moduleConfig = is_array($value) ? $value : [];
            }

            if (!is_string($class) || !class_exists($class)) {
                $errors[] = sprintf('Module class "%s" does not exist.', (string) $class);
                continue;
            }

            $module = new $class();
            if (!$module instanceof ModuleInterface) {
                $errors[] = sprintf('Module "%s" must implement %s.', $class, ModuleInterface::class);
                continue;
            }

            // Names key the config and the module.<name> parameter — a silent
            // collision would merge two modules' config. Fail loudly instead.
            if (\array_key_exists($module->name(), $config)) {
                $errors[] = sprintf('Duplicate module name "%s" (class "%s").', $module->name(), $class);
                continue;
            }

            $modules[] = $module;
            $config[$module->name()] = $moduleConfig;
            $seen[$class] = true;
        }

        // Declared dependencies are verified, never auto-loaded or reordered:
        // the manifest order stays authoritative, this only refuses manifests
        // that contradict it.
        foreach ($modules as $module) {
            foreach ($module->requires() as $required) {
                if (!isset($seen[$required])) {
                    $errors[] = sprintf('Module "%s" requires "%s", which is not listed in the manifest.', $module::class, $required);
                } elseif (!self::listedBefore($modules, $required, $module)) {
                    $errors[] = sprintf('Module "%s" requires "%s", which must be listed before it in the manifest.', $module::class, $required);
                }
            }
        }

        // One aggregate failure: fix the whole manifest in one round trip
        // instead of replaying boot once per mistake.
        if ($errors !== []) {
            throw new \LogicException(sprintf(
                "Invalid module manifest \"%s\":\n - %s",
                $file,
                implode("\n - ", $errors),
            ));
        }

        self::$config[$baseDir] = $config;

        return self::$modules[$baseDir] = $modules;
    }

    /**
     * @param list<ModuleInterface> $modules
     */
    private static function listedBefore(array $modules, string $requiredClass, ModuleInterface $module): bool
    {
        foreach ($modules as $candidate) {
            if ($candidate::class === $requiredClass) {
                return true;
            }
            if ($candidate === $module) {
                return false;
            }
        }

        return false;
    }

    /** @internal Reset the registry cache — for tests only. */
    public static function reset(): void
    {
        self::$modules = [];
        self::$config = [];
    }

    /**
     * Configuration declared for a module in config/modules.php (before the
     * module merges its own defaults).
     *
     * @return array<string, mixed>
     */
    public static function configFor(string $baseDir, ModuleInterface $module): array
    {
        self::modules($baseDir);

        return self::$config[$baseDir][$module->name()] ?? [];
    }

    /**
     * @return list<string>
     */
    public static function entityPaths(string $baseDir): array
    {
        return self::collect($baseDir, static fn (ModuleInterface $m): array => $m->entityPaths());
    }

    /**
     * @return list<string>
     */
    public static function controllerPaths(string $baseDir): array
    {
        return self::collect($baseDir, static fn (ModuleInterface $m): array => $m->controllerPaths());
    }

    /**
     * @return list<string>
     */
    public static function migrationPaths(string $baseDir): array
    {
        return self::collect($baseDir, static fn (ModuleInterface $m): array => $m->migrationPaths());
    }

    /**
     * Module migration directories keyed by their namespace (the module's own
     * namespace + "\Migrations"), ready to merge into Doctrine Migrations'
     * migrations_paths.
     *
     * @return array<string, string>
     */
    public static function migrationNamespaces(string $baseDir): array
    {
        $map = [];
        foreach (self::modules($baseDir) as $module) {
            $paths = $module->migrationPaths();
            if ([] === $paths) {
                continue;
            }
            $namespace = (new \ReflectionClass($module))->getNamespaceName().'\\Migrations';
            // A module conventionally exposes a single Migrations directory.
            $map[$namespace] = $paths[0];
        }

        return $map;
    }

    /**
     * @return list<string>
     */
    public static function templatePaths(string $baseDir): array
    {
        return self::collect($baseDir, static fn (ModuleInterface $m): array => $m->templatePaths());
    }

    /**
     * @return list<string>
     */
    public static function translationPaths(string $baseDir): array
    {
        return self::collect($baseDir, static fn (ModuleInterface $m): array => $m->translationPaths());
    }

    /**
     * @param callable(ModuleInterface): list<string> $extractor
     *
     * @return list<string>
     */
    private static function collect(string $baseDir, callable $extractor): array
    {
        $paths = [];
        foreach (self::modules($baseDir) as $module) {
            foreach ($extractor($module) as $path) {
                $paths[] = $path;
            }
        }

        return $paths;
    }
}
