<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Query;

use Modufolio\Appkit\Toolkit\A;
use Modufolio\Appkit\Toolkit\Collection;

/**
 * The Segments class helps splitting a
 * query string into processable segments.
 *
 * @author    Nico Hoffmann <nico@getkirby.com>
 *
 * @see      https://getkirby.com
 *
 * @copyright Bastian Allgeier
 * @license   https://opensource.org/licenses/MIT
 */
final class Segments extends Collection implements Resolvable
{
    /**
     * @param array<int|string, mixed> $data
     */
    public function __construct(
        array $data = [],
        protected ?Query $parent = null,
    ) {
        parent::__construct($data);
    }

    /**
     * Split query string into segments by dot
     * but not inside (nested) parens.
     */
    public static function factory(string $query, ?Query $parent = null): static
    {
        $segments = static::parse($query);
        $position = 0;

        $segments = A::map(
            $segments,
            static function ($segment) use (&$position) {
                // leave connectors as they are
                if (true === in_array($segment, ['.', '?.'])) {
                    return $segment;
                }

                // turn all other parts into Segment objects
                // and pass their position in the chain (ignoring connectors)
                ++$position;

                return Segment::factory($segment, $position - 1);
            }
        );

        return new static($segments, $parent);
    }

    /**
     * Splits the string of a segment chaing into an
     * array of segments as well as conenctors (`.` or `?.`).
     *
     * @internal
     *
     * @return list<string>
     */
    public static function parse(string $string): array
    {
        $segments = preg_split(
            '/(\??\.)|(\(([^()]+|(?2))*+\))(*SKIP)(*FAIL)/',
            trim($string),
            flags: PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
        );

        return false === $segments ? [] : $segments;
    }

    /**
     * Resolves the segments chain by looping through
     * each segment call to be applied to the value of
     * all previous segment calls, returning gracefully at
     * `?.` when current value is `null`.
     */
    /**
     * @param array<string, mixed>|object $data
     */
    public function resolve(array|object $data = []): mixed
    {
        $value = null;

        foreach ($this->data as $segment) {
            // optional chaining: stop if current value is null
            if ('?.' === $segment && null === $value) {
                return null;
            }

            // for regular connectors and optional chaining on non-null,
            // just skip this connecting segment
            if ('.' === $segment || '?.' === $segment) {
                continue;
            }

            // offer possibility to intercept on objects
            if (null !== $value) {
                $value = $this->parent?->intercept($value) ?? $value;
            }

            $value = $segment->resolve($value, $data);
        }

        return $value;
    }
}
