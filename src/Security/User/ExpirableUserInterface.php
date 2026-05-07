<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\User;

/**
 * Marker interface for users whose accounts can expire.
 *
 * Implement this on your User entity if you want {@see UserChecker} to block
 * authentication for expired accounts.
 */
interface ExpirableUserInterface extends UserInterface
{
    public function isAccountExpired(): bool;

    public function getAccountExpiresAt(): ?\DateTimeImmutable;
}
