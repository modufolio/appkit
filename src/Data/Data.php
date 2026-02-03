<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Data;

use Modufolio\Appkit\Toolkit\F;
use Exception;

/**
 * The `Data` class provides readers and
 * writers for data. The class comes with
 * handlers for `json`, `php`, `txt`, `xml`
 * and `yaml` encoded data, but can be
 * extended and customized.
 *
 * The read and write methods automatically
 * detect which data handler to use in order
 * to correctly encode and decode passed data.
 *
 * @package   Kirby Data
 * @author    Bastian Allgeier <bastian@getkirby.com>
 * @link      https://getkirby.com
 * @copyright Bastian Allgeier GmbH
 * @license   https://opensource.org/licenses/MIT
 */
class Data
{
    /**
     * Handler Type Aliases
     *
     * @var array
     */
    public static array $aliases = [
        'md' => 'txt',
        'mdown' => 'txt',
        'rss' => 'xml',
        'yml' => 'yaml',
    ];

    /**
     * All registered handlers
     *
     * @var array
     */
    public static array $handlers = [
        'json' => Json::class,
        'php' => PHP::class,
        'txt' => Txt::class,
        'xml' => Xml::class,
        'yaml' => Yaml::class,
    ];

    /**
     * Handler getter
     *
     * @param string $type
     * @return Handler
     * @throws Exception
     */
    public static function handler(string $type): Handler
    {
        // normalize the type
        $type = strtolower($type);

        // find a handler or alias
        $handler = static::$handlers[$type] ??
            static::$handlers[static::$aliases[$type] ?? null] ??
            null;

        if ($handler === null) {
            throw new \RuntimeException('Missing handler for type: "' . $type . '"');
        }

        if (class_exists($handler)) {
            return new $handler();
        }

        throw new \RuntimeException('Missing handler for type: "' . $type . '"');
    }

    /**
     * Decodes data with the specified handler
     *
     * @param string $type
     * @return array
     * @throws Exception
     */
    public static function decode(mixed $string, string $type): array
    {
        return static::handler($type)->decode($string);
    }

    /**
     * Encodes data with the specified handler
     *
     * @param string $type
     * @return string
     * @throws Exception
     */
    public static function encode(mixed $data, string $type): string
    {
        return static::handler($type)->encode($data);
    }

    /**
     * Reads data from a file;
     * the data handler is automatically chosen by
     * the extension if not specified
     *
     * @param string $file
     * @param string|null $type
     * @return array
     * @throws Exception
     */
    public static function read(string $file, string|null $type = null): array
    {
        return static::handler($type ?? F::extension($file))->read($file);
    }

    /**
     * Writes data to a file;
     * the data handler is automatically chosen by
     * the extension if not specified
     *
     * @param string $file
     * @param array $data
     * @param string|null $type
     * @return bool
     * @throws Exception
     */
    public static function write(string $file, array $data = [], string|null $type = null): bool
    {
        return static::handler($type ?? F::extension($file))->write($file, $data);
    }
}
