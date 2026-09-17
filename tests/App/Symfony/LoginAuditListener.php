<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\App\Symfony;

use Modufolio\Appkit\Event\Security\UserLoggedInEvent;
use Modufolio\Appkit\Event\Security\UserLoggedOutEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * A listener on the framework's own events, wired by nothing but its
 * attributes. Records statically: the container is reset between requests,
 * so an instance would not survive the login it is watching.
 */
final class LoginAuditListener
{
    /** @var list<string> */
    public static array $seen = [];

    #[AsEventListener]
    public function onLogin(UserLoggedInEvent $event): void
    {
        self::$seen[] = 'in:'.$event->userIdentifier.':'.$event->firewallName;
    }

    #[AsEventListener]
    public function onLogout(UserLoggedOutEvent $event): void
    {
        self::$seen[] = 'out:'.$event->userIdentifier;
    }

    public static function clear(): void
    {
        self::$seen = [];
    }
}
