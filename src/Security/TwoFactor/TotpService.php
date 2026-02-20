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

    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserTotpSecretRepositoryInterface $totpSecretRepository,
        private string $twoFactorEntityClass,
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
     * Verify a TOTP code
     */
    public function verifyCode(TwoFactorSecret $totpSecret, string $code): bool
    {
        // Check for too many failed attempts
        if ($totpSecret->getFailedAttempts() >= 5) {
            throw new \RuntimeException('Too many failed attempts. Please wait 15 minutes before trying again.');
        }

        $totp = TOTP::createFromSecret($totpSecret->getSecret());

        // Allow 1 period (30 seconds) of leeway for time drift
        if ($totp->verify($code, null, 1)) {
            $totpSecret->resetFailedAttempts();
            $totpSecret->setLastUsedAt(new \DateTimeImmutable());
            $this->entityManager->flush();

            return true;
        }

        // Increment failed attempts
        $totpSecret->incrementFailedAttempts();
        $this->entityManager->flush();

        return false;
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
     * Verify a backup code
     */
    public function verifyBackupCode(TwoFactorSecret $totpSecret, string $code): bool
    {
        if (!$totpSecret->hasBackupCode($code)) {
            return false;
        }

        // Remove the used backup code
        $totpSecret->removeBackupCode($code);
        $totpSecret->setLastUsedAt(new \DateTimeImmutable());
        $totpSecret->resetFailedAttempts();

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
