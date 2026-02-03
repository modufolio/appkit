<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Image;


/**
 * @package   Image
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
abstract class Darkroom implements DarkroomInterface
{
    public function __construct(
        protected array $settings = []
    ) {
        $this->settings = [...$this->defaults(), ...$settings];
    }

    /**
     * Returns the default thumb settings
     */
    protected function defaults(): array
    {
        return [
            'autoOrient'  => true,
            'blur'        => false,
            'crop'        => false,
            'format'      => null,
            'grayscale'   => false,
            'height'      => null,
            'quality'     => 90,
            'scaleHeight' => null,
            'scaleWidth'  => null,
            'sharpen'     => null,
            'width'       => null,
        ];
    }

    /**
     * Normalizes all thumb options
     */
    protected function options(array $options = []): array
    {
        $options = [...$this->settings, ...$options];

        // normalize the crop option
        if ($options['crop'] === true) {
            $options['crop'] = 'center';
        }

        // normalize the blur option
        if ($options['blur'] === true) {
            $options['blur'] = 10;
        }

        // normalize the greyscale option
        if (isset($options['greyscale']) === true) {
            $options['grayscale'] = $options['greyscale'];
            unset($options['greyscale']);
        }

        // normalize the bw option
        if (isset($options['bw']) === true) {
            $options['grayscale'] = $options['bw'];
            unset($options['bw']);
        }

        // normalize the sharpen option
        if ($options['sharpen'] === true) {
            $options['sharpen'] = 50;
        }

        $options['quality'] ??= $this->settings['quality'];

        return $options;
    }

    /**
     * Calculates the dimensions of the final thumb based
     * on the given options and returns a full array with
     * all the final options to be used for the image generator
     */
    public function preprocess(string $file, array $options = []): array
    {
        $options = $this->options($options);
        $image   = new Image($file);

        $options['sourceWidth']  = $image->width();
        $options['sourceHeight'] = $image->height();

        $dimensions        = $image->dimensions();
        $thumbDimensions   = $dimensions->thumb($options);

        $options['width']  = $thumbDimensions->width();
        $options['height'] = $thumbDimensions->height();

        // scale ratio compared to the source dimensions
        $options['scaleWidth'] = Focus::ratio(
            $options['width'],
            $options['sourceWidth']
        );
        $options['scaleHeight'] = Focus::ratio(
            $options['height'],
            $options['sourceHeight']
        );

        return $options;
    }

    abstract public function process(string $file, array $options = []): array;
}
