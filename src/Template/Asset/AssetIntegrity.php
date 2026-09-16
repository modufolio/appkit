<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Template\Asset;

/**
 * Subresource Integrity hashes for the assets a template renders.
 *
 * A map from the path a template queues (`/assets/js/app.js`) to the
 * `integrity` value the browser checks (`sha384-…`). With one present the
 * rendered `<link>` and `<script>` carry `integrity` and
 * `crossorigin="anonymous"`, so a file altered on a CDN or by a compromised
 * server is refused rather than run. Generate the map after every build
 * with `assets:sri`; it is keyed by the *queued* path, so versioning does
 * not change the lookup.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
final class AssetIntegrity
{
    /** @var array<string, string> */
    private readonly array $map;

    /**
     * @param array<string, string> $map queued path => integrity value
     */
    public function __construct(array $map = [])
    {
        $normalised = [];
        foreach ($map as $path => $integrity) {
            $normalised['/'.ltrim($path, '/')] = $integrity;
        }
        $this->map = $normalised;
    }

    /**
     * From a PHP file returning the map — what `assets:sri` writes. A missing
     * file is an empty map, so the same wiring works before the first build.
     */
    public static function fromFile(string $file): self
    {
        if (!is_file($file)) {
            return new self();
        }

        $map = require $file;
        if (!\is_array($map)) {
            throw new \RuntimeException(sprintf('SRI map "%s" must return an array.', $file));
        }

        /* @var array<string, string> $map */
        return new self($map);
    }

    public function for(string $path): ?string
    {
        return $this->map['/'.ltrim($path, '/')] ?? null;
    }

    public function isEmpty(): bool
    {
        return [] === $this->map;
    }

    /** @return array<string, string> */
    public function all(): array
    {
        return $this->map;
    }
}
