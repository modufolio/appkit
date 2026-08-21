<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\App\Controller;

use Modufolio\Psr7\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class TestController
{
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(200, [], 'Home page');
    }

    public function login(ServerRequestInterface $request): ResponseInterface
    {
        // If this is a POST request, authentication has already been handled by the framework
        // If we reach here on POST, authentication succeeded - redirect to home
        if ('POST' === $request->getMethod()) {
            return Response::redirect('/');
        }

        // GET request - show login page
        return new Response(200, [], 'Login page');
    }

    public function logout(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(200, [], 'Logout');
    }

    public function submit(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(200, [], 'Submitted');
    }

    public function public(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(200, [], 'Public page');
    }

    /**
     * Route parameters ahead of optional ones, with the request first.
     *
     * The parameter resolvers key their results by name and fill them in
     * resolver order — here the route params (`slug`, `page`) resolve before
     * the request does. Spreading positionally would hand `$request` the
     * string, so this signature is the regression guard for that.
     */
    public function ordered(
        ServerRequestInterface $request,
        string $slug,
        string $page,
        ?string $optional = null,
    ): ResponseInterface {
        return new Response(200, [], implode('|', [
            get_debug_type($request),
            $slug,
            $page,
            $optional ?? 'null',
        ]));
    }

    public function me(ServerRequestInterface $request): ResponseInterface
    {
        return Response::json(['status' => 'authenticated']);
    }
}
