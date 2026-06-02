<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Template;

use Modufolio\Appkit\Toolkit\Str;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Self-contained Template class - RoadRunner-safe.
 *
 * Inspired by Kirby Layouts but refactored to use instance-based state
 * instead of static properties for RoadRunner compatibility.
 *
 * Each Template instance is independent with its own paths, data, and sections.
 * No global state = no memory leaks in long-running workers.
 *
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
        ?AssetCollection $assets = null,
    ) {
        $this->name = strtolower($name);
        $this->templatePaths = $templatePaths;
        $this->layoutPaths = $layoutPaths;
        $this->data = $data;
        $this->request = $request;
        $this->assets = $assets ?? new AssetCollection();

        if (null !== $request) {
            $this->baseUrl = $this->calculateBaseUrl($request);
        }
    }

    public function __toString(): string
    {
        return $this->name;
    }

    /**
     * Clone handler - ensures asset collection is shared between clones.
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
     * Add a template path.
     */
    public function addTemplatePath(string $path): self
    {
        $this->templatePaths[] = rtrim($path, '/');

        return $this;
    }

    /**
     * Add a layout path.
     */
    public function addLayoutPath(string $path): self
    {
        $this->layoutPaths[] = rtrim($path, '/');

        return $this;
    }

    /**
     * Set the layout for the current template.
     */
    public function layout(string $layout): self
    {
        $this->layout = $layout;

        return $this;
    }

    /**
     * Get template name.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Check if template file exists.
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
     * Get the resolved template file path.
     */
    public function file(): string
    {
        return $this->resolveFile($this->name, $this->templatePaths);
    }

    /**
     * Resolve the template or layout file.
     *
     * @throws \RuntimeException
     */
    protected function resolveFile(string $name, array $paths): string
    {
        $file = $this->secureResolve($name, $paths);

        if (null !== $file) {
            return $file;
        }

        $searchedPaths = implode(', ', $paths);
        throw new \RuntimeException("Template '{$name}.php' not found in: {$searchedPaths}");
    }

    /**
     * Securely resolve a template/snippet/layout name to a file inside one of
     * the configured roots.
     *
     * @return string|null Absolute path on success, null if not found or unsafe
     */
    protected function secureResolve(string $name, array $paths): ?string
    {
        if ($this->isUnsafeName($name)) {
            return null;
        }

        foreach ($paths as $path) {
            $root = realpath($path);
            if (false === $root) {
                continue;
            }

            $real = realpath($path.'/'.$name.'.php');
            if (
                false !== $real
                && is_file($real)
                && str_starts_with($real, rtrim($root, '/\\').DIRECTORY_SEPARATOR)
            ) {
                return $real;
            }
        }

        return null;
    }

    /**
     * Whether a template/snippet name is unsafe to resolve. Subdirectories are
     * allowed (e.g. "errors/default"), but traversal, absolute paths, backslashes
     * and null bytes are rejected outright.
     */
    protected function isUnsafeName(string $name): bool
    {
        return '' === $name
            || str_contains($name, "\0")
            || str_contains($name, '\\')
            || str_contains($name, '..')
            || str_starts_with($name, '/');
    }

    /**
     * Context-aware output escaping helper for templates (XSS protection).
     *
     * Usage inside a template:
     *   <?= $this->esc($user->name) ?>            // HTML text (default)
     *   <input value="<?= $this->esc($q, 'attr') ?>">
     *   <script>var t = "<?= $this->esc($t, 'js') ?>";</script>
     *
     * @param string|null $string  The untrusted value (null becomes '')
     * @param string      $context One of: html, attr, js, css, url
     */
    public function esc(?string $string, string $context = 'html'): string
    {
        return Str::esc((string) $string, $context);
    }

    /**
     * Start a section (for layouts).
     */
    public function start(string $name): void
    {
        if (null !== $this->currentSection) {
            throw new \RuntimeException("A section is already being captured: {$this->currentSection}");
        }

        $this->currentSection = $name;
        ob_start();
    }

    /**
     * End the currently captured section.
     */
    public function end(): void
    {
        if (null === $this->currentSection) {
            throw new \RuntimeException('No section is currently being captured.');
        }

        $this->sections[$this->currentSection] = ob_get_clean();
        $this->currentSection = null;
    }

    /**
     * Collect CSS file(s) for later rendering.
     *
     * @param string|array $url     Single URL or array of URLs
     * @param array|null   $options Additional HTML attributes (e.g., ['media' => 'print'])
     */
    public function css(string|array $url, ?array $options = null): void
    {
        foreach ((array) $url as $u) {
            $this->assets->addCss($u, $options ?? []);
        }
    }

    /**
     * Collect JavaScript file(s) for later rendering.
     *
     * @param string|array    $url     Single URL or array of URLs
     * @param array|bool|null $options HTML attributes or boolean for async
     */
    public function js(string|array $url, array|bool|null $options = null): void
    {
        if (is_bool($options)) {
            $options = ['async' => $options];
        }

        foreach ((array) $url as $u) {
            $this->assets->addJs($u, $options ?? []);
        }
    }

    /**
     * Render all collected CSS link tags.
     */
    public function renderCss(): string
    {
        $links = [];

        foreach ($this->assets->getCss() as $url => $options) {
            $attr = array_merge($options, [
                'href' => $this->url($url),
                'rel' => 'stylesheet',
            ]);

            $links[] = '<link '.\Modufolio\Appkit\Toolkit\Html::attr($attr).'>';
        }

        return implode(PHP_EOL, $links);
    }

    /**
     * Render all collected JavaScript script tags.
     */
    public function renderJs(): string
    {
        $scripts = [];

        foreach ($this->assets->getJs() as $url => $options) {
            $attr = array_merge($options, ['src' => $this->url($url)]);
            $scripts[] = '<script '.\Modufolio\Appkit\Toolkit\Html::attr($attr).'></script>';
        }

        return implode(PHP_EOL, $scripts);
    }

    /**
     * Calculate base URL from request.
     */
    protected function calculateBaseUrl(ServerRequestInterface $request): string
    {
        $uri = $request->getUri();
        $scheme = $uri->getScheme();
        $host = $uri->getHost();
        $port = $uri->getPort();

        $base = $scheme.'://'.$host;

        if (('http' === $scheme && 80 !== $port) || ('https' === $scheme && 443 !== $port)) {
            $base .= ':'.$port;
        }

        return $base;
    }

    /**
     * Generate URL from path.
     */
    public function url(string $path = ''): string
    {
        if (null !== $this->baseUrl) {
            $baseUrl = rtrim($this->baseUrl, '/');
            $path = ltrim($path, '/');

            return '' === $path ? $baseUrl : $baseUrl.'/'.$path;
        }

        // Return path as-is when no request provided
        return '/'.ltrim($path, '/');
    }

    /**
     * Render a snippet/view.
     *
     * Snippets have access to $this (cloned Template instance) for nested snippet calls.
     * Cloning prevents snippets from accidentally modifying parent template state.
     */
    public function snippet(string $name, array $data = []): ?string
    {
        // Use snippet paths from constructor, fallback to BASE_DIR/site/snippets.
        // Guard the BASE_DIR constant so a misconfigured app fails as a clean
        // "snippet not found" rather than a fatal undefined-constant error (TPL4).
        $snippetPaths = !empty($this->templatePaths)
            ? array_map(fn ($p) => str_replace('/templates', '/snippets', $p), $this->templatePaths)
            : (defined('BASE_DIR') ? [BASE_DIR.'/site/snippets'] : []);

        // Clone this template instance to prevent state pollution
        $snippetContext = clone $this;
        $snippetContext->data = array_merge($this->data, $data);

        return $snippetContext->renderSnippet($name, $snippetPaths);
    }

    /**
     * Internal method to render a snippet file with $this context.
     *
     * @internal
     */
    protected function renderSnippet(string $name, array $snippetPaths): ?string
    {
        // Resolve with the same traversal/containment guards as templates (TPL1).
        $file = $this->secureResolve($name, $snippetPaths);

        if (null !== $file) {
            ob_start();

            // Extract user data, but skip if it would overwrite existing variables
            // Protects internal variables from being overwritten by snippet data
            extract($this->data, EXTR_SKIP);

            // Include in this context so $this is available in the snippet
            include $file;

            return ob_get_clean();
        }

        throw new \RuntimeException("Snippet '{$name}.php' not found in: ".implode(', ', $snippetPaths));
    }

    /**
     * Retrieve a section's content.
     */
    public function section(string $name, string $default = ''): string
    {
        return $this->sections[$name] ?? $default;
    }

    /**
     * Render the template.
     *
     * @param array $data Additional data to merge
     *
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
            if (null !== $this->currentSection) {
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

        if (null !== $exception) {
            throw $exception;
        }

        // Render the layout if one is defined
        if (null !== $this->layout) {
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
