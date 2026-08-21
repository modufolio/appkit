<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\User;

/**
 * Marker interface for users that support administrative locking.
 *
 * Implement this on your User entity if you want {@see UserChecker} to block
 * authentication for locked accounts.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
interface LockableUserInterface extends UserInterface
{
    public function isLocked(): bool;

    public function getLockedAt(): ?\DateTimeImmutable;

    public function getLockedReason(): ?string;
}
