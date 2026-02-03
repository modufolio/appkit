<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Attributes;

#[\Attribute(\Attribute::TARGET_PARAMETER)]
class FindEntity
{
    public function __construct(
        public array $criteria = []
    ) {
    }
}
