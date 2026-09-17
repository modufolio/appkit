<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Event\Loader;

use Symfony\Component\Config\FileLocatorInterface;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Listeners declared as an array, for the `array` import type — the shape
 * {@see \Modufolio\Appkit\Routing\Loader\ArrayRouteLoader} uses for routes,
 * with `'listener' => [Class::class, 'method']` where a route says
 * `'controller'`. It is also the shape the generated cache is written in, so
 * a map the framework wrote can be pasted back as a declaration.
 *
 * See [Events](../../../docs/events.md#listeners-the-kernel-wires).
 *
 * @phpstan-type ListenerDescriptor array{event: string, listener: array{0: class-string, 1: string}, priority: int}
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
class ArrayListenerLoader extends Loader
{
    public function __construct(
        private readonly FileLocatorInterface $locator,
        private readonly AttributeListenerLoader $classes,
    ) {
        parent::__construct();
    }

    /**
     * @param array<array-key, mixed>|string $resource an array, or a file that returns one
     *
     * @return list<ListenerDescriptor>
     */
    public function load(mixed $resource, ?string $type = null): array
    {
        $declarations = \is_string($resource) ? $this->include($resource) : $resource;

        if (!\is_array($declarations)) {
            throw new \InvalidArgumentException(sprintf('A listener array must be an array, %s given.', get_debug_type($declarations)));
        }

        $listeners = [];

        foreach ($declarations as $name => $declaration) {
            // Shorthand: a bare class name, keyed or not.
            if (\is_string($declaration)) {
                foreach ($this->fromClass($declaration, $name) as $listener) {
                    $listeners[] = $listener;
                }

                continue;
            }

            if (!\is_array($declaration)) {
                throw new \InvalidArgumentException(sprintf('Listener "%s" must be a class name or a definition array, %s given.', $name, get_debug_type($declaration)));
            }

            if (!isset($declaration['listener'])) {
                throw new \InvalidArgumentException(sprintf('Listener "%s" is missing its "listener" key: give it [%s, \'method\'] or a class name.', $name, 'Some\\Listener::class'));
            }

            $listener = $declaration['listener'];

            // A class instead of a pair: a declared event or priority still
            // applies to whatever its attributes declare.
            if (\is_string($listener)) {
                foreach ($this->fromClass($listener, $name) as $found) {
                    $listeners[] = [
                        'event' => isset($declaration['event']) ? (string) $declaration['event'] : $found['event'],
                        'listener' => $found['listener'],
                        'priority' => isset($declaration['priority']) ? (int) $declaration['priority'] : $found['priority'],
                    ];
                }

                continue;
            }

            $listeners[] = $this->describe((string) $name, $declaration, $listener);
        }

        return $listeners;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return 'array' === $type;
    }

    /**
     * A class entry: whatever its attributes and its interface declare.
     *
     * @return list<ListenerDescriptor>
     */
    private function fromClass(string $class, int|string $name): array
    {
        if (!class_exists($class)) {
            throw new \InvalidArgumentException(sprintf('Listener class "%s" does not exist.', $class));
        }

        $listeners = $this->classes->fromClass($class);

        if (is_a($class, EventSubscriberInterface::class, true)) {
            foreach ($this->classes->fromSubscriber($class) as $listener) {
                $listeners[] = $listener;
            }
        }

        if ([] === $listeners) {
            throw new \InvalidArgumentException(sprintf('Listener "%s" (%s) declares nothing: give it an #[AsEventListener] attribute, implement %s, or name its event and method in the definition.', $class, \is_int($name) ? '#'.$name : '"'.$name.'"', EventSubscriberInterface::class));
        }

        return $listeners;
    }

    /**
     * A `'listener' => [Class::class, 'method']` definition.
     *
     * @param array<string, mixed> $declaration
     *
     * @return ListenerDescriptor
     */
    private function describe(string $name, array $declaration, mixed $listener): array
    {
        if (!\is_array($listener) || !isset($listener[0], $listener[1]) || !\is_string($listener[0]) || !\is_string($listener[1])) {
            throw new \InvalidArgumentException(sprintf('Listener "%s" must name [class, method] or a class.', $name));
        }

        [$class, $method] = $listener;

        if (!class_exists($class)) {
            throw new \InvalidArgumentException(sprintf('Listener class "%s" (%s) does not exist.', $class, $name));
        }

        if (!method_exists($class, $method)) {
            throw new \InvalidArgumentException(sprintf('Listener "%s" (%s) has no method "%s()".', $class, $name, $method));
        }

        // An omitted event comes from the first argument, as the attribute does.
        $event = isset($declaration['event'])
            ? (string) $declaration['event']
            : $this->classes->eventFor($class, $method);

        return ['event' => $event, 'listener' => [$class, $method], 'priority' => isset($declaration['priority']) ? (int) $declaration['priority'] : 0];
    }

    private function include(string $file): mixed
    {
        $resolved = $this->locator->locate($file);

        if (!is_file($resolved)) {
            throw new \InvalidArgumentException(sprintf('Listener array file "%s" does not exist.', $file));
        }

        return include $resolved;
    }
}
