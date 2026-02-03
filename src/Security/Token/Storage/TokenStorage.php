<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Security\Token\Storage;

use Modufolio\Appkit\Core\ResetInterface;
use Modufolio\Appkit\Security\Token\TokenInterface;
use Modufolio\Appkit\Security\Token\TokenStorageInterface;

class TokenStorage implements TokenStorageInterface, ResetInterface
{
    private ?TokenInterface $token = null;
    private ?\Closure $initializer = null;

    public function getToken(): ?TokenInterface
    {
        if ($initializer = $this->initializer) {
            $this->initializer = null;
            $initializer();
        }

        return $this->token;
    }

    public function setToken(?TokenInterface $token): void
    {
        if ($token) {
            // ensure any initializer is called
            $this->getToken();
        }

        $this->initializer = null;
        $this->token = $token;
    }

    public function setInitializer(?callable $initializer): void
    {
        $this->initializer = null === $initializer ? null : $initializer(...);
    }

    public function reset(): void
    {
        $this->setToken(null);
    }
}
