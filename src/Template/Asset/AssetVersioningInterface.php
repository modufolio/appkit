<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Template\Asset;

/**
 * Turns the path a template asks for into the path the browser should fetch.
 *
 * `$this->css('/assets/css/app.css')` names the file as it is written; what
 * is served may carry a content hash so a far-future cache header is safe:
 * `/assets/css/app.3f2a….css`, or `/assets/css/app.css?v=3f2a…`, or the name
 * a bundler wrote to its manifest. Which of those, or none, is the
 * application's choice, declared once in config/services.php and applied by
 * every template through {@see \Modufolio\Appkit\Template\Template::asset()}.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
interface AssetVersioningInterface
{
    /**
     * @param string $path a root-relative path, `/assets/css/app.css`
     *
     * @return string the path to fetch; the input untouched when nothing is known about it
     */
    public function version(string $path): string;
}
