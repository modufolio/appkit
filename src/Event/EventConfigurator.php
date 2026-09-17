<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Event;

/**
 * Declares listeners for the kernel's own dispatcher, the way
 * `config/routes.php` declares routes.
 *
 * See [Events](../../docs/events.md#listeners-the-kernel-wires).
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
final class EventConfigurator
{
    /** @var list<array{resource: mixed, type: ?string}> */
    private array $imports = [];

    /** @var list<array{event: string, listener: callable, priority: int}> */
    private array $callables = [];

    /**
     * @param mixed       $resource a directory, a class, or a file — whatever
     *                              the loader for $type accepts
     * @param string|null $type     names the loader; `attribute` and `array`
     *                              ship, an application can register more
     */
    public function import(mixed $resource, ?string $type = null): self
    {
        $this->imports[] = ['resource' => $resource, 'type' => $type];

        return $this;
    }

    /**
     * Listen with a callable rather than a class. Not an import — a closure
     * cannot come out of a loader — so it is neither cached nor lazy.
     *
     * @param class-string|string $event    an event class, or a plain name
     * @param int                 $priority higher runs first
     */
    public function on(string $event, callable $listener, int $priority = 0): self
    {
        $this->callables[] = ['event' => $event, 'listener' => $listener, 'priority' => $priority];

        return $this;
    }

    /** @return list<array{resource: mixed, type: ?string}> */
    public function getImports(): array
    {
        return $this->imports;
    }

    /** @return list<array{event: string, listener: callable, priority: int}> */
    public function getCallables(): array
    {
        return $this->callables;
    }
}
