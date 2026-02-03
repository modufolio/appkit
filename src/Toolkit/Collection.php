<?php

namespace Modufolio\Appkit\Toolkit;

use Closure;
use Exception;
use Stringable;

/**
 * The collection class provides a nicer
 * interface around arrays of arrays or objects,
 * with advanced filters, sorting, navigation and more.
 *
 * @package   Kirby Toolkit
 * @author    Bastian Allgeier <bastian@getkirby.com>
 * @link      https://getkirby.com
 * @copyright Bastian Allgeier
 * @license   https://opensource.org/licenses/MIT
 */
class Collection extends Iterator implements Stringable
{
    /**
     * All registered collection filters
     */
    public static array $filters = [];

    protected bool $caseSensitive = false;


    protected $pagination;


    public function __construct(
        array $data = [],
        bool $caseSensitive = false
    ) {
        $this->caseSensitive = $caseSensitive;
        $this->set($data);
    }

    public function __call(string $key, $arguments)
    {
        return $this->__get($key);
    }

    /**
     * Improve var_dump() output
     * @codeCoverageIgnore
     */
    public function __debugInfo(): array
    {
        return $this->keys();
    }

    public function __get(string $key)
    {
        if ($this->caseSensitive === true) {
            return $this->data[$key] ?? null;
        }

        return $this->data[$key] ?? $this->data[strtolower($key)] ?? null;
    }


    public function __set(string $key, $value): void
    {
        if ($this->caseSensitive !== true) {
            $key = strtolower($key);
        }

        $this->data[$key] = $value;
    }

    public function __toString(): string
    {
        return $this->toString();
    }


    public function __unset(string $key)
    {
        if ($this->caseSensitive !== true) {
            $key = strtolower($key);
        }

        unset($this->data[$key]);
    }


    public function append(...$args): static
    {
        if (count($args) === 1) {
            $this->data[] = $args[0];
        } elseif (count($args) > 1) {
            $this->set($args[0], $args[1]);
        }

        return $this;
    }

    /**
     * Creates chunks of the same size.
     * The last chunk may be smaller
     *
     * @param int $size Number of elements per chunk
     * @return static A new collection with an element for each chunk and
     *                a sub collection in each chunk
     */
    public function chunk(int $size): static
    {
        // create a multidimensional array that is chunked with the given
        // chunk size keep keys of the elements
        $chunks = array_chunk($this->data, $size, true);

        // convert each chunk to a sub collection
        $collection = [];

        foreach ($chunks as $items) {
            // we clone $this instead of creating a new object because
            // different objects may have different constructors
            $clone = clone $this;
            $clone->data = $items;

            $collection[] = $clone;
        }

        // convert the array of chunks to a collection
        $result = clone $this;
        $result->data = $collection;

        return $result;
    }

    /**
     * Returns a cloned instance of the collection
     */
    public function clone(): static
    {
        return clone $this;
    }

    /**
     * Getter and setter for the data
     *
     * @return array|$this
     */
    public function data(array|null $data = null): array|static
    {
        if ($data === null) {
            return $this->data;
        }

        // overwrite the data array
        $this->data = $data;

        return $this;
    }

    /**
     * Clone and remove all elements from the collection
     */
    public function empty(): static
    {
        $collection = clone $this;
        $collection->data = [];

        return $collection;
    }

    /**
     * Adds all elements to a cloned collection
     */
    public function extend($items): static
    {
        $collection = clone $this;
        return $collection->set($items);
    }

    /**
     * Filters elements by one of the
     * predefined filter methods, by a
     * custom filter function or an array of filters
     */
    public function filter(string|array|Closure $field, ...$args): static
    {
        $operator = '==';
        $test     = $args[0] ?? null;
        $split    = $args[1] ?? false;

        // filter by custom filter function
        if (
            is_string($field) === false &&
            is_callable($field) === true
        ) {
            $collection       = clone $this;
            $collection->data = array_filter($this->data, $field);

            return $collection;
        }

        // array of filters
        if (is_array($field) === true) {
            $collection = $this;

            foreach ($field as $filter) {
                $collection = $collection->filter(...$filter);
            }

            return $collection;
        }

        if (
            is_string($test) === true &&
            isset(static::$filters[$test]) === true
        ) {
            $operator = $test;
            $test     = $args[1] ?? null;
            $split    = $args[2] ?? false;
        }

        if (
            is_object($test) === true &&
            method_exists($test, '__toString') === true
        ) {
            $test = (string)$test;
        }

        // get the filter from the filters array
        $filter = static::$filters[$operator];

        if (is_array($filter) === true) {
            $collection = clone $this;
            $validator  = $filter['validator'];
            $strict     = $filter['strict'] ?? true;
            $method     = $strict ? 'filterMatchesAll' : 'filterMatchesAny';

            foreach ($collection->data as $key => $item) {
                $value = $collection->getAttribute($item, $field, $split);

                if ($split !== false) {
                    if ($this->$method($validator, $value, $test) === false) {
                        unset($collection->data[$key]);
                    }
                } elseif ($validator($value, $test) === false) {
                    unset($collection->data[$key]);
                }
            }

            return $collection;
        }

        return $filter(clone $this, $field, $test, $split);
    }

