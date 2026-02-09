<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Image;

/**
 * Image processor using composable transformation pipeline
 *
 * Inspired by functional composition patterns from modern image processing
 * libraries (imagepipe, imageproc). Allows stacking transformations that are
 * applied in sequence to produce final output variants.
 *
 * @package   Image
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
class ImageProcessor
{
    /** @var Transformation[] */
    private array $transformations = [];
    private FileInterface $file;
    private StorageInterface $storage;
    private JobStorageInterface $jobStorage;

    public function __construct(
        FileInterface $file,
        StorageInterface $storage,
        JobStorageInterface $jobStorage
    ) {
        $this->file = $file;
        $this->storage = $storage;
        $this->jobStorage = $jobStorage;
    }

    /**
     * Add a transformation to the pipeline
     */
    public function add(Transformation $transformation): self
    {
        $this->transformations[] = $transformation;
        return $this;
    }

    /**
     * Process the image through the transformation pipeline
     *
     * @throws ImageException If file validation or transformation fails
     */
    public function process(): ImageVariant|FileInterface|null
    {
        if ($this->file === null) {
            return null;
        }

        // Validate source file is processable
        if (!$this->file->isResizable()) {
            return $this->file;
        }

        // Additional validation that source file exists and is readable
        if (!file_exists($this->file->root())) {
            throw ImageException::fileNotFound($this->file->root());
        }

        if (!is_readable($this->file->root())) {
            throw ImageException::fileNotReadable($this->file->root());
        }

        $mediaRoot = dirname($this->file->mediaRoot());

        if (empty($this->transformations)) {
            // No transformations — serve the original via the media path
            $thumbName = $this->file->filename();
            $thumbRoot = $mediaRoot . '/' . $thumbName;

            if (!file_exists($thumbRoot)) {
                $this->jobStorage->saveJob($mediaRoot, $thumbName, [
                    'filename' => $this->file->relativePathFromUploads(),
                    'transformations' => [],
                ]);
            }

            return new ImageVariant([
                'modifications' => [],
                'original' => $this->file,
                'root' => $thumbRoot,
                'url' => dirname($this->file->mediaUrl()) . '/' . $thumbName,
            ]);
        }

        // Collect all transformation options
        $allOptions = [];
        foreach ($this->transformations as $transformation) {
            $allOptions = array_merge($allOptions, $transformation->config());
        }

        // Generate thumb path based on combined transformations
        $template = $mediaRoot . '/{{ name }}{{ attributes }}.{{ extension }}';
        $thumbRoot = (new CustomFilename($this->file->root(), $template, $allOptions))->toString();
        $thumbName = basename($thumbRoot);

        // Save job if thumb doesn't exist
        if (!file_exists($thumbRoot)) {
            $jobOptions = array_merge($allOptions, [
                'filename' => $this->file->relativePathFromUploads(),
                'transformations' => $this->getTransformationNames(),
            ]);

            $this->jobStorage->saveJob($mediaRoot, $thumbName, $jobOptions);
        }

        return new ImageVariant([
            'modifications' => $allOptions,
            'original' => $this->file,
            'root' => $thumbRoot,
            'url' => dirname($this->file->mediaUrl()) . '/' . $thumbName,
        ]);
    }

    /**
     * Get list of transformation names in pipeline
     */
    public function getTransformationNames(): array
    {
        return array_map(fn(Transformation $t) => $t->name(), $this->transformations);
    }

    /**
     * Get all transformation configurations
     */
    public function getConfigurations(): array
    {
        return array_map(fn(Transformation $t) => [
            'name' => $t->name(),
            'config' => $t->config(),
        ], $this->transformations);
    }

    /**
     * Clear the transformation pipeline
     */
    public function clear(): self
    {
        $this->transformations = [];
        return $this;
    }
}
