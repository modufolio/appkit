<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\TwoFactor;

use Doctrine\ORM\EntityManagerInterface;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use Modufolio\Appkit\Security\User\UserInterface;
use OTPHP\TOTP;
use Psr\Clock\ClockInterface;

/**
 * TOTP Service for Two-Factor Authentication
 *
 * Handles secret generation, QR code creation, and code verification
 * for Google Authenticator and compatible apps
 */
class TotpService implements TwoFactorServiceInterface
{
    private const BACKUP_CODES_COUNT = 10;
    private const BACKUP_CODE_LENGTH = 8;

    /** Failed attempts before a temporary lockout kicks in. */
    private const MAX_FAILED_ATTEMPTS = 5;

    /** How long the lockout lasts once the threshold is hit (seconds). */
    private const LOCKOUT_SECONDS = 900; // 15 minutes

    /** Clock-drift tolerance, in TOTP time-steps, accepted either side of "now". */
    private const LEEWAY_PERIODS = 1;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserTotpSecretRepositoryInterface $totpSecretRepository,
        private string $twoFactorEntityClass,
        private ClockInterface $clock,
        private string $issuer = 'Appkit',
    ) {
    }

    /**
     * Generate a new TOTP secret for a user
     */
    public function generateSecret(UserInterface $user): TwoFactorSecret
    {
        // Check if user already has a TOTP secret
        $existingSecret = $this->totpSecretRepository->findByUser($user);

        if ($existingSecret !== null && $existingSecret->isEnabled()) {
            throw new \RuntimeException('User already has 2FA enabled. Disable it first before generating a new secret.');
        }

        // Create TOTP instance
        $totp = TOTP::generate();
        $totp->setLabel($user->getEmail());
        $totp->setIssuer($this->issuer);

        // Create or update TOTP secret entity
        if ($existingSecret === null) {
            $totpSecret = new $this->twoFactorEntityClass();
            $totpSecret->setUser($user);
        } else {
            $totpSecret = $existingSecret;
        }

        $totpSecret->setSecret($totp->getSecret());
        $totpSecret->setEnabled(false);
        $totpSecret->setConfirmed(false);

        $this->entityManager->persist($totpSecret);
        $this->entityManager->flush();

        return $totpSecret;
    }

    /**
     * Get the TOTP provisioning URI for QR code generation
     */
    public function getProvisioningUri(UserTotpSecretInterface $totpSecret): string
    {
        $totp = TOTP::createFromSecret($totpSecret->getSecret());
        $totp->setLabel($totpSecret->getUser()->getEmail());
        $totp->setIssuer($this->issuer);

        return $totp->getProvisioningUri();
    }



    public function generateQrCode(UserTotpSecretInterface $totpSecret): string
    {
        $provisioningUri = $this->getProvisioningUri($totpSecret);

        $builder = new Builder(
            writer: new PngWriter(),
            writerOptions: [],
            validateResult: false,
            data: $provisioningUri,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: 300,
            margin: 10,
            roundBlockSizeMode: RoundBlockSizeMode::Margin
        );

        $result = $builder->build();

        return $result->getDataUri();
    }



    /**
     * Verify a TOTP code.
     *
     * @throws TwoFactorException When currently locked out
     */
    public function verifyCode(TwoFactorSecret $totpSecret, string $code): bool
    {
        $now = $this->clock->now()->getTimestamp();

        // Reject (or clear an expired) lockout before doing any work.
        $this->guardLockout($totpSecret, $now);

        $code = trim($code);
        $totp = TOTP::createFromSecret($totpSecret->getSecret());

        $matchedStep = $this->matchStep($totp, $code, $now);

        if ($matchedStep !== null) {
            $lastStep = $totpSecret->getLastUsedCounter();

            // Replay: the code is cryptographically valid but its step was already
            // consumed. Reject without counting it as a brute-force failure so a
            // benign double-submit doesn't push the user toward a lockout.
            if ($lastStep !== null && $matchedStep <= $lastStep) {
                return false;
            }

            $totpSecret->setLastUsedCounter($matchedStep);
            $totpSecret->resetFailedAttempts();
            $totpSecret->setLockedUntil(null);
            $totpSecret->setLastUsedAt(new \DateTimeImmutable('@' . $now));
            $this->entityManager->flush();

            return true;
        }

        // Invalid code — record the failure (and lock out at the threshold).
        $this->registerFailure($totpSecret, $now);
        $this->entityManager->flush();

        return false;
    }

    /**
     * Clear an expired lockout, or throw if the secret is still locked.
     *
     * @throws TwoFactorException
     */
    private function guardLockout(TwoFactorSecret $totpSecret, int $now): void
    {
        $lockedUntil = $totpSecret->getLockedUntil();

        if ($lockedUntil === null) {
            return;
        }

        if ($lockedUntil->getTimestamp() > $now) {
            throw new TwoFactorException(sprintf(
                'Too many failed attempts. Please try again in %d seconds.',
                $lockedUntil->getTimestamp() - $now,
            ));
        }

        // Lockout window has elapsed — reset so the user can try again.
        $totpSecret->setLockedUntil(null);
        $totpSecret->resetFailedAttempts();
    }

    /**
     * Count a failed attempt and start a lockout once the threshold is reached.
     */
    private function registerFailure(TwoFactorSecret $totpSecret, int $now): void
    {
        $totpSecret->incrementFailedAttempts();

        if ($totpSecret->getFailedAttempts() >= self::MAX_FAILED_ATTEMPTS) {
            $totpSecret->setLockedUntil(new \DateTimeImmutable('@' . ($now + self::LOCKOUT_SECONDS)));
        }
    }

    /**
     * Return the TOTP time-step the submitted code matches within the configured
     * drift tolerance, or null if it matches none. Comparison is constant-time.
     */
    private function matchStep(TOTP $totp, string $code, int $now): ?int
    {
        if ($code === '') {
            return null;
        }

        $period      = $totp->getPeriod();
        $currentStep = intdiv($now, $period);

        for ($offset = -self::LEEWAY_PERIODS; $offset <= self::LEEWAY_PERIODS; $offset++) {
            $step = $currentStep + $offset;
            if ($step < 0) {
                continue;
            }

            if (hash_equals($totp->at($step * $period), $code)) {
                return $step;
            }
        }

        return null;
    }

    /**
     * Verify and enable 2FA for the user
     */
    public function enableTwoFactor(TwoFactorSecret $totpSecret, string $code): bool
    {
        if (!$this->verifyCode($totpSecret, $code)) {
            return false;
        }

        $totpSecret->setEnabled(true);
        $totpSecret->setConfirmed(true);

        // Generate backup codes
        $backupCodes = $this->generateBackupCodes();
        $hashedBackupCodes = array_map(
            fn($code) => password_hash($code, PASSWORD_DEFAULT),
            $backupCodes
        );
        $totpSecret->setBackupCodes($hashedBackupCodes);

        $this->entityManager->flush();

        // Store backup codes in a temporary property for returning to user
        // In production, these should be shown only once
        $totpSecret->setPlainBackupCodes($backupCodes);

        return true;
    }

    /**
     * Disable 2FA for a user
     */
    public function disableTwoFactor(UserInterface $user): void
    {
        $totpSecret = $this->totpSecretRepository->findByUser($user);

        if ($totpSecret === null) {
            return;
        }

        $this->entityManager->remove($totpSecret);
        $this->entityManager->flush();
    }

    /**
     * Verify a backup code.
     *
     * Subject to the same lockout as {@see verifyCode()} (audit L1): otherwise an
     * attacker could sidestep the TOTP lockout by brute-forcing backup codes. A
     * wrong code now counts as a failed attempt.
     *
     * @throws TwoFactorException When currently locked out
     */
    public function verifyBackupCode(TwoFactorSecret $totpSecret, string $code): bool
    {
        $now = $this->clock->now()->getTimestamp();
        $this->guardLockout($totpSecret, $now);

        if (!$totpSecret->hasBackupCode($code)) {
            $this->registerFailure($totpSecret, $now);
            $this->entityManager->flush();

            return false;
        }

        // Remove the used backup code
        $totpSecret->removeBackupCode($code);
        $totpSecret->setLastUsedAt(new \DateTimeImmutable('@' . $now));
        $totpSecret->resetFailedAttempts();
        $totpSecret->setLockedUntil(null);

        $this->entityManager->flush();

        return true;
    }

    /**
     * Regenerate backup codes for a user
     */
    public function regenerateBackupCodes(TwoFactorSecret $totpSecret): array
    {
        if (!$totpSecret->isEnabled()) {
            throw new \RuntimeException('Two-factor authentication must be enabled to regenerate backup codes.');
        }

        // Generate new backup codes
        $backupCodes = $this->generateBackupCodes();
        $hashedBackupCodes = array_map(
            fn($code) => password_hash($code, PASSWORD_DEFAULT),
            $backupCodes
        );

        // Replace old backup codes
        $totpSecret->setBackupCodes($hashedBackupCodes);

        $this->entityManager->flush();

        return $backupCodes;
    }

    /**
     * Generate random backup codes
     */
    private function generateBackupCodes(): array
    {
        $codes = [];

        for ($i = 0; $i < self::BACKUP_CODES_COUNT; $i++) {
            $codes[] = $this->generateBackupCode();
        }

        return $codes;
    }

    /**
     * Generate a single backup code
     */
    private function generateBackupCode(): string
    {
        $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $code = '';

        for ($i = 0; $i < self::BACKUP_CODE_LENGTH; $i++) {
            $code .= $characters[random_int(0, strlen($characters) - 1)];
        }

        // Format as XXXX-XXXX for readability
        return substr($code, 0, 4) . '-' . substr($code, 4, 4);
    }

    /**
     * Check if user has 2FA enabled
     */
    public function isTwoFactorEnabled(UserInterface $user): bool
    {
        return $this->totpSecretRepository->isEnabledForUser($user);
    }

    /**
     * Get TOTP secret for user
     */
    public function getTwoFactorSecret(UserInterface $user): ?TwoFactorSecret
    {
        return $this->totpSecretRepository->findByUser($user);
    }

    /**
     * Alias for getTwoFactorSecret for backwards compatibility
     */
    public function getTotpSecret(UserInterface $user): ?TwoFactorSecret
    {
        return $this->getTwoFactorSecret($user);
    }
}
