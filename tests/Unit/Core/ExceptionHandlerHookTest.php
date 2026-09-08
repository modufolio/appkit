<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Core;

use Modufolio\Appkit\Tests\Case\AppTestCase;
use Modufolio\Psr7\Http\ServerRequest;
use Modufolio\Psr7\Http\Uri;

/**
 * Kernel::configureExceptionHandler() — the app's one place to add its own
 * exception mappings and formatters (see the fixture App's override).
 */
class ExceptionHandlerHookTest extends AppTestCase
{
    public function testTheHookRunsBeforeTheHandlerIsFirstUsed(): void
    {
        $request = (new ServerRequest(method: 'GET', uri: new Uri('/')))
            ->withHeader('Accept', 'application/x-test-error');

        $response = $this->app()->exceptionHandler()->handle(new \RuntimeException('boom'), $request);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('application/x-test-error', $response->getHeaderLine('Content-Type'));
        $this->assertSame('hooked: Runtime error', (string) $response->getBody());
    }

    public function testTheHandlerIsBuiltOnce(): void
    {
        $this->assertSame($this->app()->exceptionHandler(), $this->app()->exceptionHandler());
    }
}
