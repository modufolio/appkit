<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Security;

use Modufolio\Appkit\Security\SecurityHelper;
use PHPUnit\Framework\TestCase;

class SecurityHelperTest extends TestCase
{
    public function testGeneratePasswordDefaultLength(): void
    {
        $password = SecurityHelper::generatePassword();

        $this->assertSame(12, strlen($password));
    }

    public function testGeneratePasswordCustomLength(): void
    {
        $password = SecurityHelper::generatePassword(16);

        $this->assertSame(16, strlen($password));
    }

    public function testGeneratePasswordMinimumLength(): void
    {
        // Request length below minimum (8)
        $password = SecurityHelper::generatePassword(5);

        // Should enforce minimum of 8
        $this->assertSame(8, strlen($password));
    }

    public function testGeneratePasswordMaximumLength(): void
    {
        // Request length above maximum (64)
        $password = SecurityHelper::generatePassword(100);

        // Should enforce maximum of 64
        $this->assertSame(64, strlen($password));
    }

    public function testGeneratePasswordContainsLowercase(): void
    {
        $password = SecurityHelper::generatePassword(12);

        $this->assertMatchesRegularExpression('/[a-z]/', $password);
    }

    public function testGeneratePasswordContainsUppercase(): void
    {
        $password = SecurityHelper::generatePassword(12);

        $this->assertMatchesRegularExpression('/[A-Z]/', $password);
    }

    public function testGeneratePasswordContainsNumbers(): void
    {
        $password = SecurityHelper::generatePassword(12);

        $this->assertMatchesRegularExpression('/[0-9]/', $password);
    }

    public function testGeneratePasswordContainsSpecialCharacters(): void
    {
        $password = SecurityHelper::generatePassword(12);

        $this->assertMatchesRegularExpression('/[!@#$%^&*()_\-=+{}\[\]]/', $password);
    }

    public function testGeneratePasswordIsRandom(): void
    {
        $password1 = SecurityHelper::generatePassword(20);
        $password2 = SecurityHelper::generatePassword(20);

        // Passwords should be different
        $this->assertNotSame($password1, $password2);
    }

    public function testGeneratePasswordAllRequiredCharacterTypes(): void
    {
        $password = SecurityHelper::generatePassword(12);

        // Should contain at least one of each required type
        $this->assertMatchesRegularExpression('/[a-z]/', $password, 'Should contain lowercase');
        $this->assertMatchesRegularExpression('/[A-Z]/', $password, 'Should contain uppercase');
        $this->assertMatchesRegularExpression('/[0-9]/', $password, 'Should contain number');
        $this->assertMatchesRegularExpression('/[!@#$%^&*()_\-=+{}\[\]]/', $password, 'Should contain special character');
    }

    public function testRandomCharReturnsCharacterFromString(): void
    {
        $string = 'abc';
        $char = SecurityHelper::randomChar($string);

        $this->assertContains($char, ['a', 'b', 'c']);
    }

    public function testRandomCharIsSingleCharacter(): void
    {
        $string = 'abcdefghijklmnopqrstuvwxyz';
        $char = SecurityHelper::randomChar($string);

        $this->assertSame(1, strlen($char));
    }

    public function testRandomCharWithSingleCharacter(): void
    {
        $string = 'x';
        $char = SecurityHelper::randomChar($string);

        $this->assertSame('x', $char);
    }

    public function testGenerateMultiplePasswords(): void
    {
        $passwords = [];
        for ($i = 0; $i < 10; $i++) {
            $passwords[] = SecurityHelper::generatePassword(15);
        }

        // All passwords should be unique
        $this->assertCount(10, array_unique($passwords));

        // All should be correct length
        foreach ($passwords as $password) {
            $this->assertSame(15, strlen($password));
        }
    }

    public function testGeneratePasswordLength8(): void
    {
        $password = SecurityHelper::generatePassword(8);

        $this->assertSame(8, strlen($password));
        // Even with minimum length, should still have all character types
        $this->assertMatchesRegularExpression('/[a-z]/', $password);
        $this->assertMatchesRegularExpression('/[A-Z]/', $password);
        $this->assertMatchesRegularExpression('/[0-9]/', $password);
        $this->assertMatchesRegularExpression('/[!@#$%^&*()_\-=+{}\[\]]/', $password);
    }

    public function testGeneratePasswordLength64(): void
    {
        $password = SecurityHelper::generatePassword(64);

        $this->assertSame(64, strlen($password));
        $this->assertMatchesRegularExpression('/[a-z]/', $password);
        $this->assertMatchesRegularExpression('/[A-Z]/', $password);
        $this->assertMatchesRegularExpression('/[0-9]/', $password);
        $this->assertMatchesRegularExpression('/[!@#$%^&*()_\-=+{}\[\]]/', $password);
    }
}
