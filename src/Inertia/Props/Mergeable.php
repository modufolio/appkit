<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Inertia\Props;

/**
 * A prop the client merges into what it already holds instead of replacing.
 * At the root by default; `append()` / `prepend()` with a path merge inside
 * a wrapper (`data` of a paginated page); `matchOn()` names the key items
 * are matched on so an updated item replaces its older self.
 */
interface Mergeable
{
    public function shouldMerge(): bool;

    public function shouldDeepMerge(): bool;

    /** @return list<string> */
    public function matchesOn(): array;

    public function appendsAtRoot(): bool;

    public function prependsAtRoot(): bool;

    /** @return list<string> */
    public function appendsAtPaths(): array;

    /** @return list<string> */
    public function prependsAtPaths(): array;
}
