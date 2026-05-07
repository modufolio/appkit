<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Security\User;

use Modufolio\Appkit\Security\Exception\AccountExpiredException;
use Modufolio\Appkit\Security\Exception\CredentialsExpiredException;
use Modufolio\Appkit\Security\Exception\DisabledAccountException;
use Modufolio\Appkit\Security\Exception\LockedAccountException;
use Psr\Log\LoggerInterface;

/**
 * Service for checking user account lifecycle status during authentication.
 *
 * Lock and expiry checks are opt-in via {@see LockableUserInterface},
 * {@see ExpirableUserInterface} and {@see CredentialsExpirableUserInterface}.
 *
 * For SOC 2 compliance: CC6.1, CC6.2, CC6.7, CC7.2
 */
class UserChecker implements UserCheckerInterface
{
    public function __construct(
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Pre-authentication checks: enabled, lock, account expiry.
     *
     * @throws DisabledAccountException if account is disabled
     * @throws LockedAccountException if account is locked
     * @throws AccountExpiredException if account has expired
     */
    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user->isEnabled()) {
            $this->logger?->warning('Authentication attempted on disabled account', [
                'user_id' => $user->getId(),
                'email'   => $user->getEmail(),
                'reason'  => 'account_disabled',
            ]);

            throw new DisabledAccountException(
                'Your account has been disabled. Please contact an administrator.',
            );
        }

        if ($user instanceof LockableUserInterface && $user->isLocked()) {
            $this->logger?->warning('Authentication attempted on locked account', [
                'user_id'       => $user->getId(),
                'email'         => $user->getEmail(),
                'locked_at'     => $user->getLockedAt()?->format('Y-m-d H:i:s'),
                'locked_reason' => $user->getLockedReason(),
                'reason'        => 'account_locked',
            ]);

            throw new LockedAccountException(
                $user->getLockedReason()
                ?? 'Your account has been locked. Please contact an administrator.',
            );
        }

        if ($user instanceof ExpirableUserInterface && $user->isAccountExpired()) {
            $this->logger?->warning('Authentication attempted on expired account', [
                'user_id'    => $user->getId(),
                'email'      => $user->getEmail(),
                'expired_at' => $user->getAccountExpiresAt()?->format('Y-m-d H:i:s'),
                'reason'     => 'account_expired',
            ]);

            throw new AccountExpiredException(
                'Your account has expired. Please contact an administrator.',
            );
        }
    }

    /**
     * Post-authentication checks: credentials expiry.
     *
     * @throws CredentialsExpiredException if credentials have expired
     */
    public function checkPostAuth(UserInterface $user): void
    {
        if ($user instanceof CredentialsExpirableUserInterface && $user->isCredentialsExpired()) {
            $this->logger?->info('User authenticated but credentials expired', [
                'user_id'                => $user->getId(),
                'email'                  => $user->getEmail(),
                'credentials_expired_at' => $user->getCredentialsExpireAt()?->format('Y-m-d H:i:s'),
                'reason'                 => 'credentials_expired',
            ]);

            throw new CredentialsExpiredException(
                'Your credentials have expired. Please reset your password.',
            );
        }
    }
}