    public function filterBy(...$args): static
    {
        return $this->filter(...$args);
    }

    protected function filterMatchesAny(
        callable $validator,
        array $values,
        $test
    ): bool {
        foreach ($values as $value) {
            if ($validator($value, $test) !== false) {
                return true;
            }
        }

        return false;
    }

    protected function filterMatchesAll(
        callable $validator,
        array $values,
        $test
    ): bool {
        foreach ($values as $value) {
            if ($validator($value, $test) === false) {
                return false;
            }
        }

        return true;
    }

    protected function filterMatchesNone(
        callable $validator,
        array $values,
        $test
    ): bool {
        $matches = 0;

        foreach ($values as $value) {
            if ($validator($value, $test) !== false) {
                $matches++;
            }
        }

        return $matches === 0;
    }


    public function find(...$keys)
    {
        if (count($keys) === 1) {
            if (is_array($keys[0]) === false) {
                return $this->findByKey($keys[0]);
            }

            $keys = $keys[0];
        }

        $result = [];

        foreach ($keys as $key) {
            if ($item = $this->findByKey($key)) {
                if (is_object($item) && method_exists($item, 'id') === true) {
                    $key = $item->id();
                }

                $result[$key] = $item;
            }
        }

        $collection = clone $this;
        $collection->data = $result;
        return $collection;
    }

    public function findBy(string $attribute, $value)
    {
        foreach ($this->data as $item) {
            if ($this->getAttribute($item, $attribute) == $value) {
                return $item;
            }
        }

        return null;
    }

    public function findByKey(string $key)
    {
        return $this->get($key);
    }

    public function first()
    {
        $array = $this->data;
        return array_shift($array);
    }

    /**
     * Returns the elements in reverse order
     */
    public function flip(): static
    {
        $collection = clone $this;
        $collection->data = array_reverse($this->data, true);
        return $collection;
    }

    public function get(string $key, mixed $default = null)
    {
        return $this->__get($key) ?? $default;
    }

    /**
     * Extracts an attribute value from the given element
     * in the collection. This is useful if elements in the collection
     * might be objects, arrays or anything else and you need to
     * get the value independently from that. We use it for `filter`.
     */
    public function getAttribute(
        array|object $item,
        string $attribute,
        bool|string $split = false,
        $related = null
    ) {
        $value = $this->{'getAttributeFrom' . gettype($item)}(
            $item,
            $attribute
        );

        if ($split !== false) {
            return Str::split($value, $split === true ? ',' : $split);
        }

        if ($related !== null) {
            return Str::toType((string)$value, $related);
        }

        return $value;
    }

    protected function getAttributeFromArray(
        array $array,
        string $attribute
    ): mixed {
        return $array[$attribute] ?? null;
    }

    protected function getAttributeFromObject(
        object $object,
        string $attribute
    ): mixed {
        return $object->{$attribute}();
    }

