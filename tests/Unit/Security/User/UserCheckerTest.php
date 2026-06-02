<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Security\User;

use Modufolio\Appkit\Security\Exception\AccountExpiredException;
use Modufolio\Appkit\Security\Exception\CredentialsExpiredException;
use Modufolio\Appkit\Security\Exception\DisabledAccountException;
use Modufolio\Appkit\Security\Exception\LockedAccountException;
use Modufolio\Appkit\Security\User\CredentialsExpirableUserInterface;
use Modufolio\Appkit\Security\User\ExpirableUserInterface;
use Modufolio\Appkit\Security\User\LockableUserInterface;
use Modufolio\Appkit\Security\User\UserChecker;
use Modufolio\Appkit\Security\User\UserInterface;
use PHPUnit\Framework\TestCase;

class UserCheckerTest extends TestCase
{
    public function testCheckPreAuthPassesForEnabledPlainUser(): void
    {
        $checker = new UserChecker();
        $checker->checkPreAuth($this->plainUser(enabled: true));
        $this->addToAssertionCount(1);
    }

    public function testCheckPreAuthThrowsForDisabledUser(): void
    {
        $this->expectException(DisabledAccountException::class);

        (new UserChecker())->checkPreAuth($this->plainUser(enabled: false));
    }

    public function testCheckPreAuthThrowsForLockedUser(): void
    {
        $this->expectException(LockedAccountException::class);
        $this->expectExceptionMessage('Account compromised');

        (new UserChecker())->checkPreAuth($this->lockableUser(locked: true, reason: 'Account compromised'));
    }

    public function testCheckPreAuthLockedUserUsesDefaultMessageWhenReasonNull(): void
    {
        $this->expectException(LockedAccountException::class);
        $this->expectExceptionMessageMatches('/locked.*administrator/');

        (new UserChecker())->checkPreAuth($this->lockableUser(locked: true, reason: null));
    }

    public function testCheckPreAuthPassesForUnlockedLockableUser(): void
    {
        (new UserChecker())->checkPreAuth($this->lockableUser(locked: false));
        $this->addToAssertionCount(1);
    }

    public function testCheckPreAuthThrowsForExpiredAccount(): void
    {
        $this->expectException(AccountExpiredException::class);

        (new UserChecker())->checkPreAuth($this->expirableUser(expired: true));
    }

    public function testCheckPreAuthPassesForNonExpiredAccount(): void
    {
        (new UserChecker())->checkPreAuth($this->expirableUser(expired: false));
        $this->addToAssertionCount(1);
    }

    public function testCheckPreAuthSkipsLockChecksWhenInterfaceNotImplemented(): void
    {
        // A plain UserInterface (no LockableUserInterface) must not trigger lock checks
        // even if it happens to have an isLocked() method via duck typing.
        (new UserChecker())->checkPreAuth($this->plainUser(enabled: true));
        $this->addToAssertionCount(1);
    }

    public function testCheckPostAuthPassesForPlainUser(): void
    {
        (new UserChecker())->checkPostAuth($this->plainUser(enabled: true));
        $this->addToAssertionCount(1);
    }

    public function testCheckPostAuthThrowsForExpiredCredentials(): void
    {
        $this->expectException(CredentialsExpiredException::class);

        (new UserChecker())->checkPostAuth($this->credentialsExpirableUser(expired: true));
    }

    public function testCheckPostAuthPassesForFreshCredentials(): void
    {
        (new UserChecker())->checkPostAuth($this->credentialsExpirableUser(expired: false));
        $this->addToAssertionCount(1);
    }

    public function testDisabledCheckTakesPrecedenceOverLocked(): void
    {
        // Disabled + locked → should throw Disabled (it's checked first).
        $user = new class implements LockableUserInterface {
            public function getId(): int
            {
                return 1;
            }

            public function getEmail(): string
            {
                return 'u@example.com';
            }

            public function getRoles(): array
            {
                return ['ROLE_USER'];
            }

            public function getUserIdentifier(): string
            {
                return 'u@example.com';
            }

            public function eraseCredentials(): void
            {
            }

            public function isEnabled(): bool
            {
                return false;
            }

            public function isLocked(): bool
            {
                return true;
            }

            public function getLockedAt(): ?\DateTimeImmutable
            {
                return null;
            }

            public function getLockedReason(): ?string
            {
                return null;
            }
        };

        $this->expectException(DisabledAccountException::class);
        (new UserChecker())->checkPreAuth($user);
    }

    private function plainUser(bool $enabled): UserInterface
    {
        return new class($enabled) implements UserInterface {
            public function __construct(private bool $enabled)
            {
            }

            public function getId(): int
            {
                return 1;
            }

            public function getEmail(): string
            {
                return 'plain@example.com';
            }

            public function getRoles(): array
            {
                return ['ROLE_USER'];
            }

            public function getUserIdentifier(): string
            {
                return 'plain@example.com';
            }

            public function eraseCredentials(): void
            {
            }

            public function isEnabled(): bool
            {
                return $this->enabled;
            }
        };
    }

    private function lockableUser(bool $locked, ?string $reason = null): LockableUserInterface
    {
        return new class($locked, $reason) implements LockableUserInterface {
            public function __construct(private bool $locked, private ?string $reason)
            {
            }

            public function getId(): int
            {
                return 2;
            }

            public function getEmail(): string
            {
                return 'lockable@example.com';
            }

            public function getRoles(): array
            {
                return ['ROLE_USER'];
            }

            public function getUserIdentifier(): string
            {
                return 'lockable@example.com';
            }

            public function eraseCredentials(): void
            {
            }

            public function isEnabled(): bool
            {
                return true;
            }

            public function isLocked(): bool
            {
                return $this->locked;
            }

            public function getLockedAt(): ?\DateTimeImmutable
            {
                return $this->locked ? new \DateTimeImmutable() : null;
            }

            public function getLockedReason(): ?string
            {
                return $this->reason;
            }
        };
    }

    private function expirableUser(bool $expired): ExpirableUserInterface
    {
        return new class($expired) implements ExpirableUserInterface {
            public function __construct(private bool $expired)
            {
            }

            public function getId(): int
            {
                return 3;
            }

            public function getEmail(): string
            {
                return 'expirable@example.com';
            }

            public function getRoles(): array
            {
                return ['ROLE_USER'];
            }

            public function getUserIdentifier(): string
            {
                return 'expirable@example.com';
            }

            public function eraseCredentials(): void
            {
            }

            public function isEnabled(): bool
            {
                return true;
            }

            public function isAccountExpired(): bool
            {
                return $this->expired;
            }

            public function getAccountExpiresAt(): ?\DateTimeImmutable
            {
                return $this->expired ? new \DateTimeImmutable('-1 day') : null;
            }
        };
    }

    private function credentialsExpirableUser(bool $expired): CredentialsExpirableUserInterface
    {
        return new class($expired) implements CredentialsExpirableUserInterface {
            public function __construct(private bool $expired)
            {
            }

            public function getId(): int
            {
                return 4;
            }

            public function getEmail(): string
            {
                return 'creds@example.com';
            }

            public function getRoles(): array
            {
                return ['ROLE_USER'];
            }

            public function getUserIdentifier(): string
            {
                return 'creds@example.com';
            }

            public function eraseCredentials(): void
            {
            }

            public function isEnabled(): bool
            {
                return true;
            }

            public function isCredentialsExpired(): bool
            {
                return $this->expired;
            }

            public function getCredentialsExpireAt(): ?\DateTimeImmutable
            {
                return $this->expired ? new \DateTimeImmutable('-1 day') : null;
            }
        };
    }
}
