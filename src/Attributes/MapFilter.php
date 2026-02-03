<?php

namespace Modufolio\Appkit\Attributes;

#[\Attribute(\Attribute::TARGET_PARAMETER)]
class MapFilter
{
    public function __construct(
        public ?string $name = null
    ) {
    }
}
