<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Inertia\Props;

/**
 * A prop left out of the first response and fetched by the client right
 * after the page renders, in a partial reload it issues itself; listed under
 * `deferredProps` by group so a group is fetched in one request. May
 * `merge()` (a list that grows page by page), be `once()`, or `rescue`
 * (a failure yields null instead of an error page).
 */
final class DeferredProp extends Prop implements Deferrable, IgnoreFirstLoad, Mergeable, Onceable, Rescuable
{
    use DefersProps;
    use MergesProps;
    use ResolvesOnce;

    private function __construct(\Closure $value, string $group, private readonly bool $rescue)
    {
        parent::__construct($value);
        $this->defer($group);
    }

    public static function of(\Closure $value, string $group = 'default', bool $rescue = false): self
    {
        return new self($value, $group, $rescue);
    }

    public function shouldRescue(): bool
    {
        return $this->rescue;
    }

    /** The name group() had in this package's first release. */
    public function merges(): bool
    {
        return $this->shouldMerge();
    }

    public function mergesDeep(): bool
    {
        return $this->shouldDeepMerge();
    }
}
