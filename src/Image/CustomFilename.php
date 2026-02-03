<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Image;

use Modufolio\Appkit\Toolkit\Str;

/**
 * The `CustomFilename` class handles complex
 * mapping of file attributes into human-readable filenames,
 * inspired by Kirby's `Filename` class.
 *
 * @package   Image
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
class CustomFilename implements \Stringable
{
    protected string $extension;
    protected string $name;

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

    public function attributesToArray(): array
    {
        $array = [
            'dimensions' => implode('x', $this->dimensions()),
            'crop' => $this->crop(),
            'blur' => $this->blur(),
            'bw' => $this->grayscale(),
            'q' => $this->quality(),
        ];

        return array_filter(
            $array,
            static fn ($item) => $item !== null && $item !== false && $item !== ''
        );
    }

    public function attributesToString(?string $prefix = null): string
    {
        $array = $this->attributesToArray();
        $result = [];

        foreach ($array as $key => $value) {
            if ($value === true) {
                $value = '';
            }

            $result[] = match ($key) {
                'dimensions' => $value,
                'crop' => ($value === 'center') ? 'crop' : $key . '-' . $value,
                default => $key . $value
            };
        }

        $result = array_filter($result);
        $attributes = implode('-', $result);

        if (empty($attributes)) {
            return '';
        }

        return $prefix . $attributes;
    }

    public function blur(): int|false
    {
        $value = $this->attributes['blur'] ?? false;

        if ($value === false) {
            return false;
        }

        return (int)$value;
    }

    public function crop(): string|false
    {
        $crop = $this->attributes['crop'] ?? false;

        if ($crop === false) {
            return false;
        }

        return $this->sanitizeString($crop);
    }

    public function dimensions(): array
    {
        if (empty($this->attributes['width']) && empty($this->attributes['height'])) {
            return [];
        }

        return [
            'width' => $this->attributes['width'] ?? null,
            'height' => $this->attributes['height'] ?? null
        ];
    }

    public function extension(): string
    {
        return $this->extension;
    }

    public function grayscale(): bool
    {
        $value = $this->attributes['grayscale'] ?? $this->attributes['greyscale'] ?? $this->attributes['bw'] ?? false;

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function quality(): int|false
    {
        $value = $this->attributes['quality'] ?? false;

        if ($value === false || $value === true) {
            return false;
        }

        return (int)$value;
    }

    protected function sanitizeExtension(string $extension): string
    {
        $extension = strtolower($extension);
        return str_replace('jpeg', 'jpg', $extension);
    }

    protected function sanitizeName(string $name): string
    {
        return $this->sanitizeString($name);
    }

    protected function sanitizeString(string $value): string
    {
        return Str::slug($value);
    }

    /**
     * Check if a string contains path traversal sequences
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
            'extension' => $this->extension()
        ]);
    }
}
