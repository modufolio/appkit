<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Inertia;

use Modufolio\Appkit\Inertia\Flash\FlashStoreInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The renderer a controller holds when the host never wired Inertia. Every
 * call fails with the same message {@see \Modufolio\Appkit\Core\Kernel::inertia()}
 * gives, naming what to add to config/modules.php — rather than the fatal
 * "typed property must not be accessed before initialization" an unassigned
 * `$this->inertia` used to raise.
 *
 * @internal
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
final class UnwiredRenderer implements InertiaRendererInterface
{
    public function respond(Inertia $page, ServerRequestInterface $request): ResponseInterface
    {
        throw self::unwired();
    }

    public function toPage(Inertia $page, ServerRequestInterface $request): Page
    {
        throw self::unwired();
    }

    public function render(string $component, array $props, ServerRequestInterface $request): ResponseInterface
    {
        throw self::unwired();
    }

    public function flash(string|array $key, mixed $value = null): static
    {
        throw self::unwired();
    }

    public function location(string $url): ResponseInterface
    {
        throw self::unwired();
    }

    public function version(): string
    {
        throw self::unwired();
    }

    public function flashStore(): FlashStoreInterface
    {
        throw self::unwired();
    }

    public static function unwired(): \LogicException
    {
        return new \LogicException(sprintf(
            'Inertia is not wired: list %s in config/modules.php, or declare %s in config/services.php.',
            InertiaModule::class,
            InertiaRendererInterface::class,
        ));
    }
}
