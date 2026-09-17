<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\App\Symfony;

use Modufolio\Appkit\Tests\App\GreetingDispatched;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * The interface still works, autoconfigured into the subscriber tag the same
 * way FrameworkBundle does it.
 */
final class GreetingSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [GreetingDispatched::class => ['onGreeting', 5]];
    }

    public function onGreeting(GreetingDispatched $event): void
    {
        $event->seen[] = 'subscriber';
    }
}
