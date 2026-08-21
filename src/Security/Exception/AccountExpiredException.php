<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\Exception;

/**
 * Exception thrown when attempting to authenticate with an expired account
 * For SOC 2 compliance: CC6.1 - Account expiration controls.
 *
 * @author    Fabien Potencier <fabien@symfony.com>
 * @author    Alexander <iam.asm89@gmail.com>
 *
 * @see       https://github.com/symfony/security-core
 *
 * @copyright Fabien Potencier <fabien@symfony.com>
 * @license   https://opensource.org/licenses/MIT
 */
class AccountExpiredException extends AccountStatusException
{
    public function getMessageKey(): string
    {
        return 'Your account has expired. Please contact an administrator.';
    }
}
