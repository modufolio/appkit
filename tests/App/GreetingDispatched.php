<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\App;

/**
 * A plain PSR-14 event for the container's listener wiring. It collects what
 * ran into itself, so a test reads both the fact and the order without any
 * static state to reset between cases.
 *
 * Deliberately outside App\Symfony\, which container.php load()s: an event is
 * not a service, and autowiring its $name would fail at compile time.
 */
final class GreetingDispatched
{
    /** @var list<string> */
    public array $seen = [];

    public function __construct(public readonly string $name)
    {
    }
}
