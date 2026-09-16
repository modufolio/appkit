<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Event;

use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * The dispatcher that listens to nothing: hands every event straight back.
 *
 * What the kernel uses when the application has declared no dispatcher, so
 * a dispatch call reads the same whether or not anything listens — the same
 * reason the profiling seam has a NullProfiler rather than a null check at
 * every call site.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
final class NullEventDispatcher implements EventDispatcherInterface
{
    public function dispatch(object $event): object
    {
        return $event;
    }
}
