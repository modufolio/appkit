<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\User;

/**
 * @author    Dariusz Górecki <darek.krk@gmail.com>
 *
 * @see       https://github.com/symfony/security-core
 *
 * @copyright Fabien Potencier <fabien@symfony.com>
 * @license   https://opensource.org/licenses/MIT
 */
interface EquatableInterface
{
    /**
     * The equality comparison should neither be done by referential equality
     * nor by comparing identities (i.e. getId() === getId()).
     *
     * However, you do not need to compare every attribute, but only those that
     * are relevant for assessing whether re-authentication is required.
     */
    public function isEqualTo(UserInterface $user): bool;
}
