<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Query;

/**
 * The Query class can be used to query arrays and objects,
 * including their methods with a very simple string-based syntax.
 *
 * Namespace structure - what handles what:
 * - Query			Main interface, direct entries
 * - Expression		Simple comparisons (`a ? b :c`)
 * - Segments		Chain of method calls (`site.find('notes').url`)
 * - Segment		Single method call (`find('notes')`)
 * - Arguments		Method call parameters (`'template', '!=', 'note'`)
 * - Argument		Single parameter, resolving into actual types
 *
 * @author    Bastian Allgeier <bastian@getkirby.com>,
 * 			  Nico Hoffmann <nico@getkirby.com>
 *
 * @see      https://getkirby.com
 *
 * @copyright Bastian Allgeier
 * @license   https://opensource.org/licenses/MIT
 */
final class Query
{
    /**
     * Default data entries.
     */
    public static array $entries = [];

    /**
     * Creates a new Query object.
     */
    public function __construct(
        public ?string $query = null,
    ) {
        if (null !== $query) {
            $this->query = trim($query);
        }
    }

    /**
     * Creates a new Query object.
     */
    public static function factory(?string $query): static
    {
        return new static(query: $query);
    }

    /**
     * Method to help classes that extend Query
     * to intercept a segment's result.
     */
    public function intercept(mixed $result): mixed
    {
        return $result;
    }

    /**
     * Returns the query result if anything
     * can be found, otherwise returns null.
     */
    public function resolve(array|object $data = []): mixed
    {
        if (true === empty($this->query)) {
            return $data;
        }

        // merge data with default entries
        if (true === is_array($data)) {
            $data = array_merge(static::$entries, $data);
        }

        // direct data array access via key
        if (
            true === is_array($data)
            && true === array_key_exists($this->query, $data)
        ) {
            $value = $data[$this->query];

            if ($value instanceof \Closure) {
                $value = $value();
            }

            return $value;
        }

        // loop through all segments to resolve query
        return Expression::factory($this->query, $this)->resolve($data);
    }
}
