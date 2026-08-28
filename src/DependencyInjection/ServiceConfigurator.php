<?php

declare(strict_types=1);

namespace Modufolio\Appkit\DependencyInjection;

/**
 * Service Configurator.
 *
 * Fluent API for declaring application services in one place —
 * `config/services.php` — replacing the split between `interfaces.php`
 * (kernel-bound closures) and `factories.php` (container-parameter closures).
 *
 * Every factory closure receives the application as its only argument, so
 * definitions are portable and explicit — no reliance on `$this` binding at
 * `require` time. Closures that need nothing may omit the parameter.
 *
 * The kernel pre-wires its own core services (router, session, entity manager,
 * CSRF, serializer, …), so this file only declares what the application adds —
 * and any entry here overrides the kernel default for the same id.
 *
 * Usage:
 * ```php
 * return function (ServiceConfigurator $services): void {
 *     $services
 *         ->set(Mailer::class, fn (App $app) => new Mailer(
 *             $app->entityManager(),
 *             env('MAIL_DSN'),
 *         ))
 *         // Resolved once per request, then reused (cleared by reset()):
 *         ->shared(JsonApiRegistry::class, fn (App $app) => new JsonApiRegistry(
 *             $app->baseDir . '/config/json_api.php',
 *         ))
 *         // The panel asks for SharedPropsInterface; this app answers with DefaultProps:
 *         ->alias(SharedPropsInterface::class, DefaultProps::class);
 * };
 * ```
 *
 * `set()` runs the factory on every `get()` — the same semantics
 * `interfaces.php` and `factories.php` always had. `shared()` caches the first
 * resolution in the kernel's per-request instance table, so it lives exactly as
 * long as one request; use it for services that are expensive to build or must
 * be the same object everywhere within a request. Never mark a service shared
 * when callers expect a fresh object each time (a Response, for example).
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
final class ServiceConfigurator
{
    /** @var array<string, \Closure> */
    public array $definitions = [];

    /** @var array<string, true> */
    public array $shared = [];

    /**
     * Register a service factory. The closure receives the application and is
     * invoked on every `get($id)`.
     *
     * @param class-string $id
     */
    public function set(string $id, \Closure $factory): self
    {
        $this->definitions[$id] = $factory;
        unset($this->shared[$id]);

        return $this;
    }

    /**
     * Register a request-scoped singleton: the factory runs once, the result is
     * cached in the kernel's instance table and cleared by `reset()`.
     *
     * @param class-string $id
     */
    public function shared(string $id, \Closure $factory): self
    {
        $this->definitions[$id] = $factory;
        $this->shared[$id] = true;

        return $this;
    }

    /**
     * Point one id at another: `get($alias)` resolves `$target` through the
     * container. Use it to answer a package's interface with an application
     * service without repeating its wiring.
     *
     * @param class-string $alias
     * @param class-string $target
     */
    public function alias(string $alias, string $target): self
    {
        if ($alias === $target) {
            throw new \LogicException(sprintf('Service "%s" cannot be an alias of itself.', $alias));
        }

        return $this->set($alias, fn (\Psr\Container\ContainerInterface $app) => $app->get($target));
    }
}
