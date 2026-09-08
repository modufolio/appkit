<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Core;

use Modufolio\Appkit\Core\PrepareResponse;
use Modufolio\Appkit\Inertia\Header;
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

    public function testARedirectToAFragmentBecomesA409TheClientVisitsItself(): void
    {
        $prepared = (new PrepareResponse())->prepare(
            new ServerRequest('POST', '/panel/users', ['X-Inertia' => 'true']),
            Response::redirect('/panel/users#row-7'),
        );

        // The client's isInertiaRedirect(): 409 + X-Inertia-Redirect.
        self::assertSame(409, $prepared->getStatusCode());
        self::assertSame('/panel/users#row-7', $prepared->getHeaderLine(Header::REDIRECT));
        self::assertFalse($prepared->hasHeader('Location'), 'An XHR would follow it and drop the fragment.');
    }

    public function testAPrefetchKeepsItsRedirect(): void
    {
        $prepared = (new PrepareResponse())->prepare(
            new ServerRequest('GET', '/panel/users', ['X-Inertia' => 'true', 'Purpose' => 'prefetch']),
            Response::redirect('/panel/users#row-7'),
        );

        self::assertSame(302, $prepared->getStatusCode());
    }

    public function testARedirectWithoutAFragmentIsUntouched(): void
    {
        $prepared = (new PrepareResponse())->prepare(
            new ServerRequest('POST', '/panel/users', ['X-Inertia' => 'true']),
            Response::redirect('/panel/users'),
        );

        self::assertSame(302, $prepared->getStatusCode());
        self::assertSame('/panel/users', $prepared->getHeaderLine('Location'));
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
