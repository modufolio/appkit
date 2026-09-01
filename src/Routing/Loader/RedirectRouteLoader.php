<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Routing\Loader;

use Modufolio\Appkit\Routing\RedirectConfigurator;
use Symfony\Component\Config\FileLocatorInterface;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

final class RedirectRouteLoader extends Loader
{
    public function __construct(
        private FileLocatorInterface $fileLocator,
        ?string $env = null,
    ) {
        parent::__construct($env);
    }

    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        $filePath = $this->fileLocator->locate($resource);
        $closure = require $filePath;

        if (!is_callable($closure)) {
            throw new \RuntimeException(sprintf('The redirect configuration file "%s" must return a closure.', $filePath));
        }

        $configurator = new RedirectConfigurator();
        $closure($configurator);

        $redirects = $configurator->getRedirects();
        $this->assertNoRedirectLoops($redirects, $filePath);

        $collection = new RouteCollection();

        foreach ($redirects as $redirect) {
            $source = '/'.ltrim($redirect['source'], '/');
            $statusCode = $redirect['statusCode'];

            if (null !== $redirect['routeName']) {
                // Resolved through the UrlGenerator when the redirect is
                // served — the collection is still being built here.
                $defaults = [
                    '_controller' => [RedirectController::class, 'redirect'],
                    'routeName' => $redirect['routeName'],
                    'routeParams' => $redirect['routeParams'],
                    'statusCode' => $statusCode,
                ];
                $identity = 'route:'.$redirect['routeName'].':'.json_encode($redirect['routeParams']);
            } else {
                $defaults = [
                    '_controller' => [RedirectController::class, 'redirect'],
                    'target' => $redirect['target'],
                    'statusCode' => $statusCode,
                ];
                $identity = (string) $redirect['target'];
            }

            $hash = substr(hash('sha256', $source.'|'.$identity), 0, 12);
            $collection->add('redirect_'.$hash, new Route(path: $source, defaults: $defaults));
        }

        return $collection;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return 'redirect' === $type;
    }

    /**
     * Refuse cycles between literal-path redirects, printing the full chain
     * (`/a -> /b -> /a`). Only explicit path targets participate: a
     * route-name target's path is not known until the collection is built.
     *
     * @param list<array{source: string, target: ?string, routeName: ?string, routeParams: array<string, mixed>, statusCode: int}> $redirects
     */
    private function assertNoRedirectLoops(array $redirects, string $filePath): void
    {
        $map = [];
        foreach ($redirects as $redirect) {
            if (null === $redirect['target'] || !str_starts_with($redirect['target'], '/')) {
                continue;
            }
            $map['/'.ltrim($redirect['source'], '/')] = '/'.ltrim($redirect['target'], '/');
        }

        $errors = [];
        foreach (array_keys($map) as $start) {
            $chain = [$start];
            $seen = [$start => true];
            $current = $start;

            while (isset($map[$current])) {
                $current = $map[$current];
                $chain[] = $current;

                if (isset($seen[$current])) {
                    // Report each cycle once, from its smallest member.
                    if ($start === min($chain)) {
                        $errors[] = implode(' -> ', $chain);
                    }
                    break;
                }

                $seen[$current] = true;
            }
        }

        if ([] !== $errors) {
            throw new \LogicException(sprintf("Redirect loop(s) in \"%s\":\n - %s", $filePath, implode("\n - ", $errors)));
        }
    }
}
