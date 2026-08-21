<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\Exception;

/**
 * @author    Fabien Potencier <fabien@symfony.com>
 * @author    Alexander <iam.asm89@gmail.com>
 *
 * @see       https://github.com/symfony/security-core
 *
 * @copyright Fabien Potencier <fabien@symfony.com>
 * @license   https://opensource.org/licenses/MIT
 */
class BadCredentialsException extends AuthenticationException
{
    public function getMessageKey(): string
    {
        return 'Invalid credentials.';
    }
}
