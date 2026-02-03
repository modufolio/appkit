<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\Csrf;

/**
 * CSRF Token Value Object
 *
 * Represents a CSRF token with its identifier and value.
 * Immutable by design.
 */
class CsrfToken
{
    private string $id;
    private string $value;

    /**
     * @param string $id Token identifier (e.g., 'login', 'delete_user')
     * @param string $value Token value (random string)
     */
    public function __construct(string $id, string $value)
    {
        if (empty($id)) {
            throw new \InvalidArgumentException('CSRF token ID cannot be empty');
        }

        if (empty($value)) {
            throw new \InvalidArgumentException('CSRF token value cannot be empty');
        }

        $this->id = $id;
        $this->value = $value;
    }

    /**
     * Get the token identifier
     *
     * @return string
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Get the token value
     *
     * @return string
     */
    public function getValue(): string
    {
        return $this->value;
    }

    /**
     * Get string representation (returns value for convenience)
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->value;
    }
}
