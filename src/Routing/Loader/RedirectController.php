<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Routing\Loader;

use Modufolio\Psr7\Http\Response;
use Modufolio\Psr7\Http\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class RedirectController
{
    /**
     * Serves both redirect forms: a literal `target`, or a `routeName` +
     * `routeParams` generated here at request time — so route renames
     * propagate, and an unknown name throws a loud RouteNotFoundException
     * instead of silently redirecting into a 404.
     *
     * @param array<string, mixed> $routeParams
     */
    public function redirect(
        ServerRequestInterface $request,
        UrlGeneratorInterface $urlGenerator,
        int $statusCode = 301,
        ?string $target = null,
        ?string $routeName = null,
        array $routeParams = [],
    ): ResponseInterface {
        if (null === $target) {
            if (null === $routeName) {
                throw new \LogicException('A redirect route must carry either a target or a routeName default.');
            }

            $target = $urlGenerator->generate($routeName, $routeParams);
        }

        $escapedUrl = \htmlspecialchars($target, \ENT_QUOTES, 'UTF-8');
        $body = \sprintf(
            '<!DOCTYPE html>
            <html>
                <head>
                    <meta charset="UTF-8" />
                    <meta http-equiv="refresh" content="0;url=\'%1$s\'" />
                    <title>Redirecting to %1$s</title>
                </head>
                <body>
                    Redirecting to <a href="%1$s">%1$s</a>.
                </body>
            </html>',
            $escapedUrl
        );

        return new Response(
            $statusCode,
            ['Location' => $target],
            Stream::create($body)
        );
    }
}
