<?php

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
