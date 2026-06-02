<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\App\Repository;

use Doctrine\ORM\EntityRepository;
use Modufolio\Appkit\Security\TwoFactor\UserTotpSecretInterface;
use Modufolio\Appkit\Security\TwoFactor\UserTotpSecretRepositoryInterface;
use Modufolio\Appkit\Security\User\UserInterface;

class UserTotpSecretRepository extends EntityRepository implements UserTotpSecretRepositoryInterface
{
    /**
     * Find TOTP secret by user.
     */
    public function findByUser(UserInterface $user): ?UserTotpSecretInterface
    {
        return $this->findOneBy(['user' => $user]);
    }

    /**
     * Check if user has 2FA enabled.
     */
    public function isEnabledForUser(UserInterface $user): bool
    {
        $totpSecret = $this->findByUser($user);

        return null !== $totpSecret && $totpSecret->isEnabled() && $totpSecret->isConfirmed();
    }
}
