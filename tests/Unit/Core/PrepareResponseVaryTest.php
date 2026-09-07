<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Core;

use Modufolio\Appkit\Core\PrepareResponse;
use Modufolio\Psr7\Http\Response;
use Modufolio\Psr7\Http\ServerRequest;
use PHPUnit\Framework\TestCase;

final class PrepareResponseVaryTest extends TestCase
{
    public function testAnInertiaResponseVariesOnAcceptAndTheInertiaHeader(): void
    {
        $prepared = (new PrepareResponse())->prepare(
            new ServerRequest('GET', '/panel', ['X-Inertia' => 'true']),
            Response::json(['component' => 'X']),
        );

        self::assertSame('Accept, X-Inertia', $prepared->getHeaderLine('Vary'));
        self::assertSame('true', $prepared->getHeaderLine('X-Inertia'));
    }

    public function testAVarySetUpstreamIsKeptAndNotDuplicated(): void
    {
        $prepared = (new PrepareResponse())->prepare(
            new ServerRequest('GET', '/panel', ['X-Inertia' => 'true']),
            Response::json(['component' => 'X'])->withHeader('Vary', 'X-Inertia, Cookie'),
        );

        self::assertSame('X-Inertia, Cookie, Accept', $prepared->getHeaderLine('Vary'));
    }

    public function testARedirectAfterAWriteBecomesA303ForInertia(): void
    {
        $prepared = (new PrepareResponse())->prepare(
            new ServerRequest('PUT', '/panel/users/1', ['X-Inertia' => 'true']),
            Response::redirect('/panel/users'),
        );

        self::assertSame(303, $prepared->getStatusCode());
    }
}
