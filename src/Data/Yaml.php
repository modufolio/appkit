<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Data;

use Modufolio\Appkit\Toolkit\A;
use InvalidArgumentException;
use Symfony\Component\Yaml\Yaml as Symfony;

/**
 * Simple Wrapper around the Symfony YAML class
 *
 * @package   Kirby Data
 * @author    Bastian Allgeier <bastian@getkirby.com>
 * @link      https://getkirby.com
 * @copyright Bastian Allgeier
 * @license   https://opensource.org/licenses/MIT
 */
class Yaml extends Handler
{
    /**
     * Converts an array to an encoded YAML string
     */
    public static function encode(mixed $data): string
    {
        return Symfony::dump(
            $data,
            9999,
            2,
            Symfony::DUMP_MULTI_LINE_LITERAL_BLOCK | Symfony::DUMP_EMPTY_ARRAY_AS_SEQUENCE
        );
    }

    /**
     * Parses an encoded YAML string and returns a multidimensional array
     * @throws InvalidArgumentException
     */
    public static function decode(mixed $string): array
    {
        if ($string === null || $string === '') {
            return [];
        }

        if (is_array($string) === true) {
            return $string;
        }

        if (is_string($string) === false) {
            throw new InvalidArgumentException('Invalid YAML data; please pass a string');
        }

        $result = Symfony::parse($string);
        return A::wrap($result);
    }
}
