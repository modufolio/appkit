<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Security\Csrf;

use Modufolio\Appkit\Security\Csrf\CsrfToken;
use PHPUnit\Framework\TestCase;

class CsrfTokenTest extends TestCase
{
    public function testConstructorWithValidParameters(): void
    {
        $token = new CsrfToken('login', 'abc123def456');

        $this->assertInstanceOf(CsrfToken::class, $token);
        $this->assertSame('login', $token->getId());
        $this->assertSame('abc123def456', $token->getValue());
    }

    public function testGetId(): void
    {
        $token = new CsrfToken('delete_user', 'randomvalue');

        $this->assertSame('delete_user', $token->getId());
    }

    public function testGetValue(): void
    {
        $token = new CsrfToken('form_token', 'secure_random_value');

        $this->assertSame('secure_random_value', $token->getValue());
    }

    public function testToString(): void
    {
        $token = new CsrfToken('test', 'token_value');

        $this->assertSame('token_value', (string)$token);
        $this->assertSame('token_value', $token->__toString());
    }

    public function testConstructorThrowsExceptionForEmptyId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CSRF token ID cannot be empty');

        new CsrfToken('', 'value');
    }

    public function testConstructorThrowsExceptionForEmptyValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CSRF token value cannot be empty');

        new CsrfToken('id', '');
    }

    public function testTokenIsImmutable(): void
    {
        $token = new CsrfToken('test', 'value');

        // Get original values
        $originalId = $token->getId();
        $originalValue = $token->getValue();

        // Calling getters multiple times should return same values
        $this->assertSame($originalId, $token->getId());
        $this->assertSame($originalValue, $token->getValue());
    }

    public function testDifferentTokensWithSameValues(): void
    {
        $token1 = new CsrfToken('login', 'value123');
        $token2 = new CsrfToken('login', 'value123');

        // Same values but different instances
        $this->assertNotSame($token1, $token2);
        $this->assertSame($token1->getId(), $token2->getId());
        $this->assertSame($token1->getValue(), $token2->getValue());
    }

    public function testDifferentTokensWithDifferentIds(): void
    {
        $token1 = new CsrfToken('login', 'value123');
        $token2 = new CsrfToken('logout', 'value123');

        $this->assertNotSame($token1->getId(), $token2->getId());
        $this->assertSame($token1->getValue(), $token2->getValue());
    }

    public function testTokenWithSpecialCharacters(): void
    {
        $specialValue = '!@#$%^&*()_+-=[]{}|;:,.<>?';
        $token = new CsrfToken('special', $specialValue);

        $this->assertSame($specialValue, $token->getValue());
    }

    public function testTokenWithLongValues(): void
    {
        $longId = str_repeat('a', 100);
        $longValue = str_repeat('b', 1000);

        $token = new CsrfToken($longId, $longValue);

        $this->assertSame($longId, $token->getId());
        $this->assertSame($longValue, $token->getValue());
    }
}
