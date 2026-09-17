<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Event\Loader;

use Symfony\Component\Config\FileLocatorInterface;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Reads `#[AsEventListener]` and {@see EventSubscriberInterface} off classes,
 * for the `attribute` import type.
 *
 * Every resolution rule follows Symfony's `RegisterListenersPass`, so a
 * listener fires the same way before and after graduating to the container.
 * Output is plain arrays, so a scan can be cached to a PHP file.
 *
 * @phpstan-type ListenerDescriptor array{event: string, listener: array{0: class-string, 1: string}, priority: int}
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
final class AttributeListenerLoader extends Loader
{
    public function __construct(private readonly FileLocatorInterface $locator)
    {
        parent::__construct();
    }

    /**
     * A directory of classes, or one class. Both are read for the attribute
     * and for the interface, as `autoconfigure()` does at the next step.
     *
     * @return list<ListenerDescriptor>
     */
    public function load(mixed $resource, ?string $type = null): array
    {
        if (!\is_string($resource)) {
            throw new \InvalidArgumentException(sprintf('A listener resource must be a directory or a class name, %s given.', get_debug_type($resource)));
        }

        $classes = class_exists($resource)
            ? [$resource]
            : $this->classesIn($this->locate($resource));

        $listeners = [];

        foreach ($classes as $class) {
            foreach ($this->fromClass($class) as $listener) {
                $listeners[] = $listener;
            }

            if (is_a($class, EventSubscriberInterface::class, true)) {
                foreach ($this->fromSubscriber($class) as $listener) {
                    $listeners[] = $listener;
                }
            }
        }

        return $listeners;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return 'attribute' === $type;
    }

    /**
     * One class, attribute on the class itself and on each method.
     *
     * @param class-string $class
     *
     * @return list<ListenerDescriptor>
     */
    public function fromClass(string $class): array
    {
        if (!class_exists($class)) {
            throw new \InvalidArgumentException(sprintf('Listener class "%s" does not exist.', $class));
        }

        $reflection = new \ReflectionClass($class);

        if ($reflection->isAbstract()) {
            throw new \InvalidArgumentException(sprintf('Attributes on "%s" cannot be read as it is abstract.', $class));
        }

        $listeners = [];

        foreach ($reflection->getAttributes(AsEventListener::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
            foreach ($this->describe($reflection, $attribute->newInstance(), null) as $listener) {
                $listeners[] = $listener;
            }
        }

        foreach ($reflection->getMethods() as $method) {
            foreach ($method->getAttributes(AsEventListener::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                foreach ($this->describe($reflection, $attribute->newInstance(), $method) as $listener) {
                    $listeners[] = $listener;
                }
            }
        }

        return $listeners;
    }

    /**
     * Read statically, so the subscriber is not built until its event fires.
     *
     * @param class-string $class
     *
     * @return list<ListenerDescriptor>
     */
    public function fromSubscriber(string $class): array
    {
        if (!is_a($class, EventSubscriberInterface::class, true)) {
            throw new \InvalidArgumentException(sprintf('Subscriber "%s" must implement "%s".', $class, EventSubscriberInterface::class));
        }

        $listeners = [];

        // The three shapes EventDispatcher::addSubscriber() accepts.
        foreach ($class::getSubscribedEvents() as $event => $params) {
            if (\is_string($params)) {
                $listeners[] = ['event' => $event, 'listener' => [$class, $params], 'priority' => 0];

                continue;
            }

            if (\is_string($params[0] ?? null)) {
                /* @var array{0: string, 1?: int} $params */
                $listeners[] = ['event' => $event, 'listener' => [$class, $params[0]], 'priority' => (int) ($params[1] ?? 0)];

                continue;
            }

            /** @var list<array{0: string, 1?: int}> $params */
            foreach ($params as $listener) {
                $listeners[] = ['event' => $event, 'listener' => [$class, $listener[0]], 'priority' => (int) ($listener[1] ?? 0)];
            }
        }

        return $listeners;
    }

    /**
     * The event a `[class, method]` pair takes, inferred from its signature.
     * A union type has no single answer, so the first member wins.
     *
     * @param class-string $class
     */
    public function eventFor(string $class, string $method): string
    {
        return $this->eventsFromSignature(new \ReflectionClass($class), $method)[0];
    }

    /**
     * One attribute to one or more descriptors.
     *
     * @param \ReflectionClass<object> $reflection
     *
     * @return list<ListenerDescriptor>
     */
    private function describe(\ReflectionClass $reflection, AsEventListener $attribute, ?\ReflectionMethod $on): array
    {
        if (null !== $attribute->dispatcher) {
            throw new \LogicException(sprintf('#[AsEventListener] on "%s" names a dispatcher, which needs the Symfony container — the kernel has one.', $reflection->getName()));
        }

        if (null !== $on && null !== $attribute->method) {
            throw new \LogicException(sprintf('#[AsEventListener] on method "%s::%s()" cannot also name a method.', $reflection->getName(), $on->getName()));
        }

        $method = $on?->getName() ?? $attribute->method;

        if (null === $attribute->event) {
            $method ??= '__invoke';
            $events = $this->eventsFromSignature($reflection, $method);
        } else {
            $events = [$attribute->event];
            $method ??= $this->methodForEvent($reflection, $attribute->event);
        }

        $listeners = [];
        foreach ($events as $event) {
            $listeners[] = ['event' => $event, 'listener' => [$reflection->getName(), $method], 'priority' => $attribute->priority];
        }

        return $listeners;
    }

    /**
     * The event(s) from the first parameter; a union listens to each member.
     *
     * @param \ReflectionClass<object> $reflection
     *
     * @return list<string>
     */
    private function eventsFromSignature(\ReflectionClass $reflection, string $method): array
    {
        if (!$reflection->hasMethod($method)
            || 1 > ($m = $reflection->getMethod($method))->getNumberOfParameters()
            || !(($type = $m->getParameters()[0]->getType()) instanceof \ReflectionNamedType || $type instanceof \ReflectionUnionType)
        ) {
            throw new \InvalidArgumentException(sprintf('Listener "%s::%s()" must name an event in #[AsEventListener] or type-hint one as its first argument.', $reflection->getName(), $method));
        }

        $names = [];
        foreach ($type instanceof \ReflectionUnionType ? $type->getTypes() : [$type] as $candidate) {
            if (!$candidate instanceof \ReflectionNamedType
                || $candidate->isBuiltin()
                || Event::class === ($name = $candidate->getName())
            ) {
                continue;
            }

            $names[] = $name;
        }

        if ([] === $names) {
            throw new \InvalidArgumentException(sprintf('Listener "%s::%s()" must name an event in #[AsEventListener] or type-hint one as its first argument.', $reflection->getName(), $method));
        }

        return $names;
    }

    /**
     * `on<EventName>`, falling back to `__invoke` — Symfony's derivation.
     *
     * @param \ReflectionClass<object> $reflection
     */
    private function methodForEvent(\ReflectionClass $reflection, string $event): string
    {
        $method = 'on'.preg_replace_callback(
            ['/(?<=\b|_)[a-z]/i', '/[^a-z0-9]/i'],
            static fn (array $matches): string => strtoupper($matches[0]),
            $event,
        );
        $method = (string) preg_replace('/[^a-z0-9]/i', '', $method);

        if ($reflection->hasMethod($method)) {
            return $method;
        }

        if ($reflection->hasMethod('__invoke')) {
            return '__invoke';
        }

        throw new \InvalidArgumentException(sprintf('None of the "%s" or "__invoke" methods exist on listener "%s". Name the method in #[AsEventListener].', $method, $reflection->getName()));
    }

    /** Through the same locator the route loaders use. */
    private function locate(string $path): string
    {
        $resolved = $this->locator->locate($path, null, true);

        if (!is_dir($resolved)) {
            throw new \InvalidArgumentException(sprintf('Listener resource "%s" is neither a class nor a directory.', $path));
        }

        return $resolved;
    }

    /**
     * Every class under a directory, named from the file rather than its
     * path — so PSR-4 prefixes need not match the layout.
     *
     * @return list<class-string>
     */
    private function classesIn(string $dir): array
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );

        $classes = [];
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }

