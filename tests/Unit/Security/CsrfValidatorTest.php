<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Security;

use Modufolio\Appkit\Security\Csrf\CsrfTokenManagerInterface;
use Modufolio\Appkit\Tests\Case\AppTestCase;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The per-firewall `csrf_validator` option lets a form layer answer the CSRF
 * question for request shapes the built-in extraction cannot describe (a
 * namespaced `contact[_token]` field, a per-form token id). true accepts,
 * false rejects, null falls through to the built-in check.
 *
 * Also covers the CSRF failure response: browsers that prefer text/html get
 * the AccessDeniedException path through the exception handler, while
 * fetch/XHR and API clients keep the exact JSON body.
 */
class CsrfValidatorTest extends AppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->refreshDatabase();
        $this->loadFixtures();
    }

    private function configureFirewallWithValidator(): void
    {
        $this->app()->configureFirewall([
            'firewalls' => [
                'main' => [
                    'pattern' => '/',
                    'authenticators' => ['form_login'],
                    'entry_point' => '/login',
                    'logout' => ['path' => '/logout', 'target' => '/login'],
                    'csrf_validator' => function (ServerRequestInterface $request, CsrfTokenManagerInterface $tokens): ?bool {
                        $body = $request->getParsedBody();
                        $token = is_array($body) ? ($body['contact']['_token'] ?? null) : null;

                        return null === $token ? null : $tokens->validateToken('contact_form', $token);
                    },
                ],
            ],
            'access_control' => [],
        ]);
    }

    public function testValidatorAcceptsANamespacedFormToken(): void
    {
        $this->configureFirewallWithValidator();
        $this->login();

        $token = $this->app()->csrfTokenManager()->getToken('contact_form')->getValue();

        $this->withoutCsrfToken()->post('/submit', [
            'contact' => ['name' => 'Ada', '_token' => $token],
        ], ['Content-Type' => 'application/x-www-form-urlencoded'])->assertStatus(200);
    }

    public function testValidatorRejectsAForgedNamespacedToken(): void
    {
        $this->configureFirewallWithValidator();
        $this->login();

        $response = $this->withoutCsrfToken()->post('/submit', [
            'contact' => ['name' => 'Ada', '_token' => 'forged'],
        ], ['Content-Type' => 'application/x-www-form-urlencoded']);

        $response->assertStatus(403);
        $this->assertSame('invalid_csrf_token', $response->jsonData()['error'] ?? null);
    }

    public function testANullVerdictFallsThroughToTheBuiltInCheck(): void
    {
        $this->configureFirewallWithValidator();
        $this->login();

        // No contact[_token] in the body → the validator answers null, and the
        // firewall's own token (sent as X-CSRF-Token) is what decides.
        $token = $this->app()->csrfTokenManager()->getToken('csrf')->getValue();

        $this->post('/submit', ['name' => 'Ada'], [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'X-CSRF-Token' => $token,
        ])->assertStatus(200);

        $this->withoutCsrfToken()->post('/submit', ['name' => 'Ada'], [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ])->assertStatus(403);
    }

    public function testCsrfFailureIsNegotiatedForABrowserAndStaysJsonForApiClients(): void
    {
        $this->configureFirewallWithValidator();
        $this->login();

        // A browser form submission: the AccessDeniedException path renders
        // through the app's exception handler instead of the raw JSON body.
        $html = $this->withoutCsrfToken()->post('/submit', [
            'contact' => ['_token' => 'forged'],
        ], [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        ]);
        $html->assertStatus(403);
        $this->assertStringNotContainsString('invalid_csrf_token', $html->getContent());

        // fetch/XHR and API clients keep the machine-readable body exactly.
        $json = $this->withoutCsrfToken()->post('/submit', [
            'contact' => ['_token' => 'forged'],
        ], [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json',
        ]);
        $json->assertStatus(403);
        $this->assertSame('invalid_csrf_token', $json->jsonData()['error'] ?? null);

        // An XHR that accepts text/html but flags X-Requested-With wants data.
        $xhr = $this->withoutCsrfToken()->post('/submit', [
            'contact' => ['_token' => 'forged'],
        ], [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'text/html',
            'X-Requested-With' => 'XMLHttpRequest',
        ]);
        $xhr->assertStatus(403);
        $this->assertSame('invalid_csrf_token', $xhr->jsonData()['error'] ?? null);
    }

    public function testCsrfFailureHonoursQualityValuesNotHeaderOrder(): void
    {
        $this->configureFirewallWithValidator();
        $this->login();

        // text/html is listed first but weighted below JSON, so this client
        // wants JSON. Ordering alone would misread it as a browser.
        $json = $this->withoutCsrfToken()->post('/submit', [
            'contact' => ['_token' => 'forged'],
        ], [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'text/html;q=0.1, application/json;q=0.9',
        ]);
        $json->assertStatus(403);
        $this->assertSame('invalid_csrf_token', $json->jsonData()['error'] ?? null);

        // And the mirror image: JSON first, but HTML carries the higher weight.
        $html = $this->withoutCsrfToken()->post('/submit', [
            'contact' => ['_token' => 'forged'],
        ], [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json;q=0.2, text/html;q=0.8',
        ]);
        $html->assertStatus(403);
        $this->assertStringNotContainsString('invalid_csrf_token', $html->getContent());
    }

    public function testCsrfFailureDefaultsToJsonWithoutAPreference(): void
    {
        $this->configureFirewallWithValidator();
        $this->login();

        foreach (['*/*', ''] as $accept) {
            $response = $this->withoutCsrfToken()->post('/submit', [
                'contact' => ['_token' => 'forged'],
            ], [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Accept' => $accept,
            ]);
            $response->assertStatus(403);
            $this->assertSame(
                'invalid_csrf_token',
                $response->jsonData()['error'] ?? null,
                sprintf('Accept: "%s" should get the machine-readable body', $accept),
            );
        }
    }
}
