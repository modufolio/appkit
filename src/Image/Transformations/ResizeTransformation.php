<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Image\Transformations;

use Modufolio\Appkit\Image\Transformation;
use Modufolio\Appkit\Image\FileInterface;
use Modufolio\Appkit\Image\StorageInterface;
use Modufolio\Appkit\Image\CustomFilename;

/**
 * Resize transformation for image width/height adjustment
 *
 * @license MIT
 */
class ResizeTransformation implements Transformation
{
    private int|null $width;
    private int|null $height;
    private int|null $quality;

    public function __construct(
        int|null $width = null,
        int|null $height = null,
        int|null $quality = null
    ) {
        $this->width = $width;
        $this->height = $height;
        $this->quality = $quality;
    }

    public function apply(FileInterface $file, StorageInterface $storage): array
    {
        if (!$file->isResizable()) {
            return [
                'root' => $file->root(),
                'url' => $file->mediaUrl(),
            ];
        }

        $options = array_filter([
            'width' => $this->width,
            'height' => $this->height,
            'quality' => $this->quality,
        ], fn($v) => $v !== null);

        $mediaRoot = dirname($file->mediaRoot());
        $template = $mediaRoot . '/{{ name }}{{ attributes }}.{{ extension }}';
        $thumbRoot = (new CustomFilename($file->root(), $template, $options))->toString();

        return [
            'root' => $thumbRoot,
            'url' => dirname($file->mediaUrl()) . '/' . basename($thumbRoot),
        ];
    }

    public function name(): string
    {
        return 'resize';
    }

    public function config(): array
    {
        return array_filter([
            'width' => $this->width,
            'height' => $this->height,
            'quality' => $this->quality,
        ], fn($v) => $v !== null);
    }
}
