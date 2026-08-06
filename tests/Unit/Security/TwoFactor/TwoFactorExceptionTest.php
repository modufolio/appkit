<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Security\TwoFactor;

use Modufolio\Appkit\Security\TwoFactor\TwoFactorException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TwoFactorException::class)]
class TwoFactorExceptionTest extends TestCase
{
    public function testTooManyFailedAttempts(): void
    {
        $exception = TwoFactorException::tooManyFailedAttempts(5, 5);

        $this->assertSame(
            '2FA verification failed. Too many attempts (5/5). Please try again later.',
            $exception->getMessage()
        );
    }

    public function testAlreadyEnabled(): void
    {
        $this->assertSame(
            'Two-factor authentication is already enabled for this user.',
            TwoFactorException::alreadyEnabled()->getMessage()
        );
    }

    public function testNotEnabled(): void
    {
        $this->assertSame(
            'Two-factor authentication is not enabled for this user.',
            TwoFactorException::notEnabled()->getMessage()
        );
    }

    public function testInvalidCode(): void
    {
        $this->assertSame(
            'Invalid two-factor authentication code.',
            TwoFactorException::invalidCode()->getMessage()
        );
    }

    public function testInvalidBackupCode(): void
    {
        $this->assertSame('Invalid backup code.', TwoFactorException::invalidBackupCode()->getMessage());
    }

    public function testSecretNotFound(): void
    {
        $this->assertSame(
            'Two-factor authentication secret not found.',
            TwoFactorException::secretNotFound()->getMessage()
        );
    }

    public function testError(): void
    {
        $this->assertSame('Custom message', TwoFactorException::error('Custom message')->getMessage());
    }
}
