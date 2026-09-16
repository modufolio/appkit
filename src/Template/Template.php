<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Template;

use Modufolio\Appkit\Template\Asset\AssetIntegrity;
use Modufolio\Appkit\Template\Asset\AssetVersioningInterface;
use Modufolio\Appkit\Template\Asset\NoVersioning;
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
    /** @var list<string> */
    protected array $templatePaths = [];

    /** @var list<string> */
    protected array $layoutPaths = [];
    protected string $name;

    /** @var array<string, mixed> */
    protected array $data = [];
    protected ?string $layout = null;

    /** @var array<string, string> */
    protected array $sections = [];
    protected ?string $currentSection = null;
    protected ?ServerRequestInterface $request = null;
    protected ?string $baseUrl = null;
    protected AssetCollection $assets;
    protected AssetVersioningInterface $versioning;
    protected AssetIntegrity $integrity;

    /**
     * @param list<string>                  $templatePaths
     * @param list<string>                  $layoutPaths
     * @param array<string, mixed>          $data
     * @param AssetVersioningInterface|null $versioning    how queued asset paths become the paths
     *                                                     fetched — a content hash, a build manifest;
     *                                                     none by default
     * @param AssetIntegrity|null           $integrity     SRI hashes to render on `<link>` and
     *                                                     `<script>`; none by default
     *
     * Final so that render() can build the layout as `new static`: a
     * subclass keeps its behaviour for layouts without copying render(),
     * and adds state through a setter or a factory rather than the signature
     */
    final public function __construct(
        string $name,
        array $templatePaths = [],
        array $layoutPaths = [],
        array $data = [],
        ?ServerRequestInterface $request = null,
        ?AssetCollection $assets = null,
        ?AssetVersioningInterface $versioning = null,
        ?AssetIntegrity $integrity = null,
    ) {
        $this->name = strtolower($name);
        $this->templatePaths = $templatePaths;
        $this->layoutPaths = $layoutPaths;
        $this->data = $data;
        $this->request = $request;
        $this->assets = $assets ?? new AssetCollection();
        $this->versioning = $versioning ?? new NoVersioning();
        $this->integrity = $integrity ?? new AssetIntegrity();

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
     * @param list<string> $paths
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
     * @param list<string> $paths
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
     * @param string|int|float|\Stringable|null $string  The untrusted value (null becomes '')
     * @param string                            $context One of: html, attr, js, css, url
     */
    public function esc(string|int|float|\Stringable|null $string, string $context = 'html'): string
    {
        return Str::esc($string, $context);
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

        $output = ob_get_clean();
        $this->sections[$this->currentSection] = false === $output ? '' : $output;
        $this->currentSection = null;
    }

    /**
     * Collect CSS file(s) for later rendering.
     *
     * @param string|list<string>       $url     Single URL or array of URLs
     * @param array<string, mixed>|null $options Additional HTML attributes (e.g., ['media' => 'print'])
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
     * @param string|list<string>            $url     Single URL or array of URLs
     * @param array<string, mixed>|bool|null $options HTML attributes or boolean for async
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
     * The URL to fetch an asset from: the queued path, versioned by the
     * configured strategy, under the request's base URL. What `renderCss()`
     * and `renderJs()` use; call it yourself for an image or a font:
     *
     *     <img src="<?= $this->asset('/assets/img/logo.svg') ?>">
     *
     * An absolute URL passes through untouched.
     */
    public function asset(string $path): string
    {
        return $this->url($this->versioning->version($path));
    }

    /**
     * Render all collected CSS link tags.
     *
     * Each carries `integrity` and `crossorigin="anonymous"` when the SRI
     * map knows the queued path.
     */
    public function renderCss(): string
    {
        $links = [];

        foreach ($this->assets->getCss() as $url => $options) {
            $attr = array_merge($options, [
                'href' => $this->asset($url),
                'rel' => 'stylesheet',
            ]);

            $links[] = '<link '.\Modufolio\Appkit\Toolkit\Html::attr($this->withIntegrity($url, $attr)).'>';
        }

        return implode(PHP_EOL, $links);
    }

    /**
     * Render all collected JavaScript script tags, with `integrity` where
     * the SRI map knows the queued path.
     */
    public function renderJs(): string
    {
        $scripts = [];

        foreach ($this->assets->getJs() as $url => $options) {
            $attr = array_merge($options, ['src' => $this->asset($url)]);
            $scripts[] = '<script '.\Modufolio\Appkit\Toolkit\Html::attr($this->withIntegrity($url, $attr)).'></script>';
        }

        return implode(PHP_EOL, $scripts);
    }

    /**
     * @param array<string, mixed> $attr
     *
     * @return array<string, mixed>
     */
    private function withIntegrity(string $queuedPath, array $attr): array
    {
        $integrity = $this->integrity->for($queuedPath);
        if (null === $integrity) {
            return $attr;
        }

        // The map is keyed by the queued path so versioning does not change
        // the lookup; the hash is of the content, which versioning keeps.
        return $attr + ['integrity' => $integrity, 'crossorigin' => 'anonymous'];
    }

    /**
     * Calculate base URL from request.
     *
     * The host is copied from the request as-is; the kernel has already checked
     * it against the trusted-hosts allowlist before any request state or
     * template exists (see Kernel::createState()). Templates rendered outside
     * the kernel must be handed a request whose host is known to be safe.
     */
    protected function calculateBaseUrl(ServerRequestInterface $request): string
    {
        $uri = $request->getUri();
        $scheme = $uri->getScheme();
        $host = $uri->getHost();
        $port = $uri->getPort();

        // A request built without an absolute URI (console, tests, some SAPIs)
        // has no scheme or host. Returning "://" would make url('/x') render as
        // ":/x" — emit root-relative URLs instead.
        if ('' === $scheme || '' === $host) {
            return '';
        }

        $base = $scheme.'://'.$host;

        // PSR-7 getPort() returns null for the scheme's default port — only a
        // real, non-default port belongs in the URL (null used to render as
        // a dangling "host:" colon).
        if (null !== $port && !(('http' === $scheme && 80 === $port) || ('https' === $scheme && 443 === $port))) {
            $base .= ':'.$port;
        }

        return $base;
    }

    /**
     * Generate URL from path.
     */
    public function url(string $path = ''): string
    {
        // A full URL — a CDN, another origin — is already where to fetch from.
        if (str_contains($path, '://') || str_starts_with($path, '//')) {
            return $path;
        }

        if (null !== $this->baseUrl) {
            $baseUrl = rtrim($this->baseUrl, '/');
            $path = ltrim($path, '/');

            if ('' === $path) {
                // Root of a request without scheme/host is "/", not "".
                return '' === $baseUrl ? '/' : $baseUrl;
            }

            return $baseUrl.'/'.$path;
        }

        // Return path as-is when no request provided
        return '/'.ltrim($path, '/');
    }

    /**
     * Render a snippet/view.
     *
     * Snippets have access to $this (cloned Template instance) for nested snippet calls.
     * Cloning prevents snippets from accidentally modifying parent template state.
     *
     * @param array<string, mixed> $data
     */
    public function snippet(string $name, array $data = []): ?string
    {
        // Use snippet paths from constructor, fallback to BASE_DIR/site/snippets.
        // Guard the BASE_DIR constant so a misconfigured app fails as a clean
        // "snippet not found" rather than a fatal undefined-constant error (TPL4).
        $snippetPaths = !empty($this->templatePaths)
            ? array_map(static fn ($p) => str_replace('/templates', '/snippets', $p), $this->templatePaths)
            : (defined('BASE_DIR') ? [BASE_DIR.'/site/snippets'] : []);

        // Clone this template instance to prevent state pollution
        $snippetContext = clone $this;
        $snippetContext->data = array_merge($this->data, $data);

        return $snippetContext->renderSnippet($name, $snippetPaths);
    }

    /**
     * Internal method to render a snippet file with $this context.
     *
     * @param list<string> $snippetPaths
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

            $output = ob_get_clean();

            return false === $output ? '' : $output;
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
     * @param array<string, mixed> $data Additional data to merge
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
        $content = false === $content ? '' : $content;

        // Paranoid buffer cleanup: close any nested buffers that weren't closed
        // This prevents buffer corruption in RoadRunner workers if templates misbehave
        while (ob_get_level() > $level) {
            ob_end_clean();
        }

        if (null !== $exception) {
            throw $exception;
        }

        // Snapshot per-render state, then clear it on this instance so a reused
        // template (e.g. pooled in a long-running worker) does not leak sections
        // or a layout into the next render. The snapshot is still handed to the
        // layout below, preserving normal layout behaviour.
        $sections = $this->sections;
        $layout = $this->layout;
        $this->sections = [];
        $this->layout = null;

        // Render the layout if one is defined
        if (null !== $layout) {
            // `static`, so a subclass renders its layouts as itself and keeps
            // its own behaviour without copying this method.
            $layoutTemplate = new static(
                $layout,
                // Layout paths first — the layout file lives there — with the
                // template paths kept behind them, so a snippet() call from
                // inside a layout still resolves site/snippets: snippet() derives
                // its search directories from templatePaths.
                [...$this->layoutPaths, ...$this->templatePaths],
                [],
                array_merge($this->data, [
                    'content' => $content,
                ]),
                $this->request,
                $this->assets,  // Share the same asset collection
                $this->versioning,
                $this->integrity,
            );

            // Copy sections to layout template
            $layoutTemplate->sections = $sections;

            return $layoutTemplate->render();
        }

        return $content;
    }
}
