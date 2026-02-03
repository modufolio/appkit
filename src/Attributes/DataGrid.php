<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Attributes;

#[\Attribute(\Attribute::TARGET_PARAMETER)]
class DataGrid
{
    /**
     * @param class-string $schema The GridSchema class to use
     * @param class-string|null $source The entity class or null for array sources
     */
    public function __construct(
        public string $schema,
        public ?string $source = null,
    ) {
    }
}
