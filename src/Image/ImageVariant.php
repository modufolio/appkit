<?php

namespace Modufolio\Appkit\Image;

/**
 * Image variant representation
 *
 * @package   Image
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
class ImageVariant
{
    protected string $root;
    protected string $url;
    protected array $modifications;
    protected FileInterface $original;

    /**
     * @throws \InvalidArgumentException
     */
    public function __construct(array $props)
    {
        $this->root          = $props['root'] ?? throw new \InvalidArgumentException('Missing root path');
        $this->url           = $props['url'] ?? throw new \InvalidArgumentException('Missing URL');
        $this->original      = $props['original'] ?? throw new \InvalidArgumentException('Missing original file');
        $this->modifications = $props['modifications'] ?? [];
    }

    public function root(): string
    {
        return $this->root;
    }

    public function url(): string
    {
        return $this->url;
    }

    public function filename(): string
    {
        return basename($this->root);
    }

    public function extension(): string
    {
        return pathinfo($this->filename(), PATHINFO_EXTENSION);
    }

    public function name(): string
    {
        return pathinfo($this->filename(), PATHINFO_FILENAME);
    }

    public function modifications(): array
    {
        return $this->modifications;
    }

    public function original(): FileInterface
    {
        return $this->original;
    }

    public function exists(): bool
    {
        return file_exists($this->root);
    }

    public function toArray(): array
    {
        return [
            'filename'      => $this->filename(),
            'url'           => $this->url(),
            'root'          => $this->root(),
            'modifications' => $this->modifications(),
        ];
    }
}
