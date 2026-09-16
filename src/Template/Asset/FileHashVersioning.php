<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Template\Asset;

/**
 * Versions an asset by a hash of the file's own content.
 *
 * For a file that exists under the public directory the hash goes either
 * into the name — `/assets/css/app.css` becomes
 * `/assets/css/app.<hash>.css`, which needs the web server to map that name
 * back to the file (see docs/deployment.md) — or into the query string,
 * `/assets/css/app.css?v=<hash>`, which needs nothing but is ignored by some
 * caches. A path that names no file is returned as it is.
 *
 * Hashes are cached per process, keyed by path and modification time, so a
 * long-running worker hashes each file once and notices a redeploy. The
 * hash is xxh128: fast, and 32 hex characters like the md5 the pattern
 * below also accepts, so names produced by older tooling still resolve.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
final class FileHashVersioning implements AssetVersioningInterface
{
    public const IN_FILENAME = 'filename';
    public const IN_QUERY = 'query';

    /** A versioned name: `name.<32 hex>.ext`. */
    public const VERSIONED_NAME = '/^(.+)\.([0-9a-f]{32})\.([A-Za-z0-9]+)$/';

    /** @var array<string, array{int, string}> path => [mtime, hash] */
    private array $hashes = [];

    /**
     * @param string $publicDir the directory root-relative paths are resolved against
     * @param string $placement IN_FILENAME or IN_QUERY
     */
    public function __construct(
        private readonly string $publicDir,
        private readonly string $placement = self::IN_FILENAME,
    ) {
        if (!\in_array($placement, [self::IN_FILENAME, self::IN_QUERY], true)) {
            throw new \InvalidArgumentException(sprintf('Placement must be "%s" or "%s"; "%s" given.', self::IN_FILENAME, self::IN_QUERY, $placement));
        }
    }

    public function version(string $path): string
    {
        if (str_contains($path, '://') || str_starts_with($path, '//') || str_contains($path, '?')) {
            return $path;
        }

        $file = rtrim($this->publicDir, '/').'/'.ltrim($path, '/');
        if (!is_file($file)) {
            return $path;
        }

        $hash = $this->hash($file);

        if (self::IN_QUERY === $this->placement) {
            return $path.'?v='.$hash;
        }

        $dot = strrpos($path, '.');
        $slash = strrpos($path, '/');
        if (false === $dot || (false !== $slash && $dot < $slash)) {
            // No extension: nothing to put the hash in front of.
            return $path.'?v='.$hash;
        }

        return substr($path, 0, $dot).'.'.$hash.substr($path, $dot);
    }

    /**
     * The path a versioned name stands for, or null when the name is not
     * one: `/assets/css/app.<hash>.css` gives `/assets/css/app.css`. For a
     * dev server or a fallback handler that must find the real file.
     */
    public static function unversion(string $path): ?string
    {
        if (1 !== preg_match(self::VERSIONED_NAME, $path, $m)) {
            return null;
        }

        return $m[1].'.'.$m[3];
    }

    private function hash(string $file): string
    {
        $mtime = (int) filemtime($file);

        if (isset($this->hashes[$file]) && $this->hashes[$file][0] === $mtime) {
            return $this->hashes[$file][1];
        }

        $hash = hash_file('xxh128', $file);
        if (false === $hash) {
            throw new \RuntimeException(sprintf('Could not hash asset "%s".', $file));
        }

        $this->hashes[$file] = [$mtime, $hash];

        return $hash;
    }
}
