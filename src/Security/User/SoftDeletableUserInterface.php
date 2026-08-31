<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\User;

/**
 * Marker interface for users that support soft deletion.
 *
 * Implement this on your User entity if you want {@see EntityUserProvider}
 * to refuse to load soft-deleted accounts (fresh logins AND session
 * refreshes — an active session dies on the next request) and
 * {@see UserChecker} to block them as a second line of defence.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
interface SoftDeletableUserInterface extends UserInterface
{
    public function isDeleted(): bool;
}
