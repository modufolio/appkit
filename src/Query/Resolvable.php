<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Query;

/**
 * A query part that can be resolved against a data set.
 *
 * Implemented by every node the query parser can produce, so a parsed tree can
 * be walked without inspecting concrete node types.
 */
interface Resolvable
{
    /**
     * @param array<string, mixed>|object $data
     */
    public function resolve(array|object $data = []): mixed;
}
