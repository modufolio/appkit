<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\App\Controller;

use Modufolio\Appkit\Attributes\RateLimit;
use Modufolio\Psr7\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Routes for the rate-limiting tests: a controller-wide limiter, and one
 * action with a stricter limiter on top of it.
 */
#[RateLimit('api')]
class ThrottledController
{
    #[Route(path: '/throttled', name: 'throttled', methods: ['GET'])]
    public function index(): ResponseInterface
    {
        return new Response(200, [], 'ok');
    }

    #[Route(path: '/throttled/expensive', name: 'throttled_expensive', methods: ['GET'])]
    #[RateLimit('expensive', by: RateLimit::BY_IP)]
    public function expensive(): ResponseInterface
    {
        return new Response(200, [], 'expensive');
    }
}