            if (null !== $class = $this->classIn($file->getPathname())) {
                $classes[] = $class;
            }
        }

        // The filesystem does not promise an order; listener order must not
        // depend on one, and a cached scan should hash the same every time.
        sort($classes);

        return $classes;
    }

    /**
     * The class a file declares, or null. Tokenised, so nothing is executed.
     *
     * @return class-string|null
     */
    private function classIn(string $file): ?string
    {
        $tokens = token_get_all((string) file_get_contents($file));
        $namespace = '';
        $count = \count($tokens);

        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];

            if (!\is_array($token)) {
                continue;
            }

            if (\T_NAMESPACE === $token[0]) {
                $namespace = '';
                for ($j = $i + 1; $j < $count; ++$j) {
                    if (\is_array($tokens[$j]) && \in_array($tokens[$j][0], [\T_STRING, \T_NAME_QUALIFIED], true)) {
                        $namespace = $tokens[$j][1];
                        break;
                    }
                    if (';' === $tokens[$j] || '{' === $tokens[$j]) {
                        break;
                    }
                }
                continue;
            }

            // An anonymous class, an enum case named "class", or "::class" is
            // not a declaration; only T_CLASS preceded by nothing meaningful.
            if (\T_CLASS === $token[0]) {
                for ($j = $i + 1; $j < $count; ++$j) {
                    if (\is_array($tokens[$j]) && \in_array($tokens[$j][0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                        continue;
                    }

                    if (\is_array($tokens[$j]) && \T_STRING === $tokens[$j][0]) {
                        /** @var class-string $fqcn */
                        $fqcn = '' === $namespace ? $tokens[$j][1] : $namespace.'\\'.$tokens[$j][1];

                        return class_exists($fqcn) ? $fqcn : null;
                    }

                    break;
                }
            }
        }

        return null;
    }
}
