<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Image;

use Modufolio\Appkit\Image\Location;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Location::class)]
class LocationTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    protected function _exif(): array
    {
        return [
            'GPSLatitudeRef' => 'N',
            'GPSLatitude' => ['50/1', '49/1', '8592/1000'],
            'GPSLongitudeRef' => 'W',
            'GPSLongitude' => ['0/1', '1', '/12450'],
        ];
    }

    public function testLatLng(): void
    {
        $camera = new Location($this->_exif());
        $this->assertSame(50.819053333333336, $camera->lat());
        $this->assertSame(-0.016666666666666666, $camera->lng());
    }

    public function testAZeroDenominatorIsNotADivision(): void
    {
        // A camera writes "0/0" for a rational it has no value for, and an
        // uploaded file can carry one: the division was a fatal
        // DivisionByZeroError before the guard.
        $location = new Location([
            'GPSLatitudeRef' => 'N',
            'GPSLatitude' => ['41/1', '53/1', '0/0'],
            'GPSLongitudeRef' => 'E',
            'GPSLongitude' => ['12/1', '29/1', '0/0'],
        ]);

        $this->assertSame(41.883333333333333, $location->lat());
        $this->assertSame(12.483333333333333, $location->lng());
    }

    public function testToArray(): void
    {
        $camera = new Location($this->_exif());
        $array = [
            'lat' => 50.819053333333336,
            'lng' => -0.016666666666666666,
        ];
        $this->assertSame($array, $camera->toArray());
        $this->assertSame($array, $camera->__debugInfo());
    }

    public function testToString(): void
    {
        $camera = new Location($this->_exif());
        $this->assertStringContainsString('50.8190533333', (string) $camera);
        $this->assertStringContainsString('-0.016666666666', (string) $camera);
    }
}
