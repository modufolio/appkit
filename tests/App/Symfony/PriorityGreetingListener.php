<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\App\Symfony;

use Modufolio\Appkit\Tests\App\GreetingDispatched;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * The attribute is repeatable, so one class can take the same event twice at
 * different priorities. Higher runs first.
 */
#[AsEventListener(event: GreetingDispatched::class, method: 'onEarly', priority: 10)]
#[AsEventListener(event: GreetingDispatched::class, method: 'onLate', priority: -10)]
final class PriorityGreetingListener
{
    public function onEarly(GreetingDispatched $event): void
    {
        $event->seen[] = 'early';
    }

    public function onLate(GreetingDispatched $event): void
    {
        $event->seen[] = 'late';
    }
}
