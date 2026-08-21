<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\User;

/**
 * @author    Robin Chalas <robin.chalas@gmail.com>
 * @author    Wouter de Jong <wouter@wouterj.nl>
 *
 * @see       https://github.com/symfony/security-core
 *
 * @copyright Fabien Potencier <fabien@symfony.com>
 * @license   https://opensource.org/licenses/MIT
 */
interface PasswordAuthenticatedUserInterface extends UserInterface
{
    /**
     * Returns the hashed password used to authenticate the user.
     *
     * Usually on authentication, a plain-text password will be compared to this value.
     */
    public function getPassword(): ?string;
}
