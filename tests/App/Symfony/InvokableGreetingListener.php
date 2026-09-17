<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\App\Symfony;

use Modufolio\Appkit\Tests\App\GreetingDispatched;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * The shortest listener there is: an attribute with no arguments on an
 * invokable class. The event comes from __invoke()'s parameter type.
 */
#[AsEventListener]
final class InvokableGreetingListener
{
    public function __invoke(GreetingDispatched $event): void
    {
        $event->seen[] = 'invokable';
    }
}
