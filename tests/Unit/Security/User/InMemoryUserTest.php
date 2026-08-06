<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Security\User;

use Modufolio\Appkit\Security\User\InMemoryUser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InMemoryUser::class)]
class InMemoryUserTest extends TestCase
{
    public function testBasicAccessors(): void
    {
        $user = new InMemoryUser('john@example.com', 'secret', ['ROLE_USER']);

        $this->assertSame('john@example.com', $user->getUserIdentifier());
        $this->assertSame('john@example.com', (string) $user);
        $this->assertSame('john@example.com', $user->getId());
        $this->assertSame('john@example.com', $user->getEmail());
        $this->assertSame('secret', $user->getPassword());
        $this->assertSame(['ROLE_USER'], $user->getRoles());
        $this->assertTrue($user->isEnabled());
    }

    public function testDisabledUser(): void
    {
        $user = new InMemoryUser('john@example.com', 'secret', [], false);

        $this->assertFalse($user->isEnabled());
    }

    public function testEmptyUsernameIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The username cannot be empty.');

        new InMemoryUser('', 'secret');
    }

    public function testNullUsernameIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new InMemoryUser(null, 'secret');
    }

    public function testEraseCredentialsIsANoOp(): void
    {
        $user = new InMemoryUser('john@example.com', 'secret');
        $user->eraseCredentials();

        $this->assertSame('secret', $user->getPassword());
    }

    public function testIsEqualTo(): void
    {
        $user = new InMemoryUser('john@example.com', 'secret', ['ROLE_USER']);
        $same = new InMemoryUser('john@example.com', 'secret', ['ROLE_USER']);

        $this->assertTrue($user->isEqualTo($same));
    }

    public function testIsNotEqualToDifferentClass(): void
    {
        $user = new InMemoryUser('john@example.com', 'secret');
        $other = new class implements \Modufolio\Appkit\Security\User\UserInterface {
            public function getRoles(): array
            {
                return [];
            }

            public function getUserIdentifier(): string
            {
                return 'john@example.com';
            }

            public function eraseCredentials(): void
            {
            }

            public function getId(): mixed
            {
                return null;
            }

            public function getEmail(): string
            {
                return 'john@example.com';
            }

            public function isEnabled(): bool
            {
                return true;
            }
        };

        $this->assertFalse($user->isEqualTo($other));
    }

    public function testIsNotEqualToDifferentPassword(): void
    {
        $user = new InMemoryUser('john@example.com', 'secret');
        $other = new InMemoryUser('john@example.com', 'different');

        $this->assertFalse($user->isEqualTo($other));
    }

    public function testIsNotEqualToDifferentRoles(): void
    {
        $user = new InMemoryUser('john@example.com', 'secret', ['ROLE_USER']);
        $other = new InMemoryUser('john@example.com', 'secret', ['ROLE_ADMIN']);

        $this->assertFalse($user->isEqualTo($other));
    }

    public function testIsNotEqualToDifferentIdentifier(): void
    {
        $user = new InMemoryUser('john@example.com', 'secret');
        $other = new InMemoryUser('jane@example.com', 'secret');

        $this->assertFalse($user->isEqualTo($other));
    }

    public function testIsNotEqualToDifferentEnabledState(): void
    {
        $user = new InMemoryUser('john@example.com', 'secret');
        $other = new InMemoryUser('john@example.com', 'secret', [], false);

        $this->assertFalse($user->isEqualTo($other));
    }
}
