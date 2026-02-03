<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Template;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Self-contained Template class - RoadRunner-safe
 *
 * Inspired by Kirby Layouts but refactored to use instance-based state
 * instead of static properties for RoadRunner compatibility.
 *
 * Each Template instance is independent with its own paths, data, and sections.
 * No global state = no memory leaks in long-running workers.
 *
 * @package   Appkit Core
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
class Template implements \Stringable
{
    protected array $templatePaths = [];
    protected array $layoutPaths = [];
    protected string $name;
    protected array $data = [];
    protected ?string $layout = null;
    protected array $sections = [];
    protected ?string $currentSection = null;
    protected ?ServerRequestInterface $request = null;
    protected ?string $baseUrl = null;
    protected AssetCollection $assets;

    public function __construct(
        string $name,
        array $templatePaths = [],
        array $layoutPaths = [],
        array $data = [],
        ?ServerRequestInterface $request = null,
        ?AssetCollection $assets = null
    ) {
        $this->name = strtolower($name);
        $this->templatePaths = $templatePaths;
        $this->layoutPaths = $layoutPaths;
        $this->data = $data;
        $this->request = $request;
        $this->assets = $assets ?? new AssetCollection();

        if ($request !== null) {
            $this->baseUrl = $this->calculateBaseUrl($request);
        }
    }

    public function __toString(): string
    {
        return $this->name;
    }

    /**
     * Clone handler - ensures asset collection is shared between clones
     *
     * When cloning for snippets, the asset collection remains shared
     * so snippets can add CSS/JS that bubbles up to the parent template.
     */
    public function __clone(): void
    {
        // AssetCollection is intentionally NOT cloned - it remains shared
        // This allows snippets to add assets to the parent template's collection
    }

    /**
     * Add a template path
     */
    public function addTemplatePath(string $path): self
    {
        $this->templatePaths[] = rtrim($path, '/');
        return $this;
    }

    /**
     * Add a layout path
     */
    public function addLayoutPath(string $path): self
    {
        $this->layoutPaths[] = rtrim($path, '/');
        return $this;
    }

    /**
     * Set the layout for the current template
     */
    public function layout(string $layout): self
    {
        $this->layout = $layout;
        return $this;
    }

    /**
     * Get template name
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Check if template file exists
     */
    public function exists(): bool
    {
        try {
            $this->resolveFile($this->name, $this->templatePaths);
            return true;
        } catch (\RuntimeException $e) {
            return false;
        }
    }

    /**
     * Get the resolved template file path
     */
    public function file(): string
    {
        return $this->resolveFile($this->name, $this->templatePaths);
    }

    /**
     * Resolve the template or layout file
     *
     * @param string $name
     * @param array $paths
     * @return string
     * @throws \RuntimeException
     */
    protected function resolveFile(string $name, array $paths): string
    {
        foreach ($paths as $path) {
            $file = "{$path}/{$name}.php";
            if (file_exists($file)) {
                return $file;
            }
        }

        $searchedPaths = implode(', ', $paths);
        throw new \RuntimeException(
            "Template '{$name}.php' not found in: {$searchedPaths}"
        );
    }

    /**
     * Start a section (for layouts)
     */
    public function start(string $name): void
    {
        if ($this->currentSection !== null) {
            throw new \RuntimeException("A section is already being captured: {$this->currentSection}");
        }

        $this->currentSection = $name;
        ob_start();
    }

    /**
     * End the currently captured section
     */
    public function end(): void
    {
        if ($this->currentSection === null) {
            throw new \RuntimeException('No section is currently being captured.');
        }

        $this->sections[$this->currentSection] = ob_get_clean();
        $this->currentSection = null;
    }

    /**
     * Collect CSS file(s) for later rendering
     *
     * @param string|array $url Single URL or array of URLs
     * @param array|null $options Additional HTML attributes (e.g., ['media' => 'print'])
     * @return void
     */
    public function css(string|array $url, ?array $options = null): void
    {
        foreach ((array)$url as $u) {
            $this->assets->addCss($u, $options ?? []);
        }
    }

    /**
     * Collect JavaScript file(s) for later rendering
     *
     * @param string|array $url Single URL or array of URLs
     * @param array|bool|null $options HTML attributes or boolean for async
     * @return void
     */
    public function js(string|array $url, array|bool|null $options = null): void
    {
        if (is_bool($options)) {
            $options = ['async' => $options];
        }

        foreach ((array)$url as $u) {
            $this->assets->addJs($u, $options ?? []);
        }
    }

    /**
     * Render all collected CSS link tags
     *
     * @return string
     */
    public function renderCss(): string
    {
        $links = [];

        foreach ($this->assets->getCss() as $url => $options) {
            $attr = array_merge($options, [
                'href' => $this->url($url),
                'rel' => 'stylesheet',
            ]);

            $links[] = '<link ' . \Modufolio\Appkit\Toolkit\Html::attr($attr) . '>';
        }

        return implode(PHP_EOL, $links);
    }

