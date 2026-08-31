<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Module;

use Modufolio\Appkit\Core\AppInterface;
use Modufolio\Appkit\DependencyInjection\ServiceConfigurator;

/**
 * Convention-based base class for modules.
 *
 * Everything is derived from the module's location on disk, so a typical
 * module is an empty subclass. Override any method to opt out of a convention.
 *
 *   - name():             class short name minus a trailing "Module", lowercased
 *   - path():             the directory the module class lives in
 *   - services():         merges defaultConfig() under the declared config,
 *                         loads <path>/config/services.php, then calls
 *                         loadServices() for programmatic wiring
 *   - controllers():      loads <path>/config/controllers.php    if present
 *   - boot()/reset():     no-ops by default
 *   - entityPaths():      [<path>/Entity]        if present
 *   - controllerPaths():  [<path>/Controller]    if present
 *   - migrationPaths():   [<path>/Migrations]    if present
 *   - templatePaths():    [<path>/templates]     if present
 *   - translationPaths(): [<path>/translations]  if present
 *
 * A module's `config/services.php` returns a closure like the application's,
 * with the merged module config as a second argument:
 *
 * ```php
 * return function (ServiceConfigurator $services, array $config): void {
 *     $services->set(Mailer::class, fn (App $app) => new Mailer($config['dsn']));
 * };
 * ```
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
abstract class AbstractModule implements ModuleInterface
{
    private ?string $path = null;

    /** @var array<string, mixed>|null Merged config, set once services() ran */
    private ?array $config = null;

    public function name(): string
    {
        $short = (new \ReflectionClass(static::class))->getShortName();

        return strtolower(preg_replace('/Module$/', '', $short) ?: $short);
    }

    public function path(): string
    {
        if (null !== $this->path) {
            return $this->path;
        }

        $file = (new \ReflectionClass(static::class))->getFileName();
        if (false === $file) {
            throw new \LogicException(sprintf('Cannot resolve the path of module "%s".', static::class));
        }

        return $this->path = \dirname($file);
    }

    public function services(ServiceConfigurator $services, array $config): void
    {
        $this->validateConfig($config);
        $config = $this->config = array_replace_recursive($this->defaultConfig(), $config);

        $file = $this->path().'/config/services.php';
        if (is_file($file)) {
            $closure = require $file;
            if (!$closure instanceof \Closure) {
                throw new \LogicException(sprintf('"%s" must return a closure accepting (ServiceConfigurator $services, array $config).', $file));
            }
            $closure($services, $config);
        }

        $this->loadServices($services, $config);
    }

    public function config(): array
    {
        return $this->config ?? $this->defaultConfig();
    }

    public function controllers(): array
    {
        $file = $this->path().'/config/controllers.php';

        return is_file($file) ? (array) require $file : [];
    }

    public function requires(): array
    {
        return [];
    }

    public function boot(AppInterface $app): void
    {
        // no-op
    }

    public function reset(): void
    {
        // no-op
    }

    /**
     * Default configuration, merged under the values from config/modules.php.
     *
     * @return array<string, mixed>
     */
    protected function defaultConfig(): array
    {
        return [];
    }

    /**
     * Reject declared config keys the module does not know: merge-under
     * semantics would otherwise accept a typo'd key silently. Enforced only
     * when defaultConfig() declares keys — a module with no defaults accepts
     * anything. Override to accept free-form config alongside defaults.
     *
     * @param array<string, mixed> $declared
     */
    protected function validateConfig(array $declared): void
    {
        $known = $this->defaultConfig();
        if ($known === []) {
            return;
        }

        $unknown = array_diff_key($declared, $known);
        if ($unknown !== []) {
            throw new \LogicException(sprintf(
                'Unknown config key(s) [%s] declared for module "%s" in config/modules.php — known keys: [%s].',
                implode(', ', array_keys($unknown)),
                $this->name(),
                implode(', ', array_keys($known)),
            ));
        }
    }

    /**
     * Programmatic, config-aware wiring hook. Runs after the module's
     * services.php is loaded. Override to register definitions that depend on
     * $config or need PHP logic a config file shouldn't hold.
     *
     * @param array<string, mixed> $config
     */
    protected function loadServices(ServiceConfigurator $services, array $config): void
    {
        // no-op
    }

    public function entityPaths(): array
    {
        return $this->existingDir('Entity');
    }

    public function controllerPaths(): array
    {
        return $this->existingDir('Controller');
    }

    public function migrationPaths(): array
    {
        // Capitalized to match the PSR-4 namespace segment "\Migrations".
        return $this->existingDir('Migrations');
    }

    public function templatePaths(): array
    {
        return $this->existingDir('templates');
    }

    public function translationPaths(): array
    {
        return $this->existingDir('translations');
    }

    /**
     * @return list<string>
     */
    private function existingDir(string $sub): array
    {
        $dir = $this->path().'/'.$sub;

        return is_dir($dir) ? [$dir] : [];
    }
}
