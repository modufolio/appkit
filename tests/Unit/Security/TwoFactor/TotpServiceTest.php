<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Security\TwoFactor;

use Modufolio\Appkit\Security\TwoFactor\TwoFactorException;
use Modufolio\Appkit\Security\TwoFactor\TwoFactorSecret;
use Modufolio\Appkit\Tests\App\Entity\User;
use Modufolio\Appkit\Tests\Case\AppTestCase;
use OTPHP\TOTP;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Unit coverage for the TOTP hardening in TotpService: the replay guard and the
 * time-based lockout. Both are time-sensitive, so the service's injected clock
 * is frozen with the ClockSensitiveTrait to make step accounting deterministic.
 */
class TotpServiceTest extends AppTestCase
{
    use ClockSensitiveTrait;

    protected function setUp(): void
    {
        parent::setUp();

        $this->refreshDatabase();
        $this->loadFixtures();
    }

    public function testVerifyCodeRejectsAReplayedStep(): void
    {
        self::mockTime('2024-01-01 00:00:00');

        $service = $this->app()->totpService();
        $secret = $service->generateSecret($this->fixtureUser());
        $code = $this->codeFor($secret);

        // First presentation of the code is accepted.
        $this->assertTrue($service->verifyCode($secret, $code));

        // Replaying the same code within its window is rejected — and must not
        // count as a brute-force failure, so a benign double-submit can't push
        // the user toward a lockout.
        $this->assertFalse($service->verifyCode($secret, $code));
        $this->assertSame(0, $secret->getFailedAttempts());
    }

    public function testVerifyCodeLocksOutAfterTooManyFailures(): void
    {
        self::mockTime('2024-01-01 00:00:00');

        $service = $this->app()->totpService();
        $secret = $service->generateSecret($this->fixtureUser());

        // Five wrong codes trip the lockout.
        for ($i = 0; $i < 5; ++$i) {
            $this->assertFalse($service->verifyCode($secret, '000000'));
        }

        $this->assertSame(5, $secret->getFailedAttempts());
        $this->assertNotNull($secret->getLockedUntil());

        // While locked, any further attempt throws regardless of correctness.
        $this->expectException(TwoFactorException::class);
        $service->verifyCode($secret, '000000');
    }

    public function testLockoutClearsAfterItsWindowElapses(): void
    {
        $clock = self::mockTime('2024-01-01 00:00:00');

        $service = $this->app()->totpService();
        $secret = $service->generateSecret($this->fixtureUser());

        for ($i = 0; $i < 5; ++$i) {
            $service->verifyCode($secret, '000000');
        }
        $this->assertNotNull($secret->getLockedUntil());

        // Advance past the 15-minute lockout window. The next attempt clears the
        // expired lockout and a fresh, valid code succeeds.
        $clock->sleep(900);

        $this->assertTrue($service->verifyCode($secret, $this->codeFor($secret)));
        $this->assertNull($secret->getLockedUntil());
        $this->assertSame(0, $secret->getFailedAttempts());
    }

    public function testInvalidBackupCodeCountsTowardLockout(): void
    {
        self::mockTime('2024-01-01 00:00:00');

        $service = $this->app()->totpService();
        $secret = $service->generateSecret($this->fixtureUser());

        // Wrong backup codes are brute-forceable too, so they share the lockout.
        for ($i = 0; $i < 5; ++$i) {
            $this->assertFalse($service->verifyBackupCode($secret, 'not-a-real-code'));
        }
        $this->assertNotNull($secret->getLockedUntil());

        $this->expectException(TwoFactorException::class);
        $service->verifyBackupCode($secret, 'not-a-real-code');
    }

    /**
     * A currently-valid TOTP code for the secret, computed against the frozen
     * clock (MockClock leaves PHP's native time() untouched).
     */
    private function codeFor(TwoFactorSecret $secret): string
    {
        $now = Clock::get()->now()->getTimestamp();

        return TOTP::createFromSecret($secret->getSecret())->at($now);
    }

    private function fixtureUser(): User
    {
        $user = $this->app()->entityManager()
            ->getRepository(User::class)
            ->findOneBy(['email' => 'johndoe@example.com']);

        $this->assertNotNull($user, 'Fixture user not found.');

        return $user;
    }
}
