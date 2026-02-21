<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Tests\App\Entity;

use Modufolio\Appkit\Security\TwoFactor\UserTotpSecretInterface;
use Modufolio\Appkit\Tests\App\Entity\Traits\Timestampable;
use Modufolio\Appkit\Tests\App\Repository\UserTotpSecretRepository;
use Modufolio\Appkit\Security\User\UserInterface;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * UserTotpSecret entity
 *
 * Stores TOTP (Time-based One-Time Password) secrets for two-factor authentication
 * using Google Authenticator or compatible apps.
 */
#[ORM\Entity(repositoryClass: UserTotpSecretRepository::class)]
#[ORM\Table(name: 'user_totp_secrets')]
#[ORM\HasLifecycleCallbacks]
class UserTotpSecret implements UserTotpSecretInterface
{
    use Timestampable;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    /**
     * The user this TOTP secret belongs to
     */
    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private UserInterface $user;

    /**
     * The encrypted TOTP secret (base32 encoded)
     * This is the secret shared between the server and the authenticator app
     */
    #[Assert\NotBlank]
    #[ORM\Column(type: 'text')]
    private string $secret;

    /**
     * Whether 2FA is enabled for this user
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $enabled = false;

    /**
     * Whether the TOTP secret has been confirmed/verified
     * User must verify the code at least once before 2FA becomes active
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $confirmed = false;

    /**
     * Date when 2FA was enabled
     */
    #[ORM\Column(name: 'enabled_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $enabledAt = null;

    /**
     * Date when the TOTP secret was last used successfully
     * For security monitoring and audit
     */
    #[ORM\Column(name: 'last_used_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    /**
     * Number of consecutive failed attempts
     * For brute force protection
     */
    #[ORM\Column(name: 'failed_attempts', type: 'integer', options: ['default' => 0])]
    private int $failedAttempts = 0;

    /**
     * Backup codes for account recovery (JSON array)
     * Hashed codes that can be used once each if user loses access to authenticator
     */
    #[ORM\Column(name: 'backup_codes', type: 'json', nullable: true)]
    private ?array $backupCodes = null;

    public ?array $plainBackupCodes = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): UserInterface
    {
        return $this->user;
    }

    public function setUser(UserInterface $user): void
    {
        $this->user = $user;
    }

    public function getSecret(): string
    {
        return $this->secret;
    }

    public function setSecret(string $secret): void
    {
        $this->secret = $secret;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;

        if ($enabled && $this->enabledAt === null) {
            $this->enabledAt = new \DateTimeImmutable();
        }
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed;
    }

    public function setConfirmed(bool $confirmed): void
    {
        $this->confirmed = $confirmed;
    }

    public function getEnabledAt(): ?\DateTimeImmutable
    {
        return $this->enabledAt;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function setLastUsedAt(?\DateTimeImmutable $lastUsedAt): void
    {
        $this->lastUsedAt = $lastUsedAt;
    }

    public function getFailedAttempts(): int
    {
        return $this->failedAttempts;
    }

    public function incrementFailedAttempts(): void
    {
        $this->failedAttempts++;
    }

    public function resetFailedAttempts(): void
    {
        $this->failedAttempts = 0;
    }

    public function getBackupCodes(): ?array
    {
        return $this->backupCodes;
    }

    public function setBackupCodes(?array $backupCodes): void
    {
        $this->backupCodes = $backupCodes;
    }

    /**
     * Check if a backup code exists
     */
    public function hasBackupCode(string $code): bool
    {
        if ($this->backupCodes === null) {
            return false;
        }

        foreach ($this->backupCodes as $hashedCode) {
            if (password_verify($code, $hashedCode)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Remove a used backup code
     */
    public function removeBackupCode(string $code): void
    {
        if ($this->backupCodes === null) {
            return;
        }

        foreach ($this->backupCodes as $index => $hashedCode) {
            if (password_verify($code, $hashedCode)) {
                unset($this->backupCodes[$index]);
                $this->backupCodes = array_values($this->backupCodes);
                return;
            }
        }
    }

    public function getUserIdentifier(): string
    {
        // TODO: Implement getUserIdentifier() method.
    }

    public function setPlainBackupCodes(?array $backupCodes): void
    {
        $this->plainBackupCodes = $backupCodes;
    }
}
