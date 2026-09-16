<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\Exception;

/**
 * A persistent remember-me cookie was replayed: its series is known, its
 * value is neither the current one nor the one just rotated away from.
 *
 * Carries the identifier of the account the series belonged to, so the
 * kernel can tell the owner through
 * {@see \Modufolio\Appkit\Event\Security\RememberMeCookieTheftDetectedEvent}. The
 * message stays generic; the identifier is not for the visitor.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
class CookieTheftException extends AuthenticationException
{
    private ?string $userIdentifier;

    public function __construct(
        string $message = 'Remember me cookie theft detected.',
        ?string $userIdentifier = null,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
        $this->userIdentifier = $userIdentifier;
    }

    public function getUserIdentifier(): ?string
    {
        return $this->userIdentifier;
    }

    public function __serialize(): array
    {
        return [$this->userIdentifier, parent::__serialize()];
    }

    /**
     * @param array<int|string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        [$this->userIdentifier, $parentData] = $data;
        parent::__unserialize($parentData);
    }
}
