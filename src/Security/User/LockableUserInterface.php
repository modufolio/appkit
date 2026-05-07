<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\User;

/**
 * Marker interface for users that support administrative locking.
 *
 * Implement this on your User entity if you want {@see UserChecker} to block
 * authentication for locked accounts.
 */
interface LockableUserInterface extends UserInterface
{
    public function isLocked(): bool;

    public function getLockedAt(): ?\DateTimeImmutable;

    public function getLockedReason(): ?string;
}
