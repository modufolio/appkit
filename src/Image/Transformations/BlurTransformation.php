<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Image\Transformations;

use Modufolio\Appkit\Image\Transformation;
use Modufolio\Appkit\Image\FileInterface;
use Modufolio\Appkit\Image\StorageInterface;
use Modufolio\Appkit\Image\CustomFilename;

/**
 * Blur transformation with configurable intensity
 *
 * @license MIT
 */
class BlurTransformation implements Transformation
{
    private int $intensity;

    public function __construct(int $intensity = 10)
    {
        $this->intensity = max(1, $intensity);
    }

    public function apply(FileInterface $file, StorageInterface $storage): array
    {
        if (!$file->isResizable()) {
            return [
                'root' => $file->root(),
                'url' => $file->mediaUrl(),
            ];
        }

        $options = ['blur' => $this->intensity];

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
        return 'blur';
    }

    public function config(): array
    {
        return ['intensity' => $this->intensity];
    }
}
