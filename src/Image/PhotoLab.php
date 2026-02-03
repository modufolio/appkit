<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Image;

use Modufolio\Appkit\Image\Transformations\BlurTransformation;
use Modufolio\Appkit\Image\Transformations\CropTransformation;
use Modufolio\Appkit\Image\Transformations\GrayscaleTransformation;
use Modufolio\Appkit\Image\Transformations\QualityTransformation;
use Modufolio\Appkit\Image\Transformations\ResizeTransformation;
use Modufolio\Appkit\Image\Transformations\SharpenTransformation;

/**
 * Image transformation factory for building image variants
 *
 * Provides a convenient factory interface for creating transformation
 * pipelines. Uses the ImageProcessor with composable Transformation objects.
 *
 * Inspired by functional composition patterns in image processing libraries
 * (image-rs, imagepipe).
 *
 * @package   Image
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
class PhotoLab
{
    private FileInterface|null $file;
    private StorageInterface $storage;
    private JobStorageInterface $jobStorage;
    private ?ImageJobService $jobService;

    public function __construct(
        string $absolutePath,
        string $disk = 'default',
        ?ImageJobService $jobService = null,
        ?StorageInterface $storage = null,
        ?JobStorageInterface $jobStorage = null
    ) {
        if (!file_exists($absolutePath)) {
            throw new \InvalidArgumentException("File does not exist: $absolutePath");
        }
        $this->file = new File($absolutePath, $disk, $storage);
        $this->storage = $storage ?? new Storage();
        $this->jobStorage = $jobStorage ?? new JsonJobStorage();
        $this->jobService = $jobService;
    }

    /**
     * Create a new transformation pipeline
     */
    public function build(): ImageProcessor
    {
        if ($this->file === null) {
            throw new \InvalidArgumentException('File does not exist');
        }

        return new ImageProcessor(
            $this->file,
            $this->storage,
            $this->jobStorage,
            $this->jobService
        );
    }

    /**
     * Convenience method: create a resize transformation
     */
    public function resize(
        int|null $width = null,
        int|null $height = null,
        int|null $quality = null
    ): ImageVariant|FileInterface|null {
        return $this->build()
            ->add(new ResizeTransformation($width, $height, $quality))
            ->process();
    }

    /**
     * Convenience method: create a crop transformation
     */
    public function crop(
        int $width,
        int|null $height = null,
        string $mode = 'center'
    ): ImageVariant|FileInterface|null {
        return $this->build()
            ->add(new CropTransformation($width, $height, $mode))
            ->process();
    }

    /**
     * Convenience method: create a blur transformation
     */
    public function blur(int|bool $intensity = true): ImageVariant|FileInterface|null
    {
        $pixels = is_int($intensity) ? $intensity : 10;
        return $this->build()
            ->add(new BlurTransformation($pixels))
            ->process();
    }

    /**
     * Convenience method: create a quality transformation
     */
    public function quality(int $level): ImageVariant|FileInterface|null
    {
        return $this->build()
            ->add(new QualityTransformation($level))
            ->process();
    }

    /**
     * Convenience method: create a grayscale transformation
     */
    public function grayscale(): ImageVariant|FileInterface|null
    {
        return $this->build()
            ->add(new GrayscaleTransformation())
            ->process();
    }

    /**
     * Alias for grayscale
     */
    public function bw(): ImageVariant|FileInterface|null
    {
        return $this->grayscale();
    }

    /**
     * Alias for grayscale (British spelling)
     */
    public function greyscale(): ImageVariant|FileInterface|null
    {
        return $this->grayscale();
    }

    /**
     * Convenience method: create a sharpen transformation
     */
    public function sharpen(int $amount = 50): ImageVariant|FileInterface|null
    {
        return $this->build()
            ->add(new SharpenTransformation($amount))
            ->process();
    }

    /**
     * Generate srcset for responsive images
     */
    public function srcset(array|string|null $sizes = null): string|null
    {
        if (!is_array($sizes) || empty($sizes)) {
            return null;
        }

        if ($this->file === null) {
            return null;
        }

        $set = [];

        foreach ($sizes as $key => $value) {
            if (is_array($value)) {
                $width = $value['width'] ?? $key;
                $condition = $value['condition'] ?? $key . 'w';
            } elseif (is_string($value)) {
                $width = $key;
                $condition = $value;
            } else {
                $width = $value;
                $condition = $value . 'w';
            }

            $variant = $this->resize((int)$width);
            if ($variant) {
                $set[] = $variant->url() . ' ' . $condition;
            }
        }

        return implode(', ', $set);
    }
}