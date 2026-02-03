<?php

namespace Modufolio\Appkit\Core;

/**
 * Enum representing application environments.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
enum Environment: string
{
    case DEV = 'dev';
    case TEST = 'test';
    case PROD = 'prod';

    public function isDev(): bool
    {
        return $this === self::DEV;
    }

    public function isTest(): bool
    {
        return $this === self::TEST;
    }

    public function isProd(): bool
    {
        return $this === self::PROD;
    }
}
