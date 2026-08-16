<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Security\User;

use Modufolio\Appkit\Security\User\PasswordAuthenticatedUserInterface;
use Modufolio\Appkit\Security\User\UserPasswordHasher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UserPasswordHasher::class)]
class UserPasswordHasherTest extends TestCase
{
    /**
     * Fast bcrypt config so the hashing primitives stay cheap under test.
     */
    private function hasher(int $cost = 4): UserPasswordHasher
    {
        return new UserPasswordHasher(['algo' => PASSWORD_BCRYPT, 'options' => ['cost' => $cost]]);
    }

    private function user(?string $password): PasswordAuthenticatedUserInterface
    {
        return new class($password) implements PasswordAuthenticatedUserInterface {
            public function __construct(private ?string $password)
            {
            }

            public function getPassword(): ?string
            {
                return $this->password;
            }

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

            public function eraseCredentials(): void
            {
            }

            public function getUserIdentifier(): string
            {
                return 'test@example.com';
            }

            public function isEnabled(): bool
            {
                return true;
            }
        };
    }

    public function testHashPasswordProducesVerifiableHash(): void
    {
        $hasher = $this->hasher();
        $hash = $hasher->hashPassword($this->user(null), 'secret');

        $this->assertNotSame('secret', $hash);
        $this->assertTrue(password_verify('secret', $hash));
    }

    public function testIsPasswordValidReturnsTrueForMatchingPassword(): void
    {
        $hasher = $this->hasher();
        $hash = $hasher->hashPassword($this->user(null), 'secret');

        $this->assertTrue($hasher->isPasswordValid($this->user($hash), 'secret'));
    }

    public function testIsPasswordValidReturnsFalseForWrongPassword(): void
    {
        $hasher = $this->hasher();
        $hash = $hasher->hashPassword($this->user(null), 'secret');

        $this->assertFalse($hasher->isPasswordValid($this->user($hash), 'wrong'));
    }

    public function testIsPasswordValidReturnsFalseWhenStoredPasswordIsNull(): void
    {
        $hasher = $this->hasher();

        $this->assertFalse($hasher->isPasswordValid($this->user(null), 'secret'));
    }

    public function testNeedsRehashIsTrueWhenCostDiffers(): void
    {
        $hashedAtCost4 = $this->hasher(4)->hashPassword($this->user(null), 'secret');

        $this->assertTrue($this->hasher(6)->needsRehash($this->user($hashedAtCost4)));
    }

    public function testNeedsRehashIsFalseForSameConfig(): void
    {
        $hasher = $this->hasher(4);
        $hash = $hasher->hashPassword($this->user(null), 'secret');

        $this->assertFalse($hasher->needsRehash($this->user($hash)));
    }

    public function testNeedsRehashIsFalseWhenStoredPasswordIsNull(): void
    {
        $this->assertFalse($this->hasher()->needsRehash($this->user(null)));
    }

    public function testVerifyDummyRunsWithoutThrowingForNormalPassword(): void
    {
        $this->expectNotToPerformAssertions();

        $this->hasher()->verifyDummy('secret');
    }

    public function testVerifyDummyRunsWithoutThrowingForOversizedPassword(): void
    {
        $this->expectNotToPerformAssertions();

        $this->hasher()->verifyDummy(str_repeat('a', UserPasswordHasher::MAX_PASSWORD_LENGTH + 1));
    }

    public function testHashPasswordThrowsOnOversizedPassword(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->hasher()->hashPassword($this->user(null), str_repeat('a', UserPasswordHasher::MAX_PASSWORD_LENGTH + 1));
    }

    public function testHashPasswordAcceptsPasswordAtMaxLength(): void
    {
        $hasher = $this->hasher();
        $password = str_repeat('a', UserPasswordHasher::MAX_PASSWORD_LENGTH);

        // bcrypt only hashes the first 72 bytes, so this stays a valid round-trip.
        $hash = $hasher->hashPassword($this->user(null), $password);

        $this->assertTrue($hasher->isPasswordValid($this->user($hash), $password));
    }

    public function testIsPasswordValidReturnsFalseForOversizedPassword(): void
    {
        $hasher = $this->hasher();
        $hash = $hasher->hashPassword($this->user(null), 'secret');

        $this->assertFalse($hasher->isPasswordValid($this->user($hash), str_repeat('a', UserPasswordHasher::MAX_PASSWORD_LENGTH + 1)));
    }
}
