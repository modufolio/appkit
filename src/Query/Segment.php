<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Query;

use Closure;
use Modufolio\Appkit\Toolkit\Str;

/**
 * The Segment class represents a single
 * part of a chained query.
 *
 * @author    Nico Hoffmann <nico@getkirby.com>
 *
 * @see      https://getkirby.com
 *
 * @copyright Bastian Allgeier
 * @license   https://opensource.org/licenses/MIT
 */
final class Segment
{
    public function __construct(
        public string $method,
        public int $position,
        public ?Arguments $arguments = null,
    ) {
    }

    /**
     * Throws an exception for an access to an invalid method.
     *
     * @internal
     *
     * @param mixed  $data  Variable on which the access was tried
     * @param string $name  Name of the method/property that was accessed
     * @param string $label Type of the name (`method`, `property` or `method/property`)
     */
    public static function error(mixed $data, string $name, string $label): never
    {
        $type = strtolower(gettype($data));

        if ('double' === $type) {
            $type = 'float';
        }

        $nonExisting = in_array($type, ['array', 'object']) ? 'non-existing ' : '';

        $error = 'Access to '.$nonExisting.$label.' "'.$name.'" on '.$type;

        throw new \BadMethodCallException($error);
    }

    /**
     * Parses a segment into the property/method name and its arguments.
     *
     * @param int $position String position of the segment inside the full query
     */
    public static function factory(
        string $segment,
        int $position = 0,
    ): static {
        if (false === Str::endsWith($segment, ')')) {
            return new static(method: $segment, position: $position);
        }

        // the args are everything inside the *outer* parentheses
        $args = Str::substr($segment, Str::position($segment, '(') + 1, -1);

        return new static(
            method: Str::before($segment, '('),
            position: $position,
            arguments: Arguments::factory($args)
        );
    }

    /**
     * Automatically resolves the segment depending on the
     * segment position and the type of the base.
     *
     * @param mixed $base Current value of the query chain
     */
    public function resolve(mixed $base = null, array|object $data = []): mixed
    {
        // resolve arguments to array
        $args = $this->arguments?->resolve($data) ?? [];

        // 1st segment, use $data as base
        if (0 === $this->position) {
            $base = $data;
        }

        if (true === is_array($base)) {
            return $this->resolveArray($base, $args);
        }

        if (true === is_object($base)) {
            return $this->resolveObject($base, $args);
        }

        // trying to access further segments on a scalar/null value
        static::error($base, $this->method, 'method/property');
    }

    /**
     * Resolves segment by calling the corresponding array key.
     */
    private function resolveArray(array $array, array $args): mixed
    {
        // the directly provided array takes precedence
        // to look up a matching entry
        if (true === array_key_exists($this->method, $array)) {
            $value = $array[$this->method];

            // if this is a Closure we can directly use it, as
            // Closures from the $array should always have priority
            // over the Query::$entries Closures
            if ($value instanceof \Closure) {
                return $value(...$args);
            }

            // if we have no arguments to pass, we also can directly
            // use the value from the $array as it must not be different
            // to the one from Query::$entries with the same name
            if ([] === $args) {
                return $value;
            }
        }

        // fallback time: only if we are handling the first segment,
        // we can also try to resolve the segment with an entry from the
        // default Query::$entries
        if (0 === $this->position) {
            if (true === array_key_exists($this->method, Query::$entries)) {
                return Query::$entries[$this->method](...$args);
            }
        }

        // if we have not been able to return anything so far,
        // we just need to differntiate between two different error messages

        // this one is in case the original array contained the key,
        // but was not a Closure while the segment had arguments
        if (
            array_key_exists($this->method, $array)
            && [] !== $args
        ) {
            throw new \InvalidArgumentException('Cannot access array element "'.$this->method.'" with arguments');
        }

        // last, the standard error for trying to access something
        // that does not exist
        static::error($array, $this->method, 'property');
    }

    /**
     * Resolves segment by calling the method/
     * accessing the property on the base object.
     */
    private function resolveObject(object $object, array $args): mixed
    {
        if (
            true === method_exists($object, $this->method)
            || true === method_exists($object, '__call')
        ) {
            return $object->{$this->method}(...$args);
        }

        if (
            [] === $args
            && (
                true === property_exists($object, $this->method)
                || true === method_exists($object, '__get')
            )
        ) {
            return $object->{$this->method};
        }

        $label = ([] === $args) ? 'method/property' : 'method';
        static::error($object, $this->method, $label);
    }
}
