<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Debug;

use Modufolio\Appkit\Core\ResetInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The profiling seam: one named interface the kernel calls at the end of
 * every request, the way it calls authenticators and user checkers at theirs.
 *
 * The kernel supplies the raw material and never looks at it — the
 * {@see \Modufolio\Appkit\Core\Kernel::stopwatch()} events recorded around
 * session restore, authentication, routing, access control and the
 * controller; the {@see \Modufolio\Appkit\Doctrine\Middleware\Debug\DebugStack}
 * queries with their origin in dev; whatever a decorated service recorded.
 * An implementation turns that into a profile, stores it, and hands the
 * response back — with a token header, typically — so a client can fetch it.
 *
 * Wire one by declaring this interface in config/services.php or a module's
 * services(); the default is {@see NullProfiler}, which costs a method call.
 * Symfony's profiler answers the same question with an event listener; here
 * the call is explicit because the request flow is.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
interface ProfilerInterface extends ResetInterface
{
    /**
     * Called by {@see \Modufolio\Appkit\Core\PrepareResponse} once the
     * response is final — the last thing every handle() does. Returns the
     * response to send, so a profiler can add a header naming the profile.
     */
    public function collect(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface;
}
