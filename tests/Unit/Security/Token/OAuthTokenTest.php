<?php

namespace Modufolio\Appkit\Tests\Unit\Security\Token;

use Modufolio\Appkit\Security\Token\OAuthToken;
use Modufolio\Appkit\Security\User\UserInterface;
use PHPUnit\Framework\TestCase;

class OAuthTokenTest extends TestCase
{
    private UserInterface $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createMock(UserInterface::class);
        $this->user->method('getUserIdentifier')->willReturn('test@example.com');
        $this->user->method('getRoles')->willReturn(['ROLE_USER', 'ROLE_ADMIN']);
    }

    public function testConstructorCreatesTokenWithCorrectProperties(): void
    {
        $token = new OAuthToken(
            $this->user,
            'main',
            ['read', 'write'],
            ['ROLE_USER', 'ROLE_ADMIN']
        );

        $this->assertSame($this->user, $token->getUser());
        $this->assertEquals('main', $token->getFirewallName());
        $this->assertEquals(['read', 'write'], $token->getScopes());
        $this->assertEquals(['ROLE_USER', 'ROLE_ADMIN'], $token->getRoles());
    }

    public function testConstructorWithEmptyScopes(): void
    {
        $token = new OAuthToken(
            $this->user,
            'api',
            [],
            ['ROLE_USER']
        );

        $this->assertSame($this->user, $token->getUser());
        $this->assertEquals('api', $token->getFirewallName());
        $this->assertEmpty($token->getScopes());
        $this->assertEquals(['ROLE_USER'], $token->getRoles());
    }

    public function testGetScopesReturnsCorrectScopes(): void
    {
        $scopes = ['read', 'write', 'delete'];
        $token = new OAuthToken($this->user, 'main', $scopes);

        $this->assertEquals($scopes, $token->getScopes());
    }

    public function testHasScopeReturnsTrueForExistingScope(): void
    {
        $token = new OAuthToken($this->user, 'main', ['read', 'write', 'delete']);

        $this->assertTrue($token->hasScope('read'));
        $this->assertTrue($token->hasScope('write'));
        $this->assertTrue($token->hasScope('delete'));
    }

    public function testHasScopeReturnsFalseForNonExistingScope(): void
    {
        $token = new OAuthToken($this->user, 'main', ['read', 'write']);

        $this->assertFalse($token->hasScope('delete'));
        $this->assertFalse($token->hasScope('admin'));
        $this->assertFalse($token->hasScope(''));
    }

    public function testHasScopeIsCaseSensitive(): void
    {
        $token = new OAuthToken($this->user, 'main', ['read', 'write']);

        $this->assertTrue($token->hasScope('read'));
        $this->assertFalse($token->hasScope('Read'));
        $this->assertFalse($token->hasScope('READ'));
    }

    public function testHasScopeWithEmptyScopes(): void
    {
        $token = new OAuthToken($this->user, 'main', []);

        $this->assertFalse($token->hasScope('read'));
        $this->assertFalse($token->hasScope('write'));
    }

    public function testMultipleScopesCanBeChecked(): void
    {
        $token = new OAuthToken($this->user, 'main', ['users:read', 'users:write', 'posts:read']);

        $this->assertTrue($token->hasScope('users:read'));
        $this->assertTrue($token->hasScope('users:write'));
        $this->assertTrue($token->hasScope('posts:read'));
        $this->assertFalse($token->hasScope('posts:write'));
        $this->assertFalse($token->hasScope('posts:delete'));
    }

    public function testTokenWithNamespacedScopes(): void
    {
        $scopes = [
            'api.users.read',
            'api.users.write',
            'api.posts.read',
            'api.posts.write',
            'api.comments.delete'
        ];

        $token = new OAuthToken($this->user, 'main', $scopes);

        $this->assertEquals($scopes, $token->getScopes());
        $this->assertTrue($token->hasScope('api.users.read'));
        $this->assertTrue($token->hasScope('api.posts.write'));
        $this->assertTrue($token->hasScope('api.comments.delete'));
        $this->assertFalse($token->hasScope('api.admin.access'));
    }

    public function testTokenInheritsUserRoles(): void
    {
        $token = new OAuthToken($this->user, 'main', ['read'], ['ROLE_USER', 'ROLE_ADMIN']);

        $this->assertEquals(['ROLE_USER', 'ROLE_ADMIN'], $token->getRoles());
    }

    public function testGetUserReturnsCorrectUser(): void
    {
        $token = new OAuthToken($this->user, 'main', ['read']);

        $this->assertSame($this->user, $token->getUser());
        $this->assertEquals('test@example.com', $token->getUser()->getUserIdentifier());
    }

    public function testGetFirewallNameReturnsCorrectName(): void
    {
        $token = new OAuthToken($this->user, 'api_v2', ['read']);

        $this->assertEquals('api_v2', $token->getFirewallName());
    }
}
