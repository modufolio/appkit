<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\User;

/**
 * Implemented by user providers that can persist an upgraded password hash.
 *
 * After a successful login, an authenticator calls upgradePassword() when the
 * stored hash needs rehashing (e.g. cost parameters were raised or the
 * algorithm changed), so existing users are migrated transparently.
 */
interface PasswordUpgraderInterface
{
    /**
     * Stores the new hashed password for the given user.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, #[\SensitiveParameter] string $newHashedPassword): void;
}
