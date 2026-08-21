<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\User;

/**
 * Marker interface for users whose credentials (passwords) can expire.
 *
 * Implement this on your User entity if you want {@see UserChecker} to force
 * a password reset when credentials have expired.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
interface CredentialsExpirableUserInterface extends UserInterface
{
    public function isCredentialsExpired(): bool;

    public function getCredentialsExpireAt(): ?\DateTimeImmutable;
}
