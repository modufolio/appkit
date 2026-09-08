<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Image;

/**
 * Interface for image transformations.
 *
 * Allows composable, pluggable image processing operations.
 * Inspired by functional composition patterns found in
 * modern image processing libraries (Rust image-rs, imageproc).
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
interface Transformation
{
    /**
     * Apply transformation to file and return result path/URL.
     *
     * @return array{root: string, url: string}
     */
    public function apply(FileInterface $file, StorageInterface $storage): array;

    /**
     * Get a human-readable name for this transformation.
     */
    public function name(): string;

    /**
     * Get the configuration for this transformation.
     *
     * Keyed by Darkroom option name (`width`, `crop`, `blur`, `sharpen`,
     * `grayscale`, `quality`, …): ImageProcessor merges these into the variant's
     * filename attributes and into the stored job, and the job is later handed
     * to a Darkroom verbatim. A key the Darkroom does not recognise is silently
     * ignored, so the regenerated file would be the untransformed original.
     *
     * @return array<string, mixed>
     */
    public function config(): array;
}
