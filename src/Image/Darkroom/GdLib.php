<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Image\Darkroom;

use claviska\SimpleImage;
use Modufolio\Appkit\Image\Darkroom;
use Modufolio\Appkit\Image\Focus;
use Modufolio\Appkit\Toolkit\Mime;

/**
 * GdLib.
 *
 * @author    Bastian Allgeier <bastian@getkirby.com>
 *
 * @see      https://getkirby.com
 *
 * @copyright Bastian Allgeier
 * @license   https://opensource.org/licenses/MIT
 */
class GdLib extends Darkroom
{
    /**
     * Processes the image with the SimpleImage library.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function process(string $file, array $options = []): array
    {
        // Optional dependencies (composer suggest) — fail with the fix in the
        // message instead of a bare class-not-found from deep inside the stack.
        if (false === extension_loaded('gd')) {
            throw new \RuntimeException('Image processing requires the gd PHP extension. Enable it in your php.ini or rebuild PHP with gd support.');
        }
        if (false === class_exists(SimpleImage::class)) {
            throw new \RuntimeException('Image processing requires the claviska/simpleimage package. Install it with `composer require claviska/simpleimage`.');
        }

        $options = $this->preprocess($file, $options);
        $mime = $this->mime($options);

        $image = new SimpleImage();
        $image->fromFile($file);

        $image = $this->resize($image, $options);
        $image = $this->autoOrient($image, $options);
        $image = $this->blur($image, $options);
        $image = $this->grayscale($image, $options);
        $image = $this->sharpen($image, $options);

        $image->toFile($file, $mime, $options);

        return $options;
    }

    /**
     * Activates the autoOrient option in SimpleImage
     * unless this is deactivated.
     *
     * @param array<string, mixed> $options
     */
    protected function autoOrient(SimpleImage $image, array $options): SimpleImage
    {
        if (false === $options['autoOrient']) {
            return $image;
        }

        return $image->autoOrient();
    }

    /**
     * Wrapper around SimpleImage's resize and crop methods.
     *
     * @param array<string, mixed> $options
     */
    protected function resize(SimpleImage $image, array $options): SimpleImage
    {
        // just resize, no crop
        if (false === $options['crop']) {
            return $image->resize($options['width'], $options['height']);
        }

        // crop based on focus point
        if (true === Focus::isFocalPoint($options['crop'])) {
            // get crop coords for focal point:
            // if image needs to be cropped, crop before resizing
            if ($focus = Focus::coords(
                $options['crop'],
                $options['sourceWidth'],
                $options['sourceHeight'],
                $options['width'],
                $options['height']
            )) {
                $image->crop(
                    $focus['x1'],
                    $focus['y1'],
                    $focus['x2'],
                    $focus['y2']
                );
            }

            return $image->thumbnail($options['width'], $options['height']);
        }

        // normal crop with crop anchor
        return $image->thumbnail(
            $options['width'],
            $options['height'] ?? $options['width'],
            $options['crop']
        );
    }

    /**
     * Applies the correct blur settings for SimpleImage.
     *
     * @param array<string, mixed> $options
     */
    protected function blur(SimpleImage $image, array $options): SimpleImage
    {
        if (false === $options['blur']) {
            return $image;
        }

        return $image->blur('gaussian', (int) $options['blur']);
    }

    /**
     * Applies grayscale conversion if activated in the options.
     *
     * @param array<string, mixed> $options
     */
    protected function grayscale(SimpleImage $image, array $options): SimpleImage
    {
        if (false === $options['grayscale']) {
            return $image;
        }

        return $image->desaturate();
    }

    /**
     * Applies sharpening if activated in the options.
     *
     * @param array<string, mixed> $options
     */
    protected function sharpen(SimpleImage $image, array $options): SimpleImage
    {
        if (false === is_int($options['sharpen'])) {
            return $image;
        }

        return $image->sharpen($options['sharpen']);
    }

    /**
     * Returns mime type based on `format` option.
     *
     * @param array<string, mixed> $options
     */
    protected function mime(array $options): ?string
    {
        if (null === $options['format']) {
            return null;
        }

        return Mime::fromExtension($options['format']);
    }
}
