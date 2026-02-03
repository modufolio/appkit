<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Image;

/**
 * Disk configuration for file storage
 *
 * Represents a named storage location with its own root path and URL.
 * Can be used to organize files across different storage locations
 * (e.g., user uploads, product images, etc.).
 *
 * @package   Image
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
class Disk implements DiskInterface
{
    private string $name;
    private string $root;
    private string $url;
    private array $config;

    public function __construct(
        string $name,
        string $root,
        string $url = '',
        array $config = []
    ) {
        $this->name = $name;
        $this->root = rtrim($root, '/');
        $this->url = $url ? rtrim($url, '/') : '';
        $this->config = array_merge(['name' => $name, 'root' => $root, 'url' => $url], $config);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function root(): string
    {
        return $this->root;
    }

    public function url(): string
    {
        return $this->url;
    }

    public function config(): array
    {
        return $this->config;
    }
}
