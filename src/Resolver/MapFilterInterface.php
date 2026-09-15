<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Resolver;

/**
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
interface MapFilterInterface
{
    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self;
}
