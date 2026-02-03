<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Image;

/**
 * Interface for storage disks
 *
 * A disk represents a named storage location with its own configuration.
 * Inspired by Laravel's disk concept but tailored for image processing.
 *
 * @package   Image
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
interface DiskInterface
{
    /**
     * Get the disk name/identifier
     */
    public function name(): string;

    /**
     * Get the root directory path for this disk
     */
    public function root(): string;

    /**
     * Get the base URL for accessing files on this disk
     */
    public function url(): string;

    /**
     * Get disk configuration as array
     */
    public function config(): array;
}
