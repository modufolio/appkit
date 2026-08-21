<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Image;

use Modufolio\Appkit\Toolkit\Str;

/**
 * The `CustomFilename` class handles complex
 * mapping of file attributes into human-readable filenames,
 * inspired by Kirby's `Filename` class.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
class CustomFilename implements \Stringable
{
    protected string $extension;
    protected string $name;

    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(protected string $filename, protected string $template, protected array $attributes = [])
    {
        // Validate template for path traversal
        if ($this->containsPathTraversal($template)) {
            throw ImageException::pathTraversalAttempt($template);
        }

        // Validate filename for path traversal
        if ($this->containsPathTraversal($filename)) {
            throw ImageException::pathTraversalAttempt($filename);
        }

        $this->extension = $this->sanitizeExtension(
            $this->attributes['format'] ?? pathinfo($this->filename, PATHINFO_EXTENSION)
        );
        $this->name = $this->sanitizeName(pathinfo($this->filename, PATHINFO_FILENAME));
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    /**
     * The attribute tokens that make up the variant suffix, in the order they
     * appear in the filename.
     *
     * Format spec (each token is omitted when the attribute is unset):
     *
     *   dimensions  {width}x{height}   either side may be empty  300x200, 100x
     *   crop        crop | crop-{pos}  bare when centred         crop, crop-top-left
     *   blur        blur{amount}                                 blur10
     *   bw          bw                 flag, no value            bw
     *   q           q{quality}                                   q80
     *
     * Joined with "-" and prefixed, e.g. "-300x200-crop-blur10-bw-q80".
     *
     * @return array<string, mixed>
     */
    public function attributesToArray(): array
    {
        $attributes = [
            'dimensions' => implode('x', $this->dimensions()),
            'crop' => $this->crop(),
            'blur' => $this->blur(),
            'bw' => $this->grayscale(),
            'q' => $this->quality(),
        ];

        // An unset attribute reports false; dimensions report '' when neither
        // side was given. Both mean "no token".
        return array_filter(
            $attributes,
            static fn (mixed $value): bool => false !== $value && '' !== $value
        );
    }

    public function attributesToString(?string $prefix = null): string
    {
        $tokens = [];

        foreach ($this->attributesToArray() as $name => $value) {
            $tokens[] = match ($name) {
                // Already rendered as WxH by attributesToArray().
                'dimensions' => $value,
                // A centred crop is the default, so it needs no position.
                'crop' => 'center' === $value ? 'crop' : 'crop-'.$value,
                // Boolean flags stand alone; the rest append their value.
                default => true === $value ? $name : $name.$value,
            };
        }

        if ([] === $tokens) {
            return '';
        }

        return $prefix.implode('-', $tokens);
    }

    /**
     * Read the first attribute that was supplied, falling back to false.
     *
     * Several attributes accept more than one spelling (grayscale/greyscale/bw),
     * and every one of them treats "absent" the same way, so the lookup lives
     * here instead of being repeated in each accessor.
     */
    protected function attribute(string ...$names): mixed
    {
        foreach ($names as $name) {
            if (isset($this->attributes[$name])) {
                return $this->attributes[$name];
            }
        }

        return false;
    }

    /**
     * Blur radius, or false when no blur was requested.
     *
     * A bare `blur => true` means "blur by 1": the flag is kept numeric so the
     * filename token stays well-formed.
     */
    public function blur(): int|false
    {
        $value = $this->attribute('blur');

        return false === $value ? false : (int) $value;
    }

    /**
     * Crop position as a slug, or false when the image is not cropped.
     */
    public function crop(): string|false
    {
        $value = $this->attribute('crop');

        return false === $value ? false : $this->sanitizeString((string) $value);
    }

    /**
     * Requested width and height, either of which may be null.
     *
     * Returns an empty array when neither was given, so callers can treat
     * "no resize" as a missing token rather than an empty WxH pair.
     *
     * @return array<string, mixed>
     */
    public function dimensions(): array
    {
        $width = $this->attributes['width'] ?? null;
        $height = $this->attributes['height'] ?? null;

        if (empty($width) && empty($height)) {
            return [];
        }

        return ['width' => $width, 'height' => $height];
    }

    public function extension(): string
    {
        return $this->extension;
    }

    /**
     * Whether the variant is black and white.
     *
     * Accepts either spelling plus the `bw` shorthand, and coerces loosely so
     * string flags coming from a query string ("true", "1", "no") behave.
     */
    public function grayscale(): bool
    {
        return filter_var(
            $this->attribute('grayscale', 'greyscale', 'bw'),
            FILTER_VALIDATE_BOOLEAN
        );
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * Compression quality, or false when unset.
     *
     * Unlike blur, a boolean carries no usable quality value — `quality => true`
     * is meaningless — so both booleans collapse to false.
     */
    public function quality(): int|false
    {
        $value = $this->attribute('quality');

        return is_bool($value) ? false : (int) $value;
    }

    /**
     * Normalise an extension for use in a filename.
     *
     * Lowercased, and the two spellings of the JPEG extension are folded to one
     * so the same source never yields two differently-named variants.
     */
    protected function sanitizeExtension(string $extension): string
    {
        return str_replace('jpeg', 'jpg', strtolower($extension));
    }

    /**
     * Reduce a name to something safe to place in a path.
     */
    protected function sanitizeName(string $name): string
    {
        return $this->sanitizeString($name);
    }

    protected function sanitizeString(string $value): string
    {
        return Str::slug($value);
    }

    /**
     * Check if a string contains path traversal sequences.
     */
    protected function containsPathTraversal(string $value): bool
    {
        // Check for common path traversal patterns
        $patterns = [
            '..',           // Parent directory
            '/../',         // Unix path traversal
            '\\..\\',       // Windows path traversal
            '%2e%2e',       // URL encoded ..
            '%252e%252e',   // Double URL encoded ..
            '..%2f',        // Mixed encoding
            '..%5c',        // Mixed encoding (backslash)
        ];

        $normalized = strtolower($value);
        foreach ($patterns as $pattern) {
            if (str_contains($normalized, strtolower($pattern))) {
                return true;
            }
        }

        return false;
    }

    public function toString(): string
    {
        return Str::template($this->template, [
            'name' => $this->name(),
            'attributes' => $this->attributesToString('-'),
            'extension' => $this->extension(),
        ]);
    }
}
