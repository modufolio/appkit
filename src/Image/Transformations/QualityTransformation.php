<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Image\Transformations;

use Modufolio\Appkit\Image\Transformation;
use Modufolio\Appkit\Image\FileInterface;
use Modufolio\Appkit\Image\StorageInterface;
use Modufolio\Appkit\Image\CustomFilename;

/**
 * Quality transformation for JPEG compression level
 *
 * @license MIT
 */
class QualityTransformation implements Transformation
{
    private int $quality;

    public function __construct(int $quality = 90)
    {
        $this->quality = max(1, min(100, $quality));
    }

    public function apply(FileInterface $file, StorageInterface $storage): array
    {
        if (!$file->isResizable()) {
            return [
                'root' => $file->root(),
                'url' => $file->mediaUrl(),
            ];
        }

        $options = ['quality' => $this->quality];

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
        return 'quality';
    }

    public function config(): array
    {
        return ['quality' => $this->quality];
    }
}
