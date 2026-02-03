<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Template;

/**
 * Shared asset collection for CSS and JavaScript
 *
 * This collection is shared between template and snippet instances,
 * allowing snippets to add assets that bubble up to the parent template.
 *
 * @package   Appkit Core
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
class AssetCollection
{
    protected array $css = [];
    protected array $js = [];

    /**
     * Add CSS file(s) to the collection
     */
    public function addCss(string $url, array $options = []): void
    {
        $this->css[$url] = $options;
    }

    /**
     * Add JavaScript file(s) to the collection
     */
    public function addJs(string $url, array $options = []): void
    {
        $this->js[$url] = $options;
    }

    /**
     * Get all CSS files
     */
    public function getCss(): array
    {
        return $this->css;
    }

    /**
     * Get all JavaScript files
     */
    public function getJs(): array
    {
        return $this->js;
    }

    /**
     * Clear all CSS files
     */
    public function clearCss(): void
    {
        $this->css = [];
    }

    /**
     * Clear all JavaScript files
     */
    public function clearJs(): void
    {
        $this->js = [];
    }
}