    public function group(
        $field,
        bool $caseInsensitive = true
    ): self {
        // group by field name
        if (is_string($field) === true) {
            return $this->group(function ($item) use ($field, $caseInsensitive) {
                $value = $this->getAttribute($item, $field);

                // ignore upper/lowercase for group names
                if ($caseInsensitive) {
                    return Str::lower($value);
                }

                return (string)$value;
            });
        }

        // group via callback function
        if (is_callable($field) === true) {
            $groups = [];

            foreach ($this->data as $key => $item) {
                // get the value to group by
                $value = $field($item);

                // make sure that there's always a proper value to group by
                if (!$value) {
                    throw new Exception(
                        message: 'Invalid grouping value for key: ' . $key
                    );
                }

                // make sure we have a proper key for each group
                if (is_array($value) === true) {
                    throw new Exception(
                        message: 'You cannot group by arrays or objects'
                    );
                }

                if (is_object($value) === true) {
                    if (method_exists($value, '__toString') === false) {
                        throw new Exception(
                            message: 'You cannot group by arrays or objects'
                        );
                    }

                    $value = (string)$value;
                }

                if (isset($groups[$value]) === false) {
                    // create a new entry for the group if it does not exist yet
                    $groups[$value] = new static([$key => $item]);
                } else {
                    // add the element to an existing group
                    $groups[$value]->set($key, $item);
                }
            }

            return new self($groups);
        }

        throw new Exception(
            message: 'Can only group by string values or by providing a callback function'
        );
    }

    public function groupBy(...$args)
    {
        return $this->group(...$args);
    }

    public function intersection(Collection $other): static
    {
        return $other->find($this->keys());
    }

