<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Core;

use Modufolio\Appkit\Routing\Router;
use Modufolio\Appkit\Routing\RouterInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Routing facade: the router service, its options, and URL generation.
 *
 * Behavior only: every property this trait touches is declared on {@see Kernel},
 * which composes it. Method names, visibility and signatures are unchanged from
 * their previous home on the kernel.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
trait AppRouting
{
    public function router(): RouterInterface
    {
        return $this->router ??= new Router(
            $this->routeLoader,
            $this->routeResource,
            $this->routerOptions
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    public function setRouterOptions(array $options): void
    {
        $defaultOptions = [
            'cache_dir' => null,
            'debug' => false,
            'resource_type' => null,
            'strict_requirements' => true,
        ];

        $invalid = [];
        foreach ($options as $key => $value) {
            if (\array_key_exists($key, $defaultOptions)) {
                $this->routerOptions[$key] = $value;
            } else {
                $invalid[] = $key;
            }
        }

        if ($invalid) {
            throw new \InvalidArgumentException(\sprintf('The Router does not support the following options: "%s".', implode('", "', $invalid)));
        }
    }

    /**
     * Generate URL from route name and parameters.
     *
     * @param array<string, mixed> $parameters
     */
    public function generateUrl(string $name, array $parameters = [], int $referenceType = UrlGeneratorInterface::ABSOLUTE_PATH): string
    {
        return $this->router()->generateUrl($name, $parameters, $referenceType);
    }

    public function urlGenerator(): UrlGeneratorInterface
    {
        return $this->router()->getUrlGenerator();
    }

    public function url(string $path = ''): string
    {
        $baseUrl = rtrim($this->baseUrl(), '/');
        $path = ltrim($path, '/');

        return '' === $path ? $baseUrl : $baseUrl.'/'.$path;
    }

    public function baseUrl(): string
    {
        return $this->state?->getBaseUrl() ?? '';
    }
}
