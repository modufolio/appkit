<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Image;

/**
 * Reads exif data from a given image object
 *
 * @package   Kirby Image
 * @author    Bastian Allgeier <bastian@getkirby.com>
 * @link      https://getkirby.com
 * @copyright Bastian Allgeier
 * @license   https://opensource.org/licenses/MIT
 */
class Exif
{
    /**
     * The raw exif array
     */
    protected array $data = [];

    protected Camera|null $camera = null;
    protected Location|null $location = null;
    protected string|null $timestamp = null;
    protected string|null $exposure = null;
    protected string|null $aperture = null;
    protected int|null $iso = null;
    protected string|null $focalLength = null;
    protected bool|null $isColor = null;

    public function __construct(
        protected Image $image
    ) {
        $this->data        = $this->read();
        $this->timestamp   = $this->parseTimestamp();
        $this->exposure    = $this->data['ExposureTime'] ?? null;
        $this->iso         = $this->data['ISOSpeedRatings'] ?? null;
        $this->focalLength = $this->parseFocalLength();
        $this->aperture    = $this->computed()['ApertureFNumber'] ?? null;
    }

    /**
     * Returns the raw data array from the parser
     */
    public function data(): array
    {
        return $this->data;
    }

    public function camera(): Camera
    {
        return $this->camera ??= new Camera($this->data);
    }

    public function location(): Location
    {
        return $this->location ??= new Location($this->data);
    }

    public function timestamp(): string|null
    {
        return $this->timestamp;
    }

    public function exposure(): string|null
    {
        return $this->exposure;
    }

    public function aperture(): string|null
    {
        return $this->aperture;
    }

    public function iso(): int|null
    {
        return $this->iso;
    }

    public function isColor(): bool|null
    {
        return $this->isColor;
    }

    public function isBW(): bool|null
    {
        return ($this->isColor !== null) ? $this->isColor === false : null;
    }

    public function focalLength(): string|null
    {
        return $this->focalLength;
    }

    /**
     * Read the exif data of the image object if possible
     */
    protected function read(): array
    {
        // @codeCoverageIgnoreStart
        if (function_exists('exif_read_data') === false) {
            return [];
        }
        // @codeCoverageIgnoreEnd

        $data = @exif_read_data($this->image->root());
        return is_array($data) ? $data : [];
    }

    /**
     * Get all computed data
     */
    protected function computed(): array
    {
        return $this->data['COMPUTED'] ?? [];
    }

    /**
     * Return the timestamp when the picture has been taken
     */
    protected function parseTimestamp(): string
    {
        if ((isset($this->data['DateTimeOriginal']) === true) && $time = strtotime($this->data['DateTimeOriginal'])) {
            return (string)$time;
        }

        $time = $this->data['FileDateTime'] ?? $this->image->modified();
        return (string)$time;
    }

    /**
     * Return the focal length
     */
    protected function parseFocalLength(): string|null
    {
        return
            $this->data['FocalLength'] ??
            $this->data['FocalLengthIn35mmFilm'] ??
            null;
    }

    /**
     * Converts the object into a nicely readable array
     *
     * @param bool $includeLocation Whether to include GPS location data (privacy-sensitive)
     */
    public function toArray(bool $includeLocation = false): array
    {
        $data = [
            'camera'      => $this->camera()->toArray(),
            'timestamp'   => $this->timestamp(),
            'exposure'    => $this->exposure(),
            'aperture'    => $this->aperture(),
            'iso'         => $this->iso(),
            'focalLength' => $this->focalLength(),
            'isColor'     => $this->isColor()
        ];

        // Only include location data if explicitly requested
        if ($includeLocation) {
            $data['location'] = $this->location()->toArray();
        }

        return $data;
    }

    /**
     * Improved `var_dump` output
     * @codeCoverageIgnore
     */
    public function __debugInfo(): array
    {
        return array_merge($this->toArray(), [
            'camera'   => $this->camera(),
            'location' => $this->location()
        ]);
    }
}
