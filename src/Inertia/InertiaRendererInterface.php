<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Inertia;

use Modufolio\Appkit\Inertia\Flash\FlashStoreInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * What finishes an {@see Inertia} page: the seam between the kernel and the
 * host's Inertia wiring. {@see InertiaRenderer} is the implementation this
 * package ships; declare this id in config/services.php to put your own in
 * front of it — a decorator that logs, that picks a version per tenant, that
 * shares props from somewhere the module knows nothing about.
 *
 * The kernel only ever asks through {@see \Modufolio\Appkit\Core\Kernel::inertia()},
 * and a controller through `$this->inertia`, so a decorator sees every page.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
interface InertiaRendererInterface
{
    /**
     * The response for a page: the host's document for a browser visit, the
     * page object as JSON for the client's XHR, or the 409 of a failed
     * version handshake.
     */
    public function respond(Inertia $page, ServerRequestInterface $request): ResponseInterface;

    /** The page object this request receives, resolved against its headers. */
    public function toPage(Inertia $page, ServerRequestInterface $request): Page;

    /**
     * {@see respond()} for a component and props, in one call.
     *
     * @param array<string, mixed> $props
     */
    public function render(string $component, array $props, ServerRequestInterface $request): ResponseInterface;

    /**
     * Flash data for the next page — before a redirect, typically.
     *
     * @param string|array<string, mixed> $key
     */
    public function flash(string|array $key, mixed $value = null): static;

    /** An Inertia "location" response: the client visits the URL in full. */
    public function location(string $url): ResponseInterface;

    /** The asset version this response carries. */
    public function version(): string;

    /** Where flash data waits between a redirect and the page that follows it. */
    public function flashStore(): FlashStoreInterface;
}
