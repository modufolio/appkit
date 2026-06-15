<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Attributes;

#[\Attribute(\Attribute::TARGET_PARAMETER)]
class MapEntity
{
    /**
     * @param array<string, mixed> $criteria fixed criteria passed to findOneBy() (e.g. ['status' => 'published'])
     * @param string|null          $class    entity class to load; defaults to the parameter's type hint
     * @param array<string, string>|null $mapping route parameter name => entity field name, merged into the criteria
     * @param list<string>|null    $exclude  criteria keys to drop before querying
     * @param bool                 $stripNull when true, null-valued criteria are removed instead of queried
     * @param string|null          $message  custom message for the 404 thrown when nothing is found
     */
    public function __construct(
        public array $criteria = [],
        public ?string $class = null,
        public ?array $mapping = null,
        public ?array $exclude = null,
        public bool $stripNull = false,
        public ?string $message = null,
    ) {
    }
}