    public function intersects(Collection $other): bool
    {
        foreach ($this->keys() as $key) {
            if ($other->has($key) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks if the number of elements is zero
     */
    public function isEmpty(): bool
    {
        return $this->count() === 0;
    }

    /**
     * Checks if the number of elements is even
     */
    public function isEven(): bool
    {
        return $this->count() % 2 === 0;
    }

    /**
     * Checks if the number of elements is more than zero
     */
    public function isNotEmpty(): bool
    {
        return $this->count() > 0;
    }

    /**
     * Checks if the number of elements is odd
     */
    public function isOdd(): bool
    {
        return $this->count() % 2 !== 0;
    }

    /**
     * Joins the collection elements into a string,
     * optionally using a Closure to transform the elements
     */
    public function join(
        string $separator = ', ',
        Closure|null $as = null
    ): string {
        return implode($separator, $this->toArray($as));
    }

    public function last()
    {
        $array = $this->data;
        return array_pop($array);
    }

    /**
     * Returns a new object with a limited number of elements
     *
     * @param int $limit The number of elements to return
     */
    public function limit(int $limit): static
    {
        return $this->slice(0, $limit);
    }

    public function map(callable $callback): static
    {
        $this->data = array_map($callback, $this->data);
        return $this;
    }

    public function nth(int $n)
    {
        return array_values($this->data)[$n] ?? null;
    }

    /**
     * Returns a Collection without the given element(s)
     *
     * @param string ...$keys any number of keys, passed as individual arguments
     */
    public function not(string ...$keys): static
    {
        $collection = clone $this;

        foreach ($keys as $key) {
            unset($collection->data[$key]);
        }

        return $collection;
    }

    public function offset(int $offset): static
    {
        return $this->slice($offset);
    }

    public function paginate(...$arguments): static
    {
        $this->pagination = Pagination::for($this, ...$arguments);

        // slice and clone the collection according to the pagination
        return $this->slice(
            $this->pagination->offset(),
            $this->pagination->limit()
        );
    }

    /**
     * Get the previously added pagination object
     */
    public function pagination(): Pagination|null
    {
        return $this->pagination;
    }

    /**
     * Extracts all values for a single field into
     * a new array
     */
    public function pluck(
        string $field,
        string|null $split = null,
        bool $unique = false
    ): array {
        $result = [];

        foreach ($this->data as $item) {
            $row = $this->getAttribute($item, $field);

            if ($split !== null) {
                $result = [...$result, ...Str::split($row, $split)];
            } else {
                $result[] = $row;
            }
        }

        if ($unique) {
            $result = array_unique($result);
        }

        return array_values($result);
    }

    public function prepend(...$args): static
    {
        if (count($args) === 1) {
            array_unshift($this->data, $args[0]);
        } elseif (count($args) > 1) {
            $data = $this->data;
            $this->data = [];
            $this->set($args[0], $args[1]);
            $this->data += $data;
        }

        return $this;
    }

    /**
     * Runs a combination of filter, sort, not,
     * offset, limit and paginate on the collection.
     * Any part of the query is optional.
     */
    public function query(array $arguments = []): static
    {
        $result = clone $this;

        if (isset($arguments['not']) === true) {
            $result = $result->not(...$arguments['not']);
        }

        if ($filters = $arguments['filterBy'] ?? $arguments['filter'] ?? null) {
            foreach ($filters as $filter) {
                if (
                    isset($filter['field']) === true &&
                    isset($filter['value']) === true
                ) {
                    $result = $result->filter(
                        $filter['field'],
                        $filter['operator'] ?? '==',
                        $filter['value']
                    );
                }
            }
        }

        if (isset($arguments['offset']) === true) {
            $result = $result->offset($arguments['offset']);
        }

        if (isset($arguments['limit']) === true) {
            $result = $result->limit($arguments['limit']);
        }

        if ($sort = $arguments['sortBy'] ?? $arguments['sort'] ?? null) {
            if (is_array($sort) === true) {
                $sort = explode(' ', implode(' ', $sort));
            } else {
                // if there are commas in the sort argument, removes it
                if (Str::contains($sort, ',') === true) {
                    $sort = Str::replace($sort, ',', '');
                }

                $sort = explode(' ', $sort);
            }

            $result = $result->sort(...$sort);
        }

        if (isset($arguments['paginate']) === true) {
            $result = $result->paginate($arguments['paginate']);
        }

        return $result;
    }

    /**
     * Returns a new collection consisting of random elements,
     * from the original collection, shuffled or ordered
     */
    public function random(int $count = 1, bool $shuffle = false): static
    {
        if ($shuffle) {
            return $this->shuffle()->slice(0, $count);
        }

        $collection = clone $this;
        $collection->data = A::random($collection->data, $count);
        return $collection;
    }

    /**
     * Removes an element from the array by key
     *
     * @param string $key the name of the key
     * @return $this
     */
    public function remove(string $key): static
    {
        $this->__unset($key);
        return $this;
    }

    public function set(string|array $key, $value = null): static
    {
        if (is_array($key) === true) {
            foreach ($key as $k => $v) {
                $this->__set($k, $v);
            }
        } else {
            $this->__set($key, $value);
        }

        return $this;
    }

    /**
     * Shuffle all elements
     */
    public function shuffle(): static
    {
        $data = $this->data;
        $keys = $this->keys();
        shuffle($keys);

        $collection = clone $this;
        $collection->data = [];

        foreach ($keys as $key) {
            $collection->data[$key] = $data[$key];
        }

        return $collection;
    }

    /**
     * Returns a slice of the object
     *
     * @param int $offset The optional index to start the slice from
     * @param int|null $limit The optional number of elements to return
     * @return $this|static
     * @psalm-return ($offset is 0 && $limit is null ? $this : static)
     */
    public function slice(
        int $offset = 0,
        int|null $limit = null
    ): static {
        if ($offset === 0 && $limit === null) {
            return $this;
        }

        $collection = clone $this;
        $collection->data = array_slice($this->data, $offset, $limit);
        return $collection;
    }

    /**
     * Get sort arguments from a string
     */
    public static function sortArgs(string $sort): array
    {
        // if there are commas in the sortBy argument, removes it
        if (Str::contains($sort, ',') === true) {
            $sort = Str::replace($sort, ',', '');
        }

        $args = Str::split($sort, ' ');

        // fill in PHP constants
        array_walk($args, function (string &$value) {
            if (
                Str::startsWith($value, 'SORT_') === true &&
                defined($value) === true
            ) {
                $value = constant($value);
            }
        });

        return $args;
    }

    public function sort(...$args): static
    {
        // there is no need to sort empty collections
        if ($this->data === []) {
            return $this;
        }

        $array      = $this->data;
        $collection = $this->clone();

        // loop through all method arguments and find sets of fields to sort by
        $fields = [];

        foreach ($args as $arg) {
            // get the index of the latest field array inside $fields
            $field = array_key_last($fields);

            // normalize $arg
            $arg = is_string($arg) === true ? strtolower($arg) : $arg;

            // $arg defines sorting direction
            if (
                $arg === 'asc'  || $arg === SORT_ASC ||
                $arg === 'desc' || $arg === SORT_DESC
            ) {
                $fields[$field]['direction'] = match ($arg) {
                    'asc'   => SORT_ASC,
                    'desc'  => SORT_DESC,
                    default => $arg
                };

                // other string: the field name
            } elseif (is_string($arg) === true) {
                $fields[] = [
                    'field'  => $arg,
                    'values' => A::map($array, function ($value) use ($collection, $arg) {
                        $value = $collection->getAttribute($value, $arg);

                        // make sure that we return something sortable
                        // but don't convert other scalars (especially numbers)
                        // to strings!
                        return is_scalar($value) === true ? $value : (string)$value;
                    })
                ];

                // callable: custom field values
            } elseif (is_callable($arg) === true) {
                $fields[] = [
                    'field'  => null,
                    'values' => A::map($array, function ($value) use ($arg) {
                        $value = $arg($value);

                        // make sure that we return something sortable
                        // but don't convert other scalars (especially numbers)
                        // to strings!
                        return is_scalar($value) === true ? $value : (string)$value;
                    })
                ];

                // flags
            } else {
                $fields[$field]['flags'] = $arg;
            }
        }

        // build the multisort params in the right order
        $params = [];

        foreach ($fields as $field) {
            $params[] = $field['values']    ?? [];
            $params[] = $field['direction'] ?? SORT_ASC;
            $params[] = $field['flags']     ?? SORT_NATURAL | SORT_FLAG_CASE;
        }

        // check what kind of collection items we have;
        // only check for the first item for better performance
        // (we assume that all collection items are of the same type)
        $firstItem = $collection->first();

        if (is_object($firstItem) === true) {
            // avoid the "Nesting level too deep - recursive dependency?" error
            // when PHP tries to sort by the objects directly (in case all other
            // fields are 100 % equal for some elements)
            if (method_exists($firstItem, '__toString') === true) {
                // PHP can easily convert the objects to strings,
                // so it should compare them as strings instead of
                // as objects to avoid the recursion
                $params[] = &$array;
                $params[] = SORT_STRING;
            } else {
                // we can't convert the objects to strings,
                // so we need a fallback:
                // custom fictional field that is guaranteed to
                // have a unique value for each item;
                // WARNING: may lead to slightly wrong sorting results
                // and is therefore only used as a fallback
                // if we don't have another way
                $params[] = range(1, count($array));
                $params[] = SORT_ASC;
                $params[] = SORT_NUMERIC;

                $params[] = &$array;
            }
        } else {
            // collection items are scalar or array; no correction necessary
            $params[] = &$array;
        }

        // array_multisort receives $params as separate params
        array_multisort(...$params);

        // $array has been overwritten by array_multisort
        $collection->data = $array;

        return $collection;
    }

    public function sortBy(...$args): static
    {
        return $this->sort(...$args);
    }

    /**
     * Converts the object into an array
     */
    public function toArray(Closure|null $map = null): array
    {
        return match ($map) {
            null    => $this->data,
            default => array_map($map, $this->data)
        };
    }

    /**
     * Converts the object into a JSON string
     */
    public function toJson(): string
    {
        return json_encode($this->toArray());
    }

    /**
     * Converts the object to a string
     */
    public function toString(): string
    {
        return implode('<br />', $this->keys());
    }

    /**
     * Returns a non-associative array
     * with all values. If a mapping Closure is passed,
     * all values are processed by the Closure.
     */
    public function values(Closure|null $map = null): array
    {
        $data = match ($map) {
            null    => $this->data,
            default => array_map($map, $this->data)
        };

        return array_values($data);
    }

    /**
     * The when method only executes the given Closure when the first parameter
     * is true. If the first parameter is false, the Closure will not be executed.
     * You may pass another Closure as the third parameter to the when method.
     * This Closure will execute if the first parameter evaluates as false
     *
     * @since 3.3.0
     * @param mixed $condition a truthy or falsy value
     */
    public function when(
        $condition,
        Closure $callback,
        Closure|null $fallback = null
    ) {
        if ($condition) {
            return $callback->call($this, $condition);
        }

        return $fallback?->call($this, $condition) ?? $this;
    }

    /**
     * @see self::not()
     */
    public function without(string ...$keys): static
    {
        return $this->not(...$keys);
    }


}

/**
 * Equals Filter
 */
Collection::$filters['=='] = function (
    Collection $collection,
    string $field,
    $test,
    bool $split = false
): Collection {
    foreach ($collection->data as $key => $item) {
        $value = $collection->getAttribute($item, $field, $split, $test);

        if ($split !== false) {
            if (in_array($test, $value) === false) {
                unset($collection->data[$key]);
            }
        } elseif ($value !== $test) {
            unset($collection->data[$key]);
        }
    }

    return $collection;
};
