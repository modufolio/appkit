<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Template;

/**
 * Shared asset collection for CSS and JavaScript.
 *
 * This collection is shared between template and snippet instances,
 * allowing snippets to add assets that bubble up to the parent template.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
class AssetCollection
{
    /** @var array<string, array<string, mixed>> */
    protected array $css = [];

    /** @var array<string, array<string, mixed>> */
    protected array $js = [];

    /**
     * Add CSS file(s) to the collection.
     *
     * @param array<string, mixed> $options
     */
    public function addCss(string $url, array $options = []): void
    {
        $this->css[$url] = $options;
    }

    /**
     * Add JavaScript file(s) to the collection.
     *
     * @param array<string, mixed> $options
     */
    public function addJs(string $url, array $options = []): void
    {
        $this->js[$url] = $options;
    }

    /**
     * Get all CSS files.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getCss(): array
    {
        return $this->css;
    }

    /**
     * Get all JavaScript files.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getJs(): array
    {
        return $this->js;
    }

    /**
     * Clear all CSS files.
     */
    public function clearCss(): void
    {
        $this->css = [];
    }

    /**
     * Clear all JavaScript files.
     */
    public function clearJs(): void
    {
        $this->js = [];
    }
}
