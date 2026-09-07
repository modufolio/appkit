<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Inertia\Props;

/**
 * A prop the client merges into what it already holds instead of replacing:
 * the next page of an infinite list. Listed under `mergeProps`,
 * `prependProps` or `deepMergeProps`; `X-Inertia-Reset` names the ones to
 * replace this once. May be `once()`.
 */
final class MergeProp extends Prop implements Mergeable, Onceable
{
    use MergesProps;
    use ResolvesOnce;

    private function __construct(mixed $value, bool $deep)
    {
        parent::__construct($value);
        $this->merge = true;
        $this->deepMerge = $deep;
    }

    public static function of(mixed $value, bool $deep = false): self
    {
        return new self($value, $deep);
    }

    /** The name shouldDeepMerge() had in this package's first release. */
    public function deep(): bool
    {
        return $this->shouldDeepMerge();
    }
}
