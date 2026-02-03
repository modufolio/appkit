<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Image\Transformations;

use Modufolio\Appkit\Image\Transformation;
use Modufolio\Appkit\Image\FileInterface;
use Modufolio\Appkit\Image\StorageInterface;
use Modufolio\Appkit\Image\CustomFilename;

/**
 * Crop transformation with configurable crop mode
 *
 * @license MIT
 */
class CropTransformation implements Transformation
{
    private int $width;
    private int|null $height;
    private string $mode;

    public function __construct(
        int $width,
        int|null $height = null,
        string $mode = 'center'
    ) {
        $this->width = $width;
        $this->height = $height ?? $width;
        $this->mode = $mode;
    }

    public function apply(FileInterface $file, StorageInterface $storage): array
    {
        if (!$file->isResizable()) {
            return [
                'root' => $file->root(),
                'url' => $file->mediaUrl(),
            ];
        }

        $options = [
            'width' => $this->width,
            'height' => $this->height,
            'crop' => $this->mode,
        ];

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
        return 'crop';
    }

    public function config(): array
    {
        return [
            'width' => $this->width,
            'height' => $this->height,
            'mode' => $this->mode,
        ];
    }
}
