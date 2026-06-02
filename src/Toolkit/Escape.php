<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Toolkit;

use Laminas\Escaper\Escaper;

/**
 * Context-aware output escaping.
 *
 * Thin wrapper around laminas/laminas-escaper — the same battle-tested escaper
 * used by Kirby and others. Each escaping context has different rules; using the
 * wrong one (e.g. HTML-escaping a value placed inside a <script> block) leaves an
 * XSS hole, so always pick the context that matches where the value is printed:
 *
 *   - html : text inside an HTML element            ............ Escape::html()
 *   - attr : value inside a quoted HTML attribute   ............ Escape::attr()
 *   - js   : value inside a <script> / JS string    ............ Escape::js()
 *   - css  : value inside a <style> / CSS context   ............ Escape::css()
 *   - url  : value used in a URL query parameter    ............ Escape::url()
 *
 * The Escaper instance is created once and reused (it is stateless and immutable),
 * which is cheaper than Kirby's per-call instantiation.
 *
 * @link https://docs.laminas.dev/laminas-escaper/
 */
final class Escape
{
    private static ?Escaper $escaper = null;

    private static function escaper(): Escaper
    {
        return self::$escaper ??= new Escaper('utf-8');
    }

    /** Escape for HTML element content. */
    public static function html(string $string): string
    {
        return self::escaper()->escapeHtml($string);
    }

    /** Escape for a quoted HTML attribute value. */
    public static function attr(string $string): string
    {
        return self::escaper()->escapeHtmlAttr($string);
    }

    /** Escape for a JavaScript string literal. */
    public static function js(string $string): string
    {
        return self::escaper()->escapeJs($string);
    }

    /** Escape for a CSS value/context. */
    public static function css(string $string): string
    {
        return self::escaper()->escapeCss($string);
    }

    /** Escape for use inside a URL (query string component). */
    public static function url(string $string): string
    {
        return self::escaper()->escapeUrl($string);
    }
}
