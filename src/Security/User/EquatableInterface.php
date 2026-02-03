<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Security\User;

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
