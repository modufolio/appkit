<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Inertia;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The HTML document a first, non-XHR visit receives: the host's shell around
 * the page object. Whatever renders it — a template engine, a string — it
 * embeds the page where the client boots from, which {@see Inertia::snippet()}
 * produces in the shape `@inertiajs/*` expects.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
interface RootViewInterface
{
    public function render(Page $page, ServerRequestInterface $request): ResponseInterface;
}
