<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Attributes;

/**
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
class MapFilter
{
    public function __construct(
        public ?string $name = null,
    ) {
    }
}
