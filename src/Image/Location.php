<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Image;

/**
 * Returns the latitude and longitude values
 * for exif location data if available.
 *
 * @author    Bastian Allgeier <bastian@getkirby.com>
 *
 * @see      https://getkirby.com
 *
 * @copyright Bastian Allgeier
 * @license   https://opensource.org/licenses/MIT
 */
class Location implements \Stringable
{
    protected ?float $lat = null;
    protected ?float $lng = null;

    /**
     * Constructor.
     *
     * @param array $exif The entire exif array
     */
    public function __construct(array $exif)
    {
        if (
            true === isset($exif['GPSLatitude'])
            && true === isset($exif['GPSLatitudeRef'])
            && true === isset($exif['GPSLongitude'])
            && true === isset($exif['GPSLongitudeRef'])
        ) {
            $this->lat = $this->gps(
                $exif['GPSLatitude'],
                $exif['GPSLatitudeRef']
            );
            $this->lng = $this->gps(
                $exif['GPSLongitude'],
                $exif['GPSLongitudeRef']
            );
        }
    }

    /**
     * Returns the latitude.
     */
    public function lat(): ?float
    {
        return $this->lat;
    }

    /**
     * Returns the longitude.
     */
    public function lng(): ?float
    {
        return $this->lng;
    }

    /**
     * Converts the gps coordinates.
     */
    protected function gps(array $coord, string $hemi): float
    {
        $degrees = count($coord) > 0 ? $this->num($coord[0]) : 0;
        $minutes = count($coord) > 1 ? $this->num($coord[1]) : 0;
        $seconds = count($coord) > 2 ? $this->num($coord[2]) : 0;

        $hemi = strtoupper($hemi);
        $flip = ('W' === $hemi || 'S' === $hemi) ? -1 : 1;

        return $flip * ($degrees + $minutes / 60 + $seconds / 3600);
    }

    /**
     * Converts coordinates to floats.
     */
    protected function num(string $part): float
    {
        $parts = explode('/', $part);

        if (1 === count($parts)) {
            return (float) $parts[0];
        }

        return (float) $parts[0] / (float) $parts[1];
    }

    /**
     * Converts the object into a nicely readable array.
     */
    public function toArray(): array
    {
        return [
            'lat' => $this->lat(),
            'lng' => $this->lng(),
        ];
    }

    /**
     * Echos the entire location as lat, lng.
     */
    public function __toString(): string
    {
        return trim($this->lat().', '.$this->lng(), ',');
    }

    /**
     * Improved `var_dump` output.
     *
     * @codeCoverageIgnore
     */
    public function __debugInfo(): array
    {
        return $this->toArray();
    }
}
