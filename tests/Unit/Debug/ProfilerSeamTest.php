<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Debug;

use Modufolio\Appkit\Core\Kernel;
use Modufolio\Appkit\Core\PrepareResponse;
use Modufolio\Appkit\Debug\NullProfiler;
use Modufolio\Appkit\Debug\ProfilerInterface;
use Modufolio\Appkit\Resolver\ParameterResolverInterface;
use Modufolio\Appkit\Security\SecurityConfigurator;
use Modufolio\Appkit\Security\User\UserProviderInterface;
use Modufolio\Appkit\Tests\App\AppFactory;
use Modufolio\Appkit\Tests\App\Debug\RecordingProfiler;
use Modufolio\Appkit\Tests\Case\AppTestCase;
use Modufolio\Psr7\Http\Response;
use Modufolio\Psr7\Http\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Stopwatch\Stopwatch;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The profiling seam: the kernel records its phases on the stopwatch, hands
 * every final response to the declared profiler, and resets both between
 * requests. The test app declares RecordingProfiler in config/services.php.
 */
class ProfilerSeamTest extends AppTestCase
{
    public function testTheKernelHandsOutTheInstalledProfiler(): void
    {
        $profiler = $this->app()->profiler();

        $this->assertInstanceOf(RecordingProfiler::class, $profiler, 'Installed by AppFactory with setProfiler().');
        $this->assertSame($profiler, $this->app()->profiler());
        $this->assertSame($profiler, $this->app()->get(ProfilerInterface::class), 'A core id, so it can be injected.');
        $this->assertSame($this->app()->stopwatch(), $this->app()->get(Stopwatch::class));
    }

    public function testEveryRequestReachesTheProfilerWithTheKernelsTimeline(): void
    {
        $profiler = $this->app()->profiler();
        $this->assertInstanceOf(RecordingProfiler::class, $profiler);
        $profiler->clear();

        $this->get('/public')->assertStatus(200);

        $this->assertCount(1, $profiler->collected());
        ['request' => $request, 'response' => $response, 'events' => $events] = $profiler->collected()[0];
        $this->assertSame('/public', $request->getUri()->getPath());
        $this->assertSame(200, $response->getStatusCode());

        // No firewall (the test case starts each test without one): the
        // phases the kernel owns, in the order the flow runs them.
        $this->assertSame(['security.access_control', 'routing', 'controller'], $this->kernelEvents($events));
    }

    public function testAFirewalledRequestAddsTheSecurityPhasesToTheTimeline(): void
    {
        $profiler = $this->app()->profiler();
        $this->assertInstanceOf(RecordingProfiler::class, $profiler);
        $profiler->clear();

        $security = new SecurityConfigurator();
        (require AppFactory::configDir($this->app()->baseDir).'/security.php')($security);
        $this->app()->configureSecurity($security);

        // Anonymous under the main firewall: bounced to the entry point, but
        // the session restore and the authenticators ran first — and so are
        // on the timeline; the controller never was.
        $this->get('/public')->assertStatus(302);

        $this->assertSame(['security.session', 'security.authenticate'], $this->kernelEvents($profiler->collected()[0]['events']));
    }

    /**
     * The kernel's own events, in recorded order, ignoring anything else.
     *
     * @param list<string> $events
     *
     * @return list<string>
     */
    private function kernelEvents(array $events): array
    {
        return array_values(array_intersect($events, ['security.session', 'security.authenticate', 'security.access_control', 'routing', 'controller']));
    }

    public function testTheProfilerCanStampTheResponseItCollected(): void
    {
        $profiler = $this->app()->profiler();
        $this->assertInstanceOf(RecordingProfiler::class, $profiler);
        $profiler->clear();

        $this->get('/public')->assertStatus(200);

        // What went out carries the header collect() added — prepare() returns
        // the profiler's response, not the one it was handed.
        $stopwatch = $this->app()->stopwatch();
        $prepared = (new PrepareResponse($profiler))->prepare(new ServerRequest('GET', '/x'), new Response());
        $this->assertSame((string) \count($profiler->collected()), $prepared->getHeaderLine('X-Profile-Id'));
        $this->assertSame($stopwatch, $this->app()->stopwatch());
    }

    public function testResetDropsTheTimelineAndResetsTheProfiler(): void
    {
        $profiler = $this->app()->profiler();
        $this->assertInstanceOf(RecordingProfiler::class, $profiler);

        $this->get('/public')->assertStatus(200);
        $this->assertNotEmpty($this->app()->stopwatch()->getSectionEvents(Stopwatch::ROOT));
        $resets = $profiler->resets();

        $this->app()->resetModules();

        $this->assertSame($resets + 1, $profiler->resets());
        $this->assertSame([], $this->app()->stopwatch()->getSectionEvents(Stopwatch::ROOT), 'The timeline starts empty for the next request.');
        $this->assertSame([], $profiler->collected());
    }

    public function testTheDefaultIsANullProfilerThatLeavesTheResponseAlone(): void
    {
        $kernel = new class extends Kernel {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new \LogicException('Not used.');
            }

            public function reset(): void
            {
            }

            public function serializer(): SerializerInterface
            {
                throw new \LogicException('Not used.');
            }

            public function parameterResolver(): ParameterResolverInterface
            {
                throw new \LogicException('Not used.');
            }

            public function validator(): ValidatorInterface
            {
                throw new \LogicException('Not used.');
            }

            public function userProvider(): UserProviderInterface
            {
                throw new \LogicException('Not used.');
            }
        };

        $this->assertInstanceOf(NullProfiler::class, $kernel->profiler());

        // prepare() always produces a new PSR-7 object (Content-Length), so
        // compare the outcome with and without the null profiler in the way.
        $request = new ServerRequest('GET', '/');
        $plain = (new PrepareResponse())->prepare($request, new Response(204));
        $viaNull = (new PrepareResponse($kernel->profiler()))->prepare($request, new Response(204));

        $this->assertSame(204, $viaNull->getStatusCode());
        $this->assertSame($plain->getHeaders(), $viaNull->getHeaders(), 'The null profiler adds nothing.');
    }
}
