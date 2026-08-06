<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Security;

use Modufolio\Appkit\Security\AuthenticationTrustResolver;
use Modufolio\Appkit\Security\Token\RememberMeToken;
use Modufolio\Appkit\Security\Token\UsernamePasswordToken;
use Modufolio\Appkit\Security\User\InMemoryUser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuthenticationTrustResolver::class)]
class AuthenticationTrustResolverTest extends TestCase
{
    private AuthenticationTrustResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new AuthenticationTrustResolver();
    }

    private function usernamePasswordToken(): UsernamePasswordToken
    {
        return new UsernamePasswordToken(
            new InMemoryUser('john@example.com', 'secret', ['ROLE_USER']),
            'main',
            ['ROLE_USER']
        );
    }

    private function rememberMeToken(): RememberMeToken
    {
        return new RememberMeToken(
            new InMemoryUser('john@example.com', 'secret', ['ROLE_USER']),
            'main',
            's3cr3t'
        );
    }

    public function testIsAuthenticated(): void
    {
        $this->assertFalse($this->resolver->isAuthenticated(null));
        $this->assertFalse($this->resolver->isAuthenticated());
        $this->assertTrue($this->resolver->isAuthenticated($this->usernamePasswordToken()));
        $this->assertTrue($this->resolver->isAuthenticated($this->rememberMeToken()));
    }

    public function testIsRememberMe(): void
    {
        $this->assertFalse($this->resolver->isRememberMe(null));
        $this->assertFalse($this->resolver->isRememberMe($this->usernamePasswordToken()));
        $this->assertTrue($this->resolver->isRememberMe($this->rememberMeToken()));
    }

    public function testIsFullFledged(): void
    {
        $this->assertFalse($this->resolver->isFullFledged(null));
        $this->assertTrue($this->resolver->isFullFledged($this->usernamePasswordToken()));

        // remember-me sessions are authenticated but not full-fledged
        $this->assertFalse($this->resolver->isFullFledged($this->rememberMeToken()));
    }
}
