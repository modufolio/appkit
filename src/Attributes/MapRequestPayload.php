<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Attributes;

/**
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
class MapRequestPayload
{
    public function __construct(
        public ?string $name = null,
        public bool $throwOnError = true,
    ) {
    }
}
