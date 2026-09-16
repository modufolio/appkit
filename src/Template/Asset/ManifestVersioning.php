<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Template\Asset;

/**
 * Versions an asset by the name a build wrote to its manifest.
 *
 * Two shapes are read. A flat map from the path a template queues to the
 * path the build produced:
 *
 *     {"/assets/css/app.css": "/assets/css/app.3f2a1c.css"}
 *
 * And Vite's `manifest.json`, keyed by source file, where each entry names
 * its output in `file` (and optionally `css` it pulls in, which is not
 * followed — queue those yourself):
 *
 *     {"src/app.js": {"file": "assets/app-3f2a1c.js", "css": [...]}}
 *
 * A path the manifest does not know is returned as it is. The file is read
 * once per process and again when its modification time changes.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
final class ManifestVersioning implements AssetVersioningInterface
{
    /** @var array<string, string> queued path => built path */
    private array $map = [];
    private int $loadedMtime = -1;

    /**
     * @param string $manifestFile absolute path to the manifest
     * @param string $prefix       prepended to Vite-style relative outputs, so `assets/app.js` becomes `/build/assets/app.js`
     */
    public function __construct(
        private readonly string $manifestFile,
        private readonly string $prefix = '/',
    ) {
    }

    public function version(string $path): string
    {
        $this->load();

        return $this->map[$path] ?? $this->map[ltrim($path, '/')] ?? $path;
    }

    private function load(): void
    {
        $mtime = is_file($this->manifestFile) ? (int) filemtime($this->manifestFile) : 0;
        if ($mtime === $this->loadedMtime) {
            return;
        }

        $this->loadedMtime = $mtime;
        $this->map = [];

        if (0 === $mtime) {
            return;
        }

        $json = file_get_contents($this->manifestFile);
        $decoded = false === $json ? null : json_decode($json, true);
        if (!\is_array($decoded)) {
            throw new \RuntimeException(sprintf('Asset manifest "%s" is not a JSON object.', $this->manifestFile));
        }

        foreach ($decoded as $source => $entry) {
            if (!\is_string($source)) {
                continue;
            }
            if (\is_string($entry)) {
                $this->map[$source] = $entry;
            } elseif (\is_array($entry) && isset($entry['file']) && \is_string($entry['file'])) {
                $this->map[$source] = rtrim($this->prefix, '/').'/'.ltrim($entry['file'], '/');
            }
        }
    }
}
