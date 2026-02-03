<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Image;

use Modufolio\Appkit\Toolkit\F;
use Modufolio\Appkit\Toolkit\Mime;

/**
 * Image
 *
 * Provides access to image metadata: dimensions, EXIF data,
 * and image type checking. Completely original design.
 *
 * @package   Image
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
class Image extends File
{
    protected Exif|null $exif = null;
    protected Dimensions|null $dimensions = null;
    protected ?string $url = null;

    public static array $resizableTypes = [
        'jpg',
        'jpeg',
        'gif',
        'png',
        'webp'
    ];

    public static array $viewableTypes = [
        'avif',
        'jpg',
        'jpeg',
        'gif',
        'png',
        'svg',
        'webp'
    ];

    /**
     * Validation rules to be used for `::match()`
     */
    public static array $validations = [
        'maxsize'     => ['size',   'max'],
        'minsize'     => ['size',   'min'],
        'maxwidth'    => ['width',  'max'],
        'minwidth'    => ['width',  'min'],
        'maxheight'   => ['height', 'max'],
        'minheight'   => ['height', 'min'],
        'orientation' => ['orientation', 'same']
    ];

    /**
     * Returns the dimensions of the file if possible
     */
    public function dimensions(): Dimensions
    {
        if ($this->dimensions !== null) {
            return $this->dimensions;
        }

        if (in_array($this->mime(), [
            'image/jpeg',
            'image/jp2',
            'image/png',
            'image/gif',
            'image/webp'
        ])) {
            return $this->dimensions = Dimensions::forImage($this->root());
        }

        if ($this->extension() === 'svg') {
            return $this->dimensions = Dimensions::forSvg($this->root());
        }

        return $this->dimensions = new Dimensions(0, 0);
    }

    /**
     * Returns the exif object for this file (if image)
     */
    public function exif(): Exif
    {
        return $this->exif ??= new Exif($this);
    }

    /**
     * Returns the height of the asset
     */
    public function height(): int
    {
        return $this->dimensions()->height();
    }

    /**
     * Returns the PHP imagesize array
     */
    public function imagesize(): array
    {
        return getimagesize($this->root());
    }

    /**
     * Checks if the dimensions of the asset are portrait
     */
    public function isPortrait(): bool
    {
        return $this->dimensions()->portrait();
    }

    /**
     * Checks if the dimensions of the asset are landscape
     */
    public function isLandscape(): bool
    {
        return $this->dimensions()->landscape();
    }

    /**
     * Checks if the dimensions of the asset are square
     */
    public function isSquare(): bool
    {
        return $this->dimensions()->square();
    }

    /**
     * Checks if the file is a resizable image
     * Validates both extension and MIME type to prevent spoofing
     */
    public function isResizable(): bool
    {
        $extension = $this->extension();

        // Check if extension is in resizable types
        if (!in_array($extension, static::$resizableTypes)) {
            return false;
        }

        // Validate MIME type matches extension to prevent spoofing
        return $this->isValidImageMimeType();
    }

    /**
     * Validates that the MIME type matches the file extension
     * Prevents MIME type spoofing attacks
     *
     * @throws ImageException If MIME type doesn't match extension
     */
    protected function isValidImageMimeType(): bool
    {
        $mime = $this->mime();
        $extension = $this->extension();

        // Map of extensions to valid MIME types
        $validMimeTypes = [
            'jpg'  => ['image/jpeg', 'image/pjpeg'],
            'jpeg' => ['image/jpeg', 'image/pjpeg'],
            'png'  => ['image/png'],
            'gif'  => ['image/gif'],
            'webp' => ['image/webp'],
        ];

        // Check if extension has valid MIME types defined
        if (!isset($validMimeTypes[$extension])) {
            return false;
        }

        // Verify actual MIME type matches expected MIME types
        if (!in_array($mime, $validMimeTypes[$extension])) {
            throw ImageException::mimeTypeMismatch($this->root(), $extension, $mime);
        }

        return true;
    }

    /**
     * Checks if a preview can be displayed for the file
     * in the Panel or in the frontend
     */
    public function isViewable(): bool
    {
        return in_array($this->extension(), static::$viewableTypes) === true;
    }

    public function modified(): int
    {
        return filemtime($this->root());
    }

    /**
     * Returns the ratio of the asset
     */
    public function ratio(): float
    {
        return $this->dimensions()->ratio();
    }

    /**
     * Returns the orientation as string
     * `landscape` | `portrait` | `square`
     */
    public function orientation(): string|false
    {
        return $this->dimensions()->orientation();
    }

    /**
     * Converts the object to an array
     *
     * @param bool $includeLocation Whether to include GPS location data from EXIF (privacy-sensitive)
     */
    public function toArray(bool $includeLocation = false): array
    {
        $array = [
            'dimensions' => $this->dimensions()->toArray(),
            'exif'       => $this->exif()->toArray($includeLocation),
        ];

        ksort($array);

        return $array;
    }

    public function extension(): string
    {
        return F::extension($this->root());
    }

    /**
     * Returns the width of the asset
     */
    public function width(): int
    {
        return $this->dimensions()->width();
    }

}
