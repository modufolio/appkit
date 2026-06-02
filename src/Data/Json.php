<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Data;

/**
 * Simple Wrapper around json_encode and json_decode.
 *
 * @author    Bastian Allgeier <bastian@getkirby.com>
 *
 * @see      https://getkirby.com
 *
 * @copyright Bastian Allgeier GmbH
 * @license   https://opensource.org/licenses/MIT
 */
class Json extends Handler
{
    /**
     * Converts an array to an encoded JSON string.
     */
    public static function encode(mixed $data): string
    {
        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Parses an encoded JSON string and returns a multidimensional array.
     */
    public static function decode(mixed $string): array
    {
        if (null === $string) {
            return [];
        }

        if (true === is_array($string)) {
            return $string;
        }

        if (false === is_string($string)) {
            throw new \InvalidArgumentException('Invalid JSON data; please pass a string');
        }

        if ('' === $string) {
            return [];
        }

        $result = json_decode($string, true);

        if (true === is_array($result)) {
            return $result;
        }

        throw new \InvalidArgumentException('JSON string is invalid');
    }
}
