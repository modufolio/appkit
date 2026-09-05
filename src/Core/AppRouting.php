<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Core;

use Modufolio\Appkit\Http\TrustedHosts;
use Modufolio\Appkit\Routing\Router;
use Modufolio\Appkit\Routing\RouterInterface;
use Psr\Http\Message\ServerRequestInterface;
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
    #[\Modufolio\Appkit\Attributes\Service]
    public function router(): RouterInterface
    {
        return $this->router ??= new Router(
            $this->routeLoader,
            $this->routeResource,
            $this->routerOptions
        );
    }

    /**
     * Router options. Recognised keys:
     *
     *   - cache_dir, debug, resource_type, strict_requirements — see Router.
     *   - trusted_hosts: allowlist of hostnames the application answers for.
     *     Same entries as Symfony's framework.trusted_hosts (regexes), plus
     *     "example.com" / "*.example.com" shorthands. When non-empty, a request
     *     whose Host header is not listed is rejected with 400 before request
     *     state is built, so the host can never reach absolute URL generation.
     *     See {@see TrustedHosts} and docs/security.md#trusted-hosts.
     *
     * Keys are merged into the current options; unknown keys throw. A router
     * built from the previous options is discarded so the change takes effect.
     *
     * @param array<string, mixed> $options
     */
    public function setRouterOptions(array $options): void
    {
        $defaultOptions = [
            'cache_dir' => null,
            'debug' => false,
            'resource_type' => null,
            'strict_requirements' => true,
            'trusted_hosts' => [],
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

        if (\array_key_exists('trusted_hosts', $options)) {
            // Validate eagerly so a malformed entry fails at configuration
            // time, not on the first request.
            $this->trustedHosts = new TrustedHosts($options['trusted_hosts']);
        }

        $this->router = null;
    }

    /**
     * The trusted-hosts allowlist from the "trusted_hosts" router option.
     * Empty (accept any host) unless configured.
     */
    public function trustedHosts(): TrustedHosts
    {
        return $this->trustedHosts ??= new TrustedHosts($this->routerOptions['trusted_hosts'] ?? []);
    }

    /**
     * Reject a request whose Host header is not on the trusted-hosts allowlist.
     *
     * Called by createState() and again at the top of handleAuthentication(),
     * so an application that builds its request state by hand is still covered
     * before any kernel code can copy the host into a URL. A no-op when no
     * trusted hosts are configured.
     *
     * @throws \Modufolio\Appkit\Exception\UntrustedHostException
     */
    public function assertTrustedHost(ServerRequestInterface $request): void
    {
        $this->trustedHosts()->assert($request->getUri()->getHost());
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

    #[\Modufolio\Appkit\Attributes\Service]
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
