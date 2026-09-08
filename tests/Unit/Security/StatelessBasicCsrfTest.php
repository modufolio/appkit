<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Security;

use Modufolio\Appkit\Tests\Case\AppTestCase;

/**
 * HTTP Basic is an ambient credential — a browser re-sends a cached realm on
 * its own — so the kernel enforces CSRF on a state-changing request it
 * authenticates. That rule only makes sense where a session exists to hold the
 * token: a stateless firewall never starts one, so there is nothing to compare
 * a submitted token against and the check could only ever fail. Stateless
 * therefore implies no CSRF check, the same way it does on the restored-session
 * and public-path branches; a session-backed Basic firewall keeps it.
 */
class StatelessBasicCsrfTest extends AppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->refreshDatabase();
        $this->loadFixtures();
    }

    private function configureBasicFirewall(bool $stateless): void
    {
        $this->app()->configureFirewall([
            'firewalls' => [
                'api' => [
                    'pattern' => '/',
                    'authenticators' => ['basic_auth'],
                    'stateless' => $stateless,
                ],
            ],
            'access_control' => [],
        ]);
    }

    /** @return array<string, string> */
    private function basicHeaders(string $identifier = 'johndoe@example.com', string $password = 'secret'): array
    {
        return [
            'Authorization' => 'Basic '.base64_encode($identifier.':'.$password),
            'Content-Type' => 'application/x-www-form-urlencoded',
        ];
    }

    public function testStatelessBasicFirewallAcceptsAWriteWithoutACsrfToken(): void
    {
        $this->configureBasicFirewall(stateless: true);

        $this->withoutCsrfToken()
            ->post('/submit', ['name' => 'Ada'], $this->basicHeaders())
            ->assertStatus(200);
    }

    public function testStatelessBasicFirewallStillRequiresValidCredentials(): void
    {
        $this->configureBasicFirewall(stateless: true);

        // Skipping CSRF must not loosen authentication itself.
        $this->withoutCsrfToken()
            ->post('/submit', ['name' => 'Ada'], $this->basicHeaders(password: 'wrong'))
            ->assertStatus(401);
    }

    public function testSessionBackedBasicFirewallStillEnforcesCsrf(): void
    {
        $this->configureBasicFirewall(stateless: false);

        $response = $this->withoutCsrfToken()
            ->post('/submit', ['name' => 'Ada'], $this->basicHeaders());

        $response->assertStatus(403);
        $this->assertSame('invalid_csrf_token', $response->jsonData()['error'] ?? null);
    }

    public function testSessionBackedBasicFirewallLeavesSafeMethodsAlone(): void
    {
        $this->configureBasicFirewall(stateless: false);

        $this->get('/', headers: $this->basicHeaders())->assertStatus(200);
    }
}
