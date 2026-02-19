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
        if ($request->getMethod() === 'POST') {
            return Response::redirect('/');
        }
        
        // GET request - show login page
        return new Response(200, [], 'Login page');
    }

    public function logout(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(200, [], 'Logout');
    }

    public function public(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(200, [], 'Public page');
    }
}
