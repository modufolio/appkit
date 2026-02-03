<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Security\Exception;

use Modufolio\Appkit\Security\Token\TokenInterface;
use Modufolio\Appkit\Security\User\UserInterface;

class AuthenticationException extends RuntimeException
{
    private ?TokenInterface $token = null;
    public bool $requires2FA = false;
    public ?UserInterface $user = null;

    public function getToken(): ?TokenInterface
    {
        return $this->token;
    }

    public function setToken(TokenInterface $token): void
    {
        $this->token = $token;
    }

    public function isRequires2FA(): bool
    {
        return $this->requires2FA;
    }

    public function setRequires2FA(bool $requires2FA): void
    {
        $this->requires2FA = $requires2FA;
    }

    public function getUser(): ?UserInterface
    {
        return $this->user;
    }

    public function setUser(?UserInterface $user): void
    {
        $this->user = $user;
    }

    /**
     * Returns all the necessary state of the object for serialization purposes.
     *
     * There is no need to serialize any entry, they should be returned as-is.
     * If you extend this method, keep in mind you MUST guarantee parent data is present in the state.
     * Here is an example of how to extend this method:
     * <code>
     *     public function __serialize(): array
     *     {
     *         return [$this->childAttribute, parent::__serialize()];
     *     }
     * </code>
     *
     * @see __unserialize()
     */
    public function __serialize(): array
    {
        return [$this->token, $this->requires2FA, $this->user, $this->code, $this->message, $this->file, $this->line];
    }

    /**
     * Restores the object state from an array given by __serialize().
     *
     * There is no need to unserialize any entry in $data, they are already ready-to-use.
     * If you extend this method, keep in mind you MUST pass the parent data to its respective class.
     * Here is an example of how to extend this method:
     * <code>
     *     public function __unserialize(array $data): void
     *     {
     *         [$this->childAttribute, $parentData] = $data;
     *         parent::__unserialize($parentData);
     *     }
     * </code>
     *
     * @see __serialize()
     */
    public function __unserialize(array $data): void
    {
        [$this->token, $this->requires2FA, $this->user, $this->code, $this->message, $this->file, $this->line] = $data;
    }

    /**
     * Message key to be used by the translation component.
     */
    public function getMessageKey(): string
    {
        return 'An authentication exception occurred.';
    }

    /**
     * Message data to be used by the translation component.
     */
    public function getMessageData(): array
    {
        return [];
    }
}
