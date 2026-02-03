<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Image\Transformations;

use Modufolio\Appkit\Image\Transformation;
use Modufolio\Appkit\Image\FileInterface;
use Modufolio\Appkit\Image\StorageInterface;
use Modufolio\Appkit\Image\CustomFilename;

/**
 * Grayscale (black and white) transformation
 *
 * @license MIT
 */
class GrayscaleTransformation implements Transformation
{
    public function apply(FileInterface $file, StorageInterface $storage): array
    {
        if (!$file->isResizable()) {
            return [
                'root' => $file->root(),
                'url' => $file->mediaUrl(),
            ];
        }

        $options = ['grayscale' => true];

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
        return 'grayscale';
    }

    public function config(): array
    {
        return [];
    }
}
