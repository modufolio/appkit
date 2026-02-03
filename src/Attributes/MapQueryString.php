<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Attributes;

#[\Attribute(\Attribute::TARGET_PARAMETER)]
class MapQueryString
{
    public function __construct(
        public ?string $name = null
    ) {
    }
}
