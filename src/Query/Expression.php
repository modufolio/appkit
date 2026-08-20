<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Query;

use Modufolio\Appkit\Toolkit\A;

/**
 * The Expression class adds support for simple shorthand
 * comparisons (`a ? b : c`, `a ?: c` and `a ?? b`).
 *
 * @author    Nico Hoffmann <nico@getkirby.com>
 *
 * @see      https://getkirby.com
 *
 * @copyright Bastian Allgeier
 * @license   https://opensource.org/licenses/MIT
 */
final class Expression implements Resolvable
{
    /**
     * @param list<string|Resolvable> $parts
     */
    public function __construct(
        public array $parts,
    ) {
    }

    /**
     * Parses an expression string into its parts.
     */
    public static function factory(string $expression, ?Query $parent = null): static|Segments
    {
        // split into different expression parts and operators
        $parts = static::parse($expression);

        // shortcut: if expression has only one part, directly
        // continue with the segments chain
        if (1 === count($parts)) {
            return Segments::factory(query: $parts[0], parent: $parent);
        }

        // turn all non-operator parts into an Argument
        // which takes care of converting string, arrays booleans etc.
        // into actual types and treats all other parts as their own queries
        $parts = A::map(
            $parts,
            static fn ($part) => in_array($part, ['?', ':', '?:', '??'])
                    ? $part
                    : Argument::factory($part)
        );

        return new static(parts: array_values($parts));
    }

    /**
     * Splits a comparison string into an array
     * of expressions and operators.
     *
     * @internal
     *
     * @return list<string>
     */
    public static function parse(string $string): array
    {
        // split by multiples of `?` and `:`, but not inside skip groups
        // (parantheses, quotes etc.)
        $parts = preg_split(
            '/\s+([\?\:]+)\s+|'.Arguments::OUTSIDE.'/',
            trim($string),
            flags: PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
        );

        return false === $parts ? [] : $parts;
    }

    /**
     * Resolves the expression by evaluating
     * the supported comparisons and consecutively
     * resolving the resulting query/argument.
     *
     * @param array<string, mixed>|object $data
     */
    public function resolve(array|object $data = []): mixed
    {
        $base = null;

        foreach ($this->parts as $index => $part) {
            // `a ?? b`
            // if the base/previous (e.g. `a`) isn't null,
            // stop the expression chain and return `a`
            if ('??' === $part) {
                if (null !== $base) {
                    return $base;
                }

                continue;
            }

            // `a ?: b`
            // if `a` isn't false, return `a`, otherwise `b`
            if ('?:' === $part) {
                if (false != $base) {
                    return $base;
                }

                return $this->operand($index + 1, $data);
            }

            // `a ? b : c`
            // if `a` isn't false, return `b`, otherwise `c`
            if ('?' === $part) {
                if (($this->parts[$index + 2] ?? null) !== ':') {
                    throw new \LogicException('Query: Incomplete ternary operator (missing matching `? :`)');
                }

                if (false != $base) {
                    return $this->operand($index + 1, $data);
                }

                return $this->operand($index + 3, $data);
            }

            $base = $this->operand($index, $data);
        }

        return $base;
    }

    /**
     * Resolves the expression part at $index, rejecting operator tokens that
     * only appear there when the expression is malformed (e.g. `a ? : b`).
     *
     * @param array<string, mixed>|object $data
     */
    private function operand(int $index, array|object $data): mixed
    {
        $part = $this->parts[$index] ?? null;

        if (!$part instanceof Resolvable) {
            throw new \LogicException('Query: Malformed expression; expected a value.');
        }

        return $part->resolve($data);
    }
}
