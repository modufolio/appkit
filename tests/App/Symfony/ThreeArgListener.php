<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\App\Symfony;

use Modufolio\Appkit\Tests\App\GreetingDispatched;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** Symfony calls every listener with ($event, $eventName, $dispatcher). */
final class ThreeArgListener
{
    #[AsEventListener]
    public function onGreeting(GreetingDispatched $event, string $eventName, EventDispatcherInterface $dispatcher): void
    {
        $event->seen[] = 'three:'.(str_contains($eventName, 'GreetingDispatched') ? 'name-ok' : 'name-BAD');
    }
}
