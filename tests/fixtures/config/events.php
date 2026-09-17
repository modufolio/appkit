<?php

declare(strict_types=1);

use Modufolio\Appkit\Tests\App\Symfony\GreetingSubscriber;
use Modufolio\Appkit\Tests\App\Symfony\InvokableGreetingListener;
use Modufolio\Appkit\Tests\App\Symfony\LoginAuditListener;
use Modufolio\Appkit\Tests\App\Symfony\LoginAuditRecorder;
use Modufolio\Appkit\Tests\App\Symfony\MethodGreetingListener;
use Modufolio\Appkit\Tests\App\Symfony\PriorityGreetingListener;

/*
 * The same listeners the Symfony container autoconfigures, imported instead
 * of discovered — so a test can assert that step 1 and step 2 wire the same
 * classes in the same order.
 *
 * Shaped like config/routes.php: a type names the loader, and which types
 * exist is whatever the application registered.
 */
return [
    'greeting_early' => [
        'event' => Modufolio\Appkit\Tests\App\GreetingDispatched::class,
        'listener' => [PriorityGreetingListener::class, 'onEarly'],
        'priority' => 10,
    ],
    'greeting_late' => [
        'event' => Modufolio\Appkit\Tests\App\GreetingDispatched::class,
        'listener' => [PriorityGreetingListener::class, 'onLate'],
        'priority' => -10,
    ],
    'greeting_subscriber' => [
        'listener' => GreetingSubscriber::class,
    ],
    InvokableGreetingListener::class,
    MethodGreetingListener::class,
    LoginAuditListener::class,
    LoginAuditRecorder::class,
];
