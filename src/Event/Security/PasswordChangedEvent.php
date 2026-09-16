<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Event\Security;

/**
 * A user's password was replaced.
 *
 * The framework never changes a password itself — a profile form, a reset
 * flow and an admin action all live in the application — so the application
 * dispatches this, right after the new hash is persisted:
 *
 *     $user->setPassword($this->hasher->hashPassword($user, $plain));
 *     $this->entityManager->flush();
 *     $this->events->dispatch(new PasswordChangedEvent($user->getUserIdentifier(), self::class));
 *
 * The framework defines the event so every application's listeners agree on
 * its shape, and so a rehash on login (a password *upgrade*, same secret)
 * is never mistaken for a change: the kernel does not dispatch this for one.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
final readonly class PasswordChangedEvent
{
    /**
     * @param string|null $origin what changed it — a controller, a console command, a reset flow — for the notification's wording
     */
    public function __construct(
        public string $userIdentifier,
        public ?string $origin = null,
        public ?string $clientIp = null,
    ) {
    }
}
