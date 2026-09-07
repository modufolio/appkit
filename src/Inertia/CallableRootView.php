<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Inertia;

use Modufolio\Psr7\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A root view from a closure: `fn (Page $page, ServerRequestInterface $request)`
 * returning the HTML as a string or a finished response. The smallest host
 * integration, and the one a test reaches for.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
final class CallableRootView implements RootViewInterface
{
    /** @param \Closure(Page, ServerRequestInterface): (string|ResponseInterface) $render */
    public function __construct(private readonly \Closure $render)
    {
    }

    public function render(Page $page, ServerRequestInterface $request): ResponseInterface
    {
        $rendered = ($this->render)($page, $request);

        return $rendered instanceof ResponseInterface ? $rendered : Response::html($rendered);
    }
}
