<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Debug;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The profiler an application has until it declares one: hands the response
 * back untouched. Kept as a real object rather than a null check at the
 * call site so the seam reads the same whether or not anything listens.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
final class NullProfiler implements ProfilerInterface
{
    public function collect(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $response;
    }

    public function reset(): void
    {
    }
}
