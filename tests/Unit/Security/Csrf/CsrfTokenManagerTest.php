<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Security\Csrf;

use Modufolio\Appkit\Security\Csrf\CsrfToken;
use Modufolio\Appkit\Security\Csrf\CsrfTokenManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

class CsrfTokenManagerTest extends TestCase
{
    private function createMockSession(array $sessionData = []): SessionInterface
    {
        $session = $this->createMock(SessionInterface::class);

        $storage = $sessionData;

        $session->method('get')
            ->willReturnCallback(function ($key, $default = null) use (&$storage) {
                return $storage[$key] ?? $default;
            });

        $session->method('set')
            ->willReturnCallback(function ($key, $value) use (&$storage) {
                $storage[$key] = $value;
            });

        return $session;
    }

    public function testGetTokenGeneratesNewToken(): void
    {
        $session = $this->createMockSession();
        $manager = new CsrfTokenManager($session);

        $token = $manager->getToken();

        $this->assertInstanceOf(CsrfToken::class, $token);
        $this->assertSame('csrf_token', $token->getId());
        $this->assertNotEmpty($token->getValue());
    }

    public function testGetTokenWithCustomId(): void
    {
        $session = $this->createMockSession();
        $manager = new CsrfTokenManager($session);

        $token = $manager->getToken('login');

        $this->assertSame('login', $token->getId());
    }

    public function testGetTokenReturnsExistingToken(): void
    {
        $session = $this->createMockSession([
            '_csrf_tokens' => ['login' => 'existing_token_value']
        ]);
        $manager = new CsrfTokenManager($session);

        $token = $manager->getToken('login');

        $this->assertSame('existing_token_value', $token->getValue());
    }

    public function testRefreshTokenGeneratesNewValue(): void
    {
        $session = $this->createMockSession([
            '_csrf_tokens' => ['login' => 'old_value']
        ]);
        $manager = new CsrfTokenManager($session);

        $newToken = $manager->refreshToken('login');

        $this->assertNotSame('old_value', $newToken->getValue());
    }

    public function testIsTokenValidWithValidToken(): void
    {
        $tokenValue = bin2hex(random_bytes(32));
        $session = $this->createMockSession([
            '_csrf_tokens' => ['login' => $tokenValue]
        ]);
        $manager = new CsrfTokenManager($session);

        $token = new CsrfToken('login', $tokenValue);
        $isValid = $manager->isTokenValid($token);

        $this->assertTrue($isValid);
    }

    public function testIsTokenValidWithInvalidToken(): void
    {
        $session = $this->createMockSession([
            '_csrf_tokens' => ['login' => 'correct_value']
        ]);
        $manager = new CsrfTokenManager($session);

        $token = new CsrfToken('login', 'wrong_value');
        $isValid = $manager->isTokenValid($token);

        $this->assertFalse($isValid);
    }

    public function testIsTokenValidWithNonExistentToken(): void
    {
        $session = $this->createMockSession();
        $manager = new CsrfTokenManager($session);

        $token = new CsrfToken('nonexistent', 'some_value');
        $isValid = $manager->isTokenValid($token);

        $this->assertFalse($isValid);
    }

    public function testValidateTokenConvenienceMethod(): void
    {
        $tokenValue = bin2hex(random_bytes(32));
        $session = $this->createMockSession([
            '_csrf_tokens' => ['login' => $tokenValue]
        ]);
        $manager = new CsrfTokenManager($session);

        $isValid = $manager->validateToken('login', $tokenValue);

        $this->assertTrue($isValid);
    }

    public function testRemoveToken(): void
    {
        $session = $this->createMockSession([
            '_csrf_tokens' => ['login' => 'value', 'logout' => 'value2']
        ]);
        $manager = new CsrfTokenManager($session);

        $this->assertTrue($manager->hasToken('login'));

        $manager->removeToken('login');

        $this->assertFalse($manager->hasToken('login'));
        $this->assertTrue($manager->hasToken('logout'));
    }

    public function testHasToken(): void
    {
        $session = $this->createMockSession([
            '_csrf_tokens' => ['login' => 'value']
        ]);
        $manager = new CsrfTokenManager($session);

        $this->assertTrue($manager->hasToken('login'));
        $this->assertFalse($manager->hasToken('logout'));
    }

    public function testGetTokenIds(): void
    {
        $session = $this->createMockSession([
            '_csrf_tokens' => [
                'login' => 'value1',
                'logout' => 'value2',
                'delete' => 'value3'
            ]
        ]);
        $manager = new CsrfTokenManager($session);

        $tokenIds = $manager->getTokenIds();

        $this->assertCount(3, $tokenIds);
        $this->assertContains('login', $tokenIds);
        $this->assertContains('logout', $tokenIds);
        $this->assertContains('delete', $tokenIds);
    }

    public function testClearRemovesAllTokens(): void
    {
        $session = $this->createMockSession([
            '_csrf_tokens' => [
                'login' => 'value1',
                'logout' => 'value2'
            ]
        ]);
        $manager = new CsrfTokenManager($session);

        $this->assertTrue($manager->hasToken('login'));
        $this->assertTrue($manager->hasToken('logout'));

        $manager->clear();

        $this->assertEmpty($manager->getTokenIds());
    }

    public function testCustomDefaultTokenId(): void
    {
        $session = $this->createMockSession();
        $manager = new CsrfTokenManager($session, 'my_custom_token');

        $token = $manager->getToken();

        $this->assertSame('my_custom_token', $token->getId());
    }

    public function testTokenValueLength(): void
    {
        $session = $this->createMockSession();
        $manager = new CsrfTokenManager($session);

        $token = $manager->getToken();

        // Token should be 32 bytes = 64 hex characters
        $this->assertSame(64, strlen($token->getValue()));
    }

    public function testTimingSafeComparison(): void
    {
        $correctValue = bin2hex(random_bytes(32));
        $session = $this->createMockSession([
            '_csrf_tokens' => ['test' => $correctValue]
        ]);
        $manager = new CsrfTokenManager($session);

        // Create a token with similar but wrong value
        $wrongValue = substr($correctValue, 0, -1) . 'x';
        $token = new CsrfToken('test', $wrongValue);

        $this->assertFalse($manager->isTokenValid($token));
    }

    public function testMultipleTokensCanCoexist(): void
    {
        $session = $this->createMockSession();
        $manager = new CsrfTokenManager($session);

        $loginToken = $manager->getToken('login');
        $logoutToken = $manager->getToken('logout');
        $deleteToken = $manager->getToken('delete');

        $this->assertNotSame($loginToken->getValue(), $logoutToken->getValue());
        $this->assertNotSame($loginToken->getValue(), $deleteToken->getValue());
        $this->assertNotSame($logoutToken->getValue(), $deleteToken->getValue());
    }

    public function testRefreshTokenWithDefaultId(): void
    {
        $session = $this->createMockSession();
        $manager = new CsrfTokenManager($session, 'default');

        $token1 = $manager->getToken();
        $token2 = $manager->refreshToken();

        $this->assertNotSame($token1->getValue(), $token2->getValue());
        $this->assertSame('default', $token2->getId());
    }
}
