<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Security\Exception;

use Modufolio\Appkit\Security\User\UserInterface;

final class TwoFactorRequiredException extends AuthenticationException
{
    public function __construct(UserInterface $user, string $message = 'Two-factor authentication required')
    {
        parent::__construct($message);
        $this->setUser($user);
    }

    public function getUser(): UserInterface
    {
        $user = parent::getUser();
        assert($user !== null);
        return $user;
    }
}
