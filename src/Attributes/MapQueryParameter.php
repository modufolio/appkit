<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Attributes;

/**
 * Maps a single query parameter to a controller argument.
 *
 * Supports arguments typed as int, float, bool, string, array, a BackedEnum
 * or a Symfony Uid. The argument name is used as the query parameter name
 * unless an explicit name is given.
 *
 * @see https://php.net/manual/filter.constants for filter, flags and options
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
class MapQueryParameter
{
    /**
     * @param string|null         $name    query parameter name; defaults to the argument name
     * @param int|null            $filter  filter_var() filter; deduced from the type hint when null
     * @param int                 $flags   filter_var() flags (e.g. FILTER_NULL_ON_FAILURE to allow null instead of a 400)
     * @param array<string, mixed> $options filter_var() options (e.g. ['min_range' => 1])
     */
    public function __construct(
        public ?string $name = null,
        public ?int $filter = null,
        public int $flags = 0,
        public array $options = [],
    ) {
    }
}
