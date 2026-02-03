<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Toolkit;

/**
 * This class provides a collection of utility functions for functional programming in PHP.
 *
 * @package   Toolkit
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
class Func
{
    /**
     * Compose multiple callable functions into a single callable function.
     *
     * @param callable ...$fns An arbitrary number of callable functions to compose.
     * @return callable A composed callable function.
     */
    public static function compose(callable ...$fns): callable
    {
        $compose = static function ($composition, $fn) {
            return static function (...$args) use ($composition, $fn) {
                return null === $composition
                    ? $fn(...$args)
                    : $fn($composition(...$args));
            };
        };

        return self::reduce($compose, $fns);
    }

    /**
     * Pipe data through a series of callable functions.
     *
     * @param mixed $data The data to be processed.
     * @param callable ...$fns An arbitrary number of callable functions to apply sequentially.
     * @return mixed The result of piping the data through the provided functions.
     */
    public static function pipe(mixed $data, callable ...$fns): mixed
    {
        return self::compose(...$fns)($data);
    }


    /**
     * Reduce an iterable collection using a given callable function.
     *
     * @param callable $fn The callback function to apply for each element in the collection.
     * @param iterable $coll The iterable collection to be reduced.
     * @param mixed|null $initial An optional initial value for the reduction.
     * @return mixed The result of the reduction.
     */
    public static function reduce(callable $fn, iterable $coll, mixed $initial = null): mixed
    {
        $acc = $initial;

        foreach ($coll as $key => $value) {
            $acc = $fn($acc, $value, $key);
        }

        return $acc;
    }

}
