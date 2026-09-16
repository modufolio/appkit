<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\App;

use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * The test app's dispatcher: keeps every event it is handed so a test can
 * assert what the kernel said, and can be told to throw so a test can prove
 * a failing listener changes nothing.
 */
final class RecordingEventDispatcher implements EventDispatcherInterface
{
    private static ?self $instance = null;

    /** @var list<object> */
    public array $events = [];

    public ?\Throwable $throwOnDispatch = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function dispatch(object $event): object
    {
        $this->events[] = $event;

        if (null !== $this->throwOnDispatch) {
            throw $this->throwOnDispatch;
        }

        return $event;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    public function of(string $class): array
    {
        return array_values(array_filter($this->events, static fn (object $e): bool => $e instanceof $class));
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    public function last(string $class): ?object
    {
        $events = $this->of($class);

        return [] === $events ? null : $events[array_key_last($events)];
    }

    public function clear(): void
    {
        $this->events = [];
        $this->throwOnDispatch = null;
    }
}
