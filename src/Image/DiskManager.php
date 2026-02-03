<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Image;

/**
 * Disk manager for registering and resolving disk configurations
 *
 * Maintains a registry of named disks and provides methods to
 * create, retrieve, and manage disk configurations.
 *
 * @license MIT
 */
class DiskManager
{
    /** @var DiskInterface[] */
    private array $disks = [];
    private DiskInterface $defaultDisk;

    public function __construct()
    {
        // Register default disk
        $this->defaultDisk = new Disk('default', '/uploads');
        $this->disks['default'] = $this->defaultDisk;
    }

    /**
     * Register a new disk
     */
    public function register(DiskInterface $disk): self
    {
        $this->disks[$disk->name()] = $disk;
        return $this;
    }

    /**
     * Register multiple disks
     *
     * @param array<string, array{root: string, url?: string}> $disks
     */
    public function registerMultiple(array $disks): self
    {
        foreach ($disks as $name => $config) {
            $disk = new Disk(
                $name,
                $config['root'],
                $config['url'] ?? '',
                array_diff_key($config, array_flip(['root', 'url']))
            );
            $this->register($disk);
        }
        return $this;
    }

    /**
     * Get a disk by name
     */
    public function disk(string $name): DiskInterface
    {
        if (!isset($this->disks[$name])) {
            throw new \InvalidArgumentException("Disk '{$name}' is not registered");
        }

        return $this->disks[$name];
    }

    /**
     * Get all registered disks
     *
     * @return DiskInterface[]
     */
    public function all(): array
    {
        return $this->disks;
    }

    /**
     * Check if a disk is registered
     */
    public function has(string $name): bool
    {
        return isset($this->disks[$name]);
    }

    /**
     * Set the default disk
     */
    public function setDefault(string $name): self
    {
        $this->defaultDisk = $this->disk($name);
        return $this;
    }

    /**
     * Get the default disk
     */
    public function getDefault(): DiskInterface
    {
        return $this->defaultDisk;
    }

    /**
     * Create a disk instance with array configuration
     */
    public static function createDisk(string $name, array $config): DiskInterface
    {
        return new Disk(
            $name,
            $config['root'] ?? '/uploads',
            $config['url'] ?? '',
            array_diff_key($config, array_flip(['root', 'url']))
        );
    }
}
