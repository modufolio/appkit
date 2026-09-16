<?php

declare(strict_types=1);

namespace Modufolio\Appkit\DependencyInjection;

/**
 * A set of service definitions a package ships: ids mapped to factories, in
 * the shape a PSR-11 container consumes directly.
 *
 * ```php
 * namespace Acme\Panel;
 *
 * final class PanelDefinitions implements DefinitionsInterface
 * {
 *     public function getDefinitions(): array
 *     {
 *         return [
 *             FormResolver::class => fn (ContainerInterface $c) => new FormResolver($c->get(EntityManagerInterface::class)),
 *             ChangePublisher::class => NullChangePublisher::class,   // an alias: get() resolves the named id
 *         ];
 *     }
 * }
 * ```
 *
 * The factory closure receives the container — here the application, which
 * is one — so a set written against `ContainerInterface` loads into the
 * kernel with {@see ServiceConfigurator::load()}, into a PHP-DI container as
 * it is, and into any other PSR-11 container that takes closures. A package
 * that wants to run on more than one host writes its wiring once, here, and
 * keeps framework-specific glue to the ids only that host can answer.
 *
 * A set is also a manifest entry: `config/modules.php` may list a
 * definitions class where it lists a module, for a package that contributes
 * services and nothing else — no entities, migrations, templates or
 * lifecycle. See {@see \Modufolio\Appkit\Module\DefinitionsModule}.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
interface DefinitionsInterface
{
    /**
     * @return array<string, \Closure|string> id => factory closure (receiving the
     *                                          container), or the id it aliases
     */
    public function getDefinitions(): array;
}
