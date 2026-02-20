<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Security\Token;

use Modufolio\Appkit\Security\Token\ApiKeyToken;
use Modufolio\Appkit\Security\Token\JwtToken;
use Modufolio\Appkit\Security\Token\UsernamePasswordToken;
use Modufolio\Appkit\Security\User\InMemoryUser;
use PHPUnit\Framework\TestCase;

class TokenTest extends TestCase
{
    public function testUsernamePasswordToken(): void
    {
        $user = new InMemoryUser('user@example.com', 'hashedpassword', ['ROLE_USER']);
        // Roles are passed to the token constructor
        $token = new UsernamePasswordToken($user, 'web', ['ROLE_USER']);

        $this->assertSame('user@example.com', $token->getUserIdentifier());
        $this->assertSame($user, $token->getUser());
        $this->assertContains('ROLE_USER', $token->getRoleNames());
        $this->assertSame('web', $token->getFirewallName());
    }

    public function testApiKeyToken(): void
    {
        $user = new InMemoryUser('api_user', 'apikey123', ['ROLE_API']);
        // ApiKeyToken(user, firewallName, apiKey, roles)
        $token = new ApiKeyToken($user, 'api', 'apikey123', ['ROLE_API']);

        $this->assertSame('api_user', $token->getUserIdentifier());
        $this->assertSame($user, $token->getUser());
        $this->assertSame('apikey123', $token->getApiKey());
        $this->assertSame('api', $token->getFirewallName());
    }

    public function testJwtToken(): void
    {
        $payload = ['sub' => 'user123', 'email' => 'user@example.com'];
        $user = new InMemoryUser('user@example.com', '', ['ROLE_JWT']);
        $token = new JwtToken($user, 'jwt', $payload, ['ROLE_JWT']);

        $this->assertSame('user@example.com', $token->getUserIdentifier());
        $this->assertSame($user, $token->getUser());
        $this->assertSame($payload, $token->getPayload());
        $this->assertSame('jwt', $token->getFirewallName());
    }

    public function testTokenAttributes(): void
    {
        $user = new InMemoryUser('user@example.com', '', ['ROLE_USER']);
        $token = new UsernamePasswordToken($user, 'web', ['ROLE_USER']);

        // Test setAttribute and getAttribute
        $token->setAttribute('login_timestamp', 1234567890);
        $this->assertSame(1234567890, $token->getAttribute('login_timestamp'));

        // Test hasAttribute
        $this->assertTrue($token->hasAttribute('login_timestamp'));
        $this->assertFalse($token->hasAttribute('nonexistent'));
    }

    public function testTokenEraseCredentials(): void
    {
        $user = new InMemoryUser('user@example.com', 'password', ['ROLE_USER']);
        $token = new UsernamePasswordToken($user, 'web', ['ROLE_USER']);

        // Erase credentials
        $token->eraseCredentials();

        // Should still have user
        $this->assertSame('user@example.com', $token->getUserIdentifier());
    }

    public function testTokenSerialization(): void
    {
        $user = new InMemoryUser('user@example.com', 'hashedpass', ['ROLE_USER', 'ROLE_ADMIN']);
        $token = new UsernamePasswordToken($user, 'web', ['ROLE_USER', 'ROLE_ADMIN']);
        $token->setAttribute('created_at', 1234567890);

        // Serialize
        $serialized = serialize($token);
        $this->assertIsString($serialized);

        // Unserialize
        $unserializedToken = unserialize($serialized);

        $this->assertInstanceOf(UsernamePasswordToken::class, $unserializedToken);
        $this->assertSame('user@example.com', $unserializedToken->getUserIdentifier());
        $roles = $unserializedToken->getRoleNames();
        $this->assertContains('ROLE_USER', $roles);
        $this->assertContains('ROLE_ADMIN', $roles);
        $this->assertSame(1234567890, $unserializedToken->getAttribute('created_at'));
    }

    public function testTokenSetUser(): void
    {
        $user1 = new InMemoryUser('user1@example.com', '', ['ROLE_USER']);
        $user2 = new InMemoryUser('user2@example.com', '', ['ROLE_ADMIN']);

        $token = new UsernamePasswordToken($user1, 'web', ['ROLE_USER']);
        $this->assertSame('user1@example.com', $token->getUserIdentifier());

        $token->setUser($user2);
        $this->assertSame('user2@example.com', $token->getUserIdentifier());
        $this->assertSame($user2, $token->getUser());
    }

    public function testTokenWithoutUser(): void
    {
        $token = new UsernamePasswordToken(new InMemoryUser('temp', '', []), 'web', []);
        $token->setUser(new InMemoryUser('new_user', '', []));

        $this->assertNotNull($token->getUser());
        $this->assertSame('new_user', $token->getUser()->getUserIdentifier());
    }

    public function testMultipleTokenTypes(): void
    {
        $user = new InMemoryUser('test@example.com', '', ['ROLE_USER']);

        $usernameToken = new UsernamePasswordToken($user, 'web', ['ROLE_USER']);
        $apiToken = new ApiKeyToken($user, 'api', 'key123', ['ROLE_API']);
        $jwtToken = new JwtToken($user, 'jwt', ['sub' => 'test'], ['ROLE_JWT']);

        $this->assertSame('test@example.com', $usernameToken->getUserIdentifier());
        $this->assertSame('test@example.com', $apiToken->getUserIdentifier());
        $this->assertSame('test@example.com', $jwtToken->getUserIdentifier());

        // Different tokens have different firewalls
        $this->assertSame('web', $usernameToken->getFirewallName());
        $this->assertSame('api', $apiToken->getFirewallName());
        $this->assertSame('jwt', $jwtToken->getFirewallName());
    }

    public function testInMemoryUserCreation(): void
    {
        $user = new InMemoryUser(
            'user@example.com',
            'hashedpassword',
            ['ROLE_USER', 'ROLE_MODERATOR']
        );

        $this->assertSame('user@example.com', $user->getUserIdentifier());
        $this->assertSame('hashedpassword', $user->getPassword());
        $roles = $user->getRoles();
        $this->assertContains('ROLE_USER', $roles);
        $this->assertContains('ROLE_MODERATOR', $roles);
    }
}
