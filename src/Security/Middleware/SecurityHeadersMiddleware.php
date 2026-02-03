<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Security Headers Middleware
 *
 * Adds security headers to all responses to protect against common attacks:
 * - X-Frame-Options: Prevents clickjacking
 * - X-Content-Type-Options: Prevents MIME sniffing
 * - X-XSS-Protection: Legacy XSS protection
 * - Referrer-Policy: Controls referrer information
 * - Strict-Transport-Security (HSTS): Enforces HTTPS
 * - Content-Security-Policy: Prevents XSS and injection attacks
 *
 * Based on OWASP recommendations and industry best practices.
 */
class SecurityHeadersMiddleware implements MiddlewareInterface
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        // Add security headers
        $headers = $this->buildSecurityHeaders($request);

        foreach ($headers as $name => $value) {
            if ($value !== null) {
                $response = $response->withHeader($name, $value);
            }
        }

        return $response;
    }

    /**
     * Build security headers based on configuration
     *
     * @return array<string, string|null>
     */
    private function buildSecurityHeaders(ServerRequestInterface $request): array
    {
        $headers = [];

        // X-Frame-Options: Prevents clickjacking attacks
        if ($this->config['x_frame_options']['enabled']) {
            $headers['X-Frame-Options'] = strtoupper($this->config['x_frame_options']['value']);
        }

        // X-Content-Type-Options: Prevents MIME sniffing
        if ($this->config['x_content_type_options']['enabled']) {
            $headers['X-Content-Type-Options'] = 'nosniff';
        }

        // X-XSS-Protection: Legacy XSS protection for older browsers
        if ($this->config['x_xss_protection']['enabled']) {
            $mode = $this->config['x_xss_protection']['mode_block'] ? '; mode=block' : '';
            $headers['X-XSS-Protection'] = '1' . $mode;
        }

        // Referrer-Policy: Controls referrer information leakage
        if ($this->config['referrer_policy']['enabled']) {
            $headers['Referrer-Policy'] = $this->config['referrer_policy']['policy'];
        }

        // Strict-Transport-Security (HSTS): Enforces HTTPS
        if ($this->config['hsts']['enabled'] && $this->isHttps($request)) {
            $hsts = sprintf(
                'max-age=%d%s%s',
                $this->config['hsts']['max_age'],
                $this->config['hsts']['include_sub_domains'] ? '; includeSubDomains' : '',
                $this->config['hsts']['preload'] ? '; preload' : ''
            );
            $headers['Strict-Transport-Security'] = $hsts;
        }

        // Content-Security-Policy: Prevents XSS and injection attacks
        if ($this->config['csp']['enabled']) {
            $csp = $this->buildCspHeader();
            $headerName = $this->config['csp']['report_only']
                ? 'Content-Security-Policy-Report-Only'
                : 'Content-Security-Policy';
            $headers[$headerName] = $csp;
        }

        // Permissions-Policy (formerly Feature-Policy): Controls browser features
        if ($this->config['permissions_policy']['enabled']) {
            $headers['Permissions-Policy'] = $this->buildPermissionsPolicyHeader();
        }

        return $headers;
    }

    /**
     * Build Content-Security-Policy header value
     */
    private function buildCspHeader(): string
    {
        $directives = [];

        foreach ($this->config['csp']['directives'] as $directive => $sources) {
            if (empty($sources)) {
                continue;
            }

            $formattedSources = array_map(function ($source) {
                // Add quotes for special keywords
                if (in_array($source, ['self', 'none', 'unsafe-inline', 'unsafe-eval', 'strict-dynamic', 'unsafe-hashes'])) {
                    return "'{$source}'";
                }
                return $source;
            }, $sources);

            $directives[] = $directive . ' ' . implode(' ', $formattedSources);
        }

        $csp = implode('; ', $directives);

        // Add report-uri if configured
        if (!empty($this->config['csp']['report_uri'])) {
            $csp .= '; report-uri ' . $this->config['csp']['report_uri'];
        }

        return $csp;
    }

    /**
     * Build Permissions-Policy header value
     */
    private function buildPermissionsPolicyHeader(): string
    {
        $policies = [];

        foreach ($this->config['permissions_policy']['directives'] as $feature => $allowlist) {
            if ($allowlist === []) {
                $policies[] = "{$feature}=()";
            } else {
                $formatted = array_map(function ($origin) {
                    return $origin === 'self' ? 'self' : "\"{$origin}\"";
                }, $allowlist);
                $policies[] = "{$feature}=(" . implode(' ', $formatted) . ')';
            }
        }

        return implode(', ', $policies);
    }

    /**
     * Check if request is HTTPS
     */
    private function isHttps(ServerRequestInterface $request): bool
    {
        $uri = $request->getUri();
        return $uri->getScheme() === 'https';
    }

    /**
     * Get default security headers configuration
     *
     * @return array<string, mixed>
     */
    private function getDefaultConfig(): array
    {
        return [
            // X-Frame-Options: Prevents clickjacking
            'x_frame_options' => [
                'enabled' => true,
                'value' => 'DENY', // Options: DENY, SAMEORIGIN
            ],

            // X-Content-Type-Options: Prevents MIME sniffing
            'x_content_type_options' => [
                'enabled' => true,
            ],

            // X-XSS-Protection: Legacy XSS protection
            'x_xss_protection' => [
                'enabled' => true,
                'mode_block' => true,
            ],

            // Referrer-Policy: Controls referrer information
            'referrer_policy' => [
                'enabled' => true,
                'policy' => 'no-referrer-when-downgrade',
                // Options: no-referrer, no-referrer-when-downgrade, origin,
                // origin-when-cross-origin, same-origin, strict-origin,
                // strict-origin-when-cross-origin, unsafe-url
            ],

            // HSTS: Enforce HTTPS
            'hsts' => [
                'enabled' => true,
                'max_age' => 31536000, // 1 year in seconds
                'include_sub_domains' => true,
                'preload' => false, // Set true only after testing
            ],

            // Content Security Policy
            'csp' => [
                'enabled' => true,
                'report_only' => false, // Set true to test without enforcement
                'report_uri' => null, // URL to send CSP violation reports
                'directives' => [
                    'default-src' => ['self'],
                    'script-src' => ['self', 'unsafe-inline'], // unsafe-inline needed for some frameworks
                    'style-src' => ['self', 'unsafe-inline'], // unsafe-inline needed for inline styles
                    'img-src' => ['self', 'data:', 'https:'],
                    'font-src' => ['self', 'data:'],
                    'connect-src' => ['self'],
                    'frame-ancestors' => ['none'], // Prevents framing (like X-Frame-Options)
                    'base-uri' => ['self'],
                    'form-action' => ['self'],
                ],
            ],

            // Permissions Policy (Feature Policy)
            'permissions_policy' => [
                'enabled' => true,
                'directives' => [
                    'geolocation' => [], // Empty array = deny all
                    'microphone' => [],
                    'camera' => [],
                    'payment' => [],
                    'usb' => [],
                    'magnetometer' => [],
                    'gyroscope' => [],
                    'accelerometer' => [],
                ],
            ],
        ];
    }
}
