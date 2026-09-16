<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Template\Asset;

/**
 * The default: every asset is fetched by the path it was queued under.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
final class NoVersioning implements AssetVersioningInterface
{
    public function version(string $path): string
    {
        return $path;
    }
}
