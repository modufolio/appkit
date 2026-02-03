<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Image\Transformations;

use Modufolio\Appkit\Image\Transformations\CropTransformation;
use PHPUnit\Framework\TestCase;

class CropTransformationTest extends TestCase
{
    public function testCropTransformationName(): void
    {
        $transformation = new CropTransformation(300, 200);
        $this->assertSame('crop', $transformation->name());
    }

    public function testCropTransformationConfig(): void
    {
        $transformation = new CropTransformation(300, 200, 'center');
        $config = $transformation->config();

        $this->assertSame(300, $config['width']);
        $this->assertSame(200, $config['height']);
        $this->assertSame('center', $config['mode']);
    }

    public function testCropTransformationDefaultMode(): void
    {
        $transformation = new CropTransformation(300, 200);
        $config = $transformation->config();

        $this->assertSame('center', $config['mode']);
    }

    public function testCropTransformationSquare(): void
    {
        $transformation = new CropTransformation(300);
        $config = $transformation->config();

        $this->assertSame(300, $config['width']);
        $this->assertSame(300, $config['height']);
    }

    public function testCropTransformationCustomMode(): void
    {
        $transformation = new CropTransformation(300, 200, 'top-left');
        $config = $transformation->config();

        $this->assertSame('top-left', $config['mode']);
    }
}
