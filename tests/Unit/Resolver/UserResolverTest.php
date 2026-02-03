<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Tests\Unit\Resolver;

use Modufolio\Appkit\Attributes\CurrentUser;
use Modufolio\Appkit\Resolver\UserResolver;
use Modufolio\Appkit\Security\Token\Storage\TokenStorage;
use Modufolio\Appkit\Security\Token\UsernamePasswordToken;
use Modufolio\Appkit\Security\User\UserInterface;
use PHPUnit\Framework\TestCase;

class UserResolverTest extends TestCase
{
    private UserResolver $resolver;
    private TokenStorage $tokenStorage;

    protected function setUp(): void
    {
        $this->tokenStorage = new TokenStorage();
        $this->resolver = new UserResolver($this->tokenStorage);
    }

    public function testSupportsReturnsTrueForParameterWithCurrentUserAttribute(): void
    {
        // Arrange
        $testClass = new class () {
            public function method(#[CurrentUser] $user): void
            {
            }
        };
        $reflection = new \ReflectionMethod($testClass, 'method');
        $parameter = $reflection->getParameters()[0];

        // Act
        $result = $this->resolver->supports($parameter);

        // Assert
        $this->assertTrue($result);
    }

    public function testSupportsReturnsFalseForParameterWithoutCurrentUserAttribute(): void
    {
        // Arrange
        $testClass = new class () {
            public function method($user): void
            {
            }
        };
        $reflection = new \ReflectionMethod($testClass, 'method');
        $parameter = $reflection->getParameters()[0];

        // Act
        $result = $this->resolver->supports($parameter);

        // Assert
        $this->assertFalse($result);
    }

    public function testResolveReturnsUserFromTokenStorage(): void
    {
        // Arrange
        $user = new TestUser();
        $token = new UsernamePasswordToken($user, 'main', ['ROLE_USER']);
        $this->tokenStorage->setToken($token);
        $testClass = new class () {
            public function method(#[CurrentUser] $user): void
            {
            }
        };
        $reflection = new \ReflectionMethod($testClass, 'method');
        $parameter = $reflection->getParameters()[0];
        $providedParameters = [];

        // Act
        $result = $this->resolver->resolve($parameter, $providedParameters);

        // Assert
        $this->assertInstanceOf(UserInterface::class, $result);
        $this->assertSame($user, $result);
    }

    public function testResolveReturnsNullWhenNoTokenInStorage(): void
    {
        // Arrange
        $this->tokenStorage->setToken(null); // No token
        $testClass = new class () {
            public function method(#[CurrentUser] $user): void
            {
            }
        };
        $reflection = new \ReflectionMethod($testClass, 'method');
        $parameter = $reflection->getParameters()[0];
        $providedParameters = [];

        // Act
        $result = $this->resolver->resolve($parameter, $providedParameters);

        // Assert
        $this->assertNull($result);
    }

    public function testResolveIgnoresProvidedParameters(): void
    {
        // Arrange
        $user = new TestUser();
        $token = new UsernamePasswordToken($user, 'main', ['ROLE_USER']);
        $this->tokenStorage->setToken($token);
        $testClass = new class () {
            public function method(#[CurrentUser] $user): void
            {
            }
        };
        $reflection = new \ReflectionMethod($testClass, 'method');
        $parameter = $reflection->getParameters()[0];
        $providedParameters = ['user' => new TestUser()]; // Different user instance

        // Act
        $result = $this->resolver->resolve($parameter, $providedParameters);

        // Assert
        $this->assertSame($user, $result); // Should return user from token, not providedParameters
    }
}

// Simple UserInterface implementation
class TestUser implements UserInterface
{
    public function getId(): mixed
    {
        return 1;
    }
    public function getEmail(): string
    {
        return 'test@example.com';
    }
    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }
    public function getPassword(): ?string
    {
        return null;
    }
    public function getSalt(): ?string
    {
        return null;
    }
    public function eraseCredentials(): void
    {
    }
    public function getUserIdentifier(): string
    {
        return 'testuser';
    }
}
