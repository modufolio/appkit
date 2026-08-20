<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Security\Token;

use Modufolio\Appkit\Security\Token\SwitchUserToken;
use Modufolio\Appkit\Security\Token\UsernamePasswordToken;
use Modufolio\Appkit\Security\User\InMemoryUser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SwitchUserToken::class)]
class SwitchUserTokenTest extends TestCase
{
    /**
     * @param list<string> $roles
     */
    private function createToken(array $roles = ['ROLE_USER']): SwitchUserToken
    {
        $admin = new InMemoryUser('admin@example.com', 'secret', ['ROLE_ADMIN']);
        $target = new InMemoryUser('user@example.com', 'secret', ['ROLE_USER']);

        $originalToken = new UsernamePasswordToken($admin, 'main', ['ROLE_ADMIN']);

        return new SwitchUserToken($target, 'main', $roles, $originalToken);
    }

    public function testGetOriginalToken(): void
    {
        $token = $this->createToken();

        $this->assertSame('admin@example.com', $token->getOriginalToken()->getUser()?->getUserIdentifier());
    }

    public function testGetFirewallName(): void
    {
        $this->assertSame('main', $this->createToken()->getFirewallName());
    }

    public function testIsImpersonating(): void
    {
        $this->assertTrue($this->createToken()->isImpersonating());
    }

    public function testImpersonationAttributeIsSet(): void
    {
        $this->assertTrue($this->createToken()->getAttribute('ROLE_PREVIOUS_ADMIN'));
    }

    public function testNoAttributeWhenRoleAlreadyPresent(): void
    {
        $token = $this->createToken(['ROLE_USER', 'ROLE_PREVIOUS_ADMIN']);

        $this->assertFalse($token->hasAttribute('ROLE_PREVIOUS_ADMIN'));
    }

    public function testEmptyFirewallNameIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('$firewallName must not be empty.');

        $user = new InMemoryUser('user@example.com', 'secret');
        $original = new UsernamePasswordToken($user, 'main', []);

        new SwitchUserToken($user, '', [], $original);
    }

    public function testSerializeRoundTrip(): void
    {
        $token = $this->createToken();

        $restored = unserialize(serialize($token));

        $this->assertInstanceOf(SwitchUserToken::class, $restored);
        $this->assertSame('main', $restored->getFirewallName());
        $this->assertSame(['ROLE_USER'], $restored->getRoleNames());
        $this->assertSame(
            'admin@example.com',
            $restored->getOriginalToken()->getUser()?->getUserIdentifier()
        );
    }
}
