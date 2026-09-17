<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\App\Symfony;

use Modufolio\Appkit\Tests\App\GreetingDispatched;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * On a method the attribute names the method by being there; the event still
 * comes from the parameter type.
 */
final class MethodGreetingListener
{
    #[AsEventListener]
    public function onGreeting(GreetingDispatched $event): void
    {
        $event->seen[] = 'method';
    }
}
