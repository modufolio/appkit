<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Data;

use Modufolio\Appkit\Toolkit\Xml as XmlConverter;

/**
 * Simple Wrapper around the XML parser of the Toolkit.
 *
 * @author    Lukas Bestle <lukas@getkirby.com>
 *
 * @see      https://getkirby.com
 *
 * @copyright Bastian Allgeier GmbH
 * @license   https://opensource.org/licenses/MIT
 */
class Xml extends Handler
{
    /**
     * Converts an array to an encoded XML string.
     */
    public static function encode(mixed $data): string
    {
        return XmlConverter::create($data, 'data');
    }

    /**
     * Parses an encoded XML string and returns a multidimensional array.
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
            throw new \InvalidArgumentException('Invalid XML data; please pass a string');
        }

        $result = XmlConverter::parse($string);

        if (true === is_array($result)) {
            // remove the root's name if it is the default <data> to ensure that
            // the decoded data is the same as the input to the encode() method
            if ('data' === $result['@name']) {
                unset($result['@name']);
            }

            return $result;
        }

        throw new \InvalidArgumentException('XML string is invalid');
    }
}
