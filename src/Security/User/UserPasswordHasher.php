<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\User;

class UserPasswordHasher implements UserPasswordHasherInterface
{
    /**
     * Maximum accepted plain-password length in bytes. Mirrors Symfony's
     * PasswordHasherInterface::MAX_PASSWORD_LENGTH. Feeding arbitrarily long
     * strings to password_hash()/password_verify() is a denial-of-service
     * vector (bcrypt/argon2 cost scales with input), so oversized inputs are
     * rejected before ever reaching the hashing primitives.
     */
    public const MAX_PASSWORD_LENGTH = 4096;

    private string|int $algorithm;

    /** @var array<string, mixed> */
    private array $options;

    private ?string $dummyHash = null;

    /**
     * @param array{algo?: string|int|null, options?: array<string, mixed>} $options
     *                                                                               options are passed straight to password_hash() and must match the algo
     *                                                                               (e.g. 'cost' for bcrypt, 'memory_cost'/'time_cost'/'threads' for argon2).
     *                                                                               When omitted, PHP applies algorithm-appropriate defaults.
     */
    public function __construct(array $options = [])
    {
        $this->algorithm = $options['algo'] ?? PASSWORD_ARGON2ID;
        $this->options = $options['options'] ?? [];
    }

    public function hashPassword(PasswordAuthenticatedUserInterface $user, #[\SensitiveParameter] string $plainPassword): string
    {
        if ($this->isPasswordTooLong($plainPassword)) {
            throw new \InvalidArgumentException('Invalid password.');
        }

        return password_hash($plainPassword, $this->algorithm, $this->options);
    }

    public function isPasswordValid(PasswordAuthenticatedUserInterface $user, #[\SensitiveParameter] string $plainPassword): bool
    {
        $hashedPassword = $user->getPassword();

        if (null === $hashedPassword) {
            return false;
        }

        if ($this->isPasswordTooLong($plainPassword)) {
            return false;
        }

        return password_verify($plainPassword, $hashedPassword);
    }

    public function needsRehash(PasswordAuthenticatedUserInterface $user): bool
    {
        $hashedPassword = $user->getPassword();

        if (null === $hashedPassword) {
            return false;
        }

        return password_needs_rehash($hashedPassword, $this->algorithm, $this->options);
    }

    public function verifyDummy(#[\SensitiveParameter] string $plainPassword): void
    {
        if ($this->isPasswordTooLong($plainPassword)) {
            return;
        }

        // Lazily computed once; ~one hash worth of cost on first call, then constant verify time.
        $this->dummyHash ??= password_hash(bin2hex(random_bytes(16)), $this->algorithm, $this->options);
        password_verify($plainPassword, $this->dummyHash);
    }

    /**
     * Guards the hashing primitives against oversized inputs (bcrypt/argon2 DoS).
     * Mirrors Symfony's CheckPasswordLengthTrait semantics.
     */
    private function isPasswordTooLong(#[\SensitiveParameter] string $plainPassword): bool
    {
        return strlen($plainPassword) > self::MAX_PASSWORD_LENGTH;
    }
}
