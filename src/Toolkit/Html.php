<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Toolkit;

/**
 * HTML builder for the most common elements.
 *
 * @author    Bastian Allgeier <bastian@getkirby.com>
 *
 * @see      https://getkirby.com
 *
 * @copyright Bastian Allgeier GmbH
 * @license   https://opensource.org/licenses/MIT
 */
class Html extends Xml
{
    /**
     * An internal store for an HTML entities translation table.
     */
    public static ?array $entities;

    /**
     * Closing string for void tags;
     * can be used to switch to trailing slashes if required.
     *
     * ```php
     * Html::$void = ' />'
     * ```
     */
    public static string $void = '>';

    /**
     * List of HTML tags that are considered to be self-closing.
     */
    public static array $voidList = [
        'area',
        'base',
        'br',
        'col',
        'command',
        'embed',
        'hr',
        'img',
        'input',
        'keygen',
        'link',
        'meta',
        'param',
        'source',
        'track',
        'wbr',
    ];

    /**
     * Generic HTML tag generator
     * Can be called like `Html::p('A paragraph', ['class' => 'text'])`.
     *
     * @param string $tag       Tag name
     * @param array  $arguments Further arguments for the Html::tag() method
     */
    public static function __callStatic(string $tag, array $arguments = []): string
    {
        if (true === static::isVoid($tag)) {
            return static::tag($tag, null, ...$arguments);
        }

        return static::tag($tag, ...$arguments);
    }

    /**
     * Generates a single attribute or a list of attributes.
     *
     * @param string|array $name   String: A single attribute with that name will be generated.
     *                             Key-value array: A list of attributes will be generated. Don't pass a second argument in that case.
     * @param mixed        $value  If used with a `$name` string, pass the value of the attribute here.
     *                             If used with a `$name` array, this can be set to `false` to disable attribute sorting.
     * @param string|null  $before An optional string that will be prepended if the result is not empty
     * @param string|null  $after  An optional string that will be appended if the result is not empty
     *
     * @return string|null The generated HTML attributes string
     */
    public static function attr(
        string|array $name,
        $value = null,
        ?string $before = null,
        ?string $after = null,
    ): ?string {
        // HTML supports boolean attributes without values
        if (false === is_array($name) && true === is_bool($value)) {
            return true === $value ? strtolower($name) : null;
        }

        // HTML attribute names are case-insensitive
        if (true === is_string($name)) {
            $name = strtolower($name);
        }

        // all other cases can share the XML variant
        $attr = parent::attr($name, $value);

        if (null === $attr) {
            return null;
        }

        // HTML supports named entities
        $entities = parent::entities();
        $html = array_keys($entities);
        $xml = array_values($entities);
        $attr = str_replace($xml, $html, $attr);

        if ($attr) {
            return $before.$attr.$after;
        }

        return null;
    }

    /**
     * Converts lines in a string into HTML breaks.
     */
    public static function breaks(string $string): string
    {
        return nl2br($string);
    }

    /**
     * Converts a string to an HTML-safe string.
     *
     * @param string $string   |null $string
     * @param bool   $keepTags If true, existing tags won't be escaped
     *
     * @return string The HTML string
     *
     * @psalm-suppress ParamNameMismatch
     */
    public static function encode(
        ?string $string,
        bool $keepTags = false,
    ): string {
        if (null === $string) {
            return '';
        }

        if (true === $keepTags) {
            $list = static::entities();

            unset(
                $list['"'],
                $list['<'],
                $list['>'],
                $list['&']
            );

            $search = array_keys($list);
            $values = array_values($list);

            return str_replace($search, $values, $string);
        }

        return htmlentities($string, ENT_QUOTES, 'utf-8');
    }

    /**
     * Returns the entity translation table.
     */
    public static function entities(): array
    {
        return self::$entities ??= get_html_translation_table(HTML_ENTITIES);
    }

    /**
     * Checks if a tag is self-closing.
     */
    public static function isVoid(string $tag): bool
    {
        return in_array(strtolower($tag), static::$voidList, true);
    }

    /**
     * Builds an HTML tag.
     *
     * @param string            $name    Tag name
     * @param array|string|null $content Scalar value or array with multiple lines of content; self-closing
     *                                   tags are generated automatically based on the `Html::isVoid()` list
     * @param array             $attr    An associative array with additional attributes for the tag
     * @param string|null       $indent  Indentation string, defaults to two spaces or `null` for output on one line
     * @param int               $level   Indentation level
     *
     * @return string The generated HTML
     */
    public static function tag(string $name, array|string|null $content = '', array $attr = [], ?string $indent = null, int $level = 0): string
    {
        // treat an explicit `null` value as an empty tag
        // as void tags are already covered below
        $content ??= '';

        // force void elements to be self-closing
        if (true === static::isVoid($name)) {
            $content = null;
        }

        return parent::tag($name, $content, $attr, $indent, $level);
    }

    /**
     * Properly encodes tag contents.
     */
    public static function value($value): ?string
    {
        if (true === $value) {
            return 'true';
        }

        if (false === $value) {
            return 'false';
        }

        if (true === is_numeric($value)) {
            return (string) $value;
        }

        if (null === $value || '' === $value) {
            return null;
        }

        return static::encode($value, false);
    }
}