    /**
     * Render all collected JavaScript script tags
     *
     * @return string
     */
    public function renderJs(): string
    {
        $scripts = [];

        foreach ($this->assets->getJs() as $url => $options) {
            $attr = array_merge($options, ['src' => $this->url($url)]);
            $scripts[] = '<script ' . \Modufolio\Appkit\Toolkit\Html::attr($attr) . '></script>';
        }


        return implode(PHP_EOL, $scripts);
    }

    /**
     * Calculate base URL from request
     */
    protected function calculateBaseUrl(ServerRequestInterface $request): string
    {
        $uri = $request->getUri();
        $scheme = $uri->getScheme();
        $host = $uri->getHost();
        $port = $uri->getPort();

        $base = $scheme . '://' . $host;

        if (($scheme === 'http' && $port !== 80) || ($scheme === 'https' && $port !== 443)) {
            $base .= ':' . $port;
        }

        return $base;
    }

    /**
     * Generate URL from path
     */
    public function url(string $path = ''): string
    {
        if ($this->baseUrl !== null) {
            $baseUrl = rtrim($this->baseUrl, '/');
            $path = ltrim($path, '/');
            return $path === '' ? $baseUrl : $baseUrl . '/' . $path;
        }

        // Return path as-is when no request provided
        return '/' . ltrim($path, '/');
    }

    /**
     * Render a snippet/view
     *
     * Snippets have access to $this (cloned Template instance) for nested snippet calls.
     * Cloning prevents snippets from accidentally modifying parent template state.
     */
    public function snippet(string $name, array $data = []): ?string
    {
        // Use snippet paths from constructor, fallback to BASE_DIR/site/snippets
        $snippetPaths = !empty($this->templatePaths)
            ? array_map(fn($p) => str_replace('/templates', '/snippets', $p), $this->templatePaths)
            : [BASE_DIR . '/site/snippets'];

        // Clone this template instance to prevent state pollution
        $snippetContext = clone $this;
        $snippetContext->data = array_merge($this->data, $data);

        return $snippetContext->renderSnippet($name, $snippetPaths);
    }

    /**
     * Internal method to render a snippet file with $this context
     *
     * @internal
     */
    protected function renderSnippet(string $name, array $snippetPaths): ?string
    {
        foreach ($snippetPaths as $path) {
            $file = "{$path}/{$name}.php";
            if (file_exists($file)) {
                ob_start();

                // Extract user data, but skip if it would overwrite existing variables
                // Protects internal variables from being overwritten by snippet data
                extract($this->data, EXTR_SKIP);

                // Include in this context so $this is available in the snippet
                include $file;

                return ob_get_clean();
            }
        }

        throw new \RuntimeException(
            "Snippet '{$name}.php' not found in: " . implode(', ', $snippetPaths)
        );
    }

    /**
     * Retrieve a section's content
     */
    public function section(string $name, string $default = ''): string
    {
        return $this->sections[$name] ?? $default;
    }

    /**
     * Render the template
     *
     * @param array $data Additional data to merge
     * @return string
     * @throws \Throwable
     */
    public function render(array $data = []): string
    {
        $this->data = array_merge($this->data, $data);

        // Record the output buffer level to ensure complete cleanup
        $level = ob_get_level();
        ob_start();

        $exception = null;
        try {
            // Set protected variables BEFORE extract to prevent data from overwriting them
            $template = $this;

            // Extract user data, but skip if it would overwrite existing variables
            // This prevents data like ['template' => 'foo'] from breaking the template
            extract($this->data, EXTR_SKIP);

            include $this->resolveFile($this->name, $this->templatePaths);
        } catch (\Throwable $e) {
            $exception = $e;
        } finally {
            // Clean up any unclosed sections (RoadRunner safety)
            if ($this->currentSection !== null) {
                ob_end_clean();
                $this->currentSection = null;
            }
        }

        // Capture content from our buffer
        $content = ob_get_clean();

        // Paranoid buffer cleanup: close any nested buffers that weren't closed
        // This prevents buffer corruption in RoadRunner workers if templates misbehave
        while (ob_get_level() > $level) {
            ob_end_clean();
        }

        if ($exception !== null) {
            throw $exception;
        }

        // Render the layout if one is defined
        if ($this->layout !== null) {
            $layoutTemplate = new self(
                $this->layout,
                $this->layoutPaths,
                [],
                array_merge($this->data, [
                    'content' => $content,
                ]),
                $this->request,
                $this->assets  // Share the same asset collection
            );

            // Copy sections to layout template
            $layoutTemplate->sections = $this->sections;

            return $layoutTemplate->render();
        }

        return $content;
    }
}
