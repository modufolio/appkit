<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Event;

use Modufolio\Appkit\Core\PrepareResponse;
use Modufolio\Appkit\Event\ExceptionCaughtEvent;
use Modufolio\Appkit\Event\Http\RequestHandledEvent;
use Modufolio\Appkit\Event\NullEventDispatcher;
use Modufolio\Appkit\Exception\ExceptionHandler;
use Modufolio\Appkit\Tests\App\RecordingEventDispatcher;
use Modufolio\Psr7\Http\Response;
use Modufolio\Psr7\Http\ServerRequest;
use Modufolio\Psr7\Http\Uri;
use PHPUnit\Framework\TestCase;

/**
 * The pieces of the seam that need no application: the null dispatcher, and
 * the two kernel collaborators that dispatch on their own.
 */
class EventSeamTest extends TestCase
{
    public function testTheNullDispatcherHandsTheEventBack(): void
    {
        $event = new \stdClass();

        $this->assertSame($event, (new NullEventDispatcher())->dispatch($event));
    }

    public function testPrepareResponseAnnouncesTheFinalResponseWithADuration(): void
    {
        $events = new RecordingEventDispatcher();
        $request = new ServerRequest('GET', new Uri('/'), serverParams: ['REQUEST_TIME_FLOAT' => microtime(true) - 0.05]);
        $response = new Response(200, [], 'ok');

        $prepared = (new PrepareResponse(null, $events))->prepare($request, $response);

        $handled = $events->of(RequestHandledEvent::class);
        $this->assertCount(1, $handled);
        $this->assertSame($prepared, $handled[0]->response, 'The announced response is the prepared one');
        $this->assertNotNull($handled[0]->durationMs);
        $this->assertGreaterThanOrEqual(50.0, $handled[0]->durationMs);
    }

    public function testPrepareResponseSurvivesAFailingListener(): void
    {
        $events = new RecordingEventDispatcher();
        $events->throwOnDispatch = new \RuntimeException('boom');

        $response = (new PrepareResponse(null, $events))->prepare(
            new ServerRequest('GET', new Uri('/')),
            new Response(204),
        );

        $this->assertSame(204, $response->getStatusCode());
    }

    public function testTheExceptionHandlerAnnouncesEveryThrowableBeforeRendering(): void
    {
        $events = new RecordingEventDispatcher();
        $handler = new ExceptionHandler(events: $events);
        $request = (new ServerRequest('GET', new Uri('/')))->withHeader('Accept', 'application/json');

        $response = $handler->handle(new \RuntimeException('boom'), $request);

        $this->assertSame(500, $response->getStatusCode());
        $caught = $events->of(ExceptionCaughtEvent::class);
        $this->assertCount(1, $caught);
        $this->assertSame('boom', $caught[0]->exception->getMessage());
    }

    public function testTheExceptionHandlerStillRendersWhenTheListenerFails(): void
    {
        $events = new RecordingEventDispatcher();
        $events->throwOnDispatch = new \RuntimeException('reporter down');
        $handler = new ExceptionHandler(events: $events);
        $request = (new ServerRequest('GET', new Uri('/')))->withHeader('Accept', 'application/json');

        $response = $handler->handle(new \RuntimeException('boom'), $request);

        $this->assertSame(500, $response->getStatusCode());
    }
}
