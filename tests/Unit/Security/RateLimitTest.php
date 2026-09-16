<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Security;

use Modufolio\Appkit\Event\Security\LoginFailedEvent;
use Modufolio\Appkit\Event\Security\RateLimitExceededEvent;
use Modufolio\Appkit\Security\SecurityConfigurator;
use Modufolio\Appkit\Testing\TestResponse;
use Modufolio\Appkit\Tests\App\Entity\User;
use Modufolio\Appkit\Tests\App\RecordingEventDispatcher;
use Modufolio\Appkit\Tests\Case\AppTestCase;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * symfony/rate-limiter behind two seams: a firewall's `rate_limit` counts
 * presented credentials per address; a route's `#[RateLimit]` counts
 * requests per user or address. Both answer 429 with Retry-After.
 */
class RateLimitTest extends AppTestCase
{
    private RecordingEventDispatcher $events;

    protected function setUp(): void
    {
        parent::setUp();

        $this->refreshDatabase();
        $this->loadFixtures();

        $this->events = RecordingEventDispatcher::instance();
        $this->events->clear();

        // A fresh window for every test.
        $this->app()->setRateLimiterStorage(new InMemoryStorage());

        $this->app()->configureFirewall([
            'firewalls' => [
                'main' => [
                    'pattern' => '/',
                    'authenticators' => ['form_login'],
                    'entry_point' => '/login',
                    'logout' => ['path' => '/logout', 'target' => '/'],
                    'rate_limit' => 'login',
                ],
            ],
            'access_control' => [
                ['path' => '/throttled', 'roles' => []],
            ],
            'rate_limiters' => [
                'login' => ['policy' => 'fixed_window', 'limit' => 2, 'interval' => '1 minute'],
                'api' => ['policy' => 'fixed_window', 'limit' => 2, 'interval' => '1 minute'],
                'expensive' => ['policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 minute'],
            ],
        ]);

        $em = $this->app()->entityManager();
        foreach (['one@example.com', 'two@example.com'] as $email) {
            $em->persist((new User())->setEmail($email)->setPassword(password_hash('secret', PASSWORD_BCRYPT))->setRoles(['ROLE_USER']));
        }
        $em->flush();
    }

    public function tearDown(): void
    {
        $this->events->clear();

        parent::tearDown();
    }

    private function attemptLogin(string $password): TestResponse
    {
        $csrf = $this->app()->csrfTokenManager()->getToken('authenticate')->getValue();

        return $this->form('/login', ['email' => 'one@example.com', 'password' => $password, '_csrf_token' => $csrf]);
    }

    // ── Firewall: login throttle ────────────────────────────────────────────

    public function testTheThirdCredentialInTheWindowIsA429WithRetryAfter(): void
    {
        $this->assertNotSame(429, $this->attemptLogin('wrong')->getResponse()->getStatusCode());
        $this->assertNotSame(429, $this->attemptLogin('wrong')->getResponse()->getStatusCode());

        $response = $this->attemptLogin('wrong')->getResponse();

        $this->assertSame(429, $response->getStatusCode());
        $this->assertGreaterThanOrEqual(1, (int) $response->getHeaderLine('Retry-After'));
        $this->assertLessThanOrEqual(60, (int) $response->getHeaderLine('Retry-After'));
        $this->assertSame('2', $response->getHeaderLine('RateLimit-Limit'));
    }

    public function testTheThrottleRefusesBeforeTheCredentialIsRead(): void
    {
        $this->attemptLogin('wrong');
        $this->attemptLogin('wrong');
        $this->events->clear();

        $this->attemptLogin('secret');

        $this->assertNull($this->app()->tokenStorage()->getToken(), 'A correct password inside a spent window is not tried');
        $this->assertSame([], $this->events->of(LoginFailedEvent::class), 'Not a failed login: the credential was never read');

        $exceeded = $this->events->of(RateLimitExceededEvent::class);
        $this->assertCount(1, $exceeded);
        $this->assertSame('login', $exceeded[0]->limiter);
        $this->assertSame('ip:unknown', $exceeded[0]->key, 'The test harness sends no REMOTE_ADDR');
        $this->assertSame('/login', $exceeded[0]->path);
        $this->assertSame('POST', $exceeded[0]->method);
    }

    public function testBrowsingWithoutACredentialIsNotCounted(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->assertSame(200, $this->get('/login')->getResponse()->getStatusCode());
        }

        $this->assertNotSame(429, $this->attemptLogin('wrong')->getResponse()->getStatusCode());
    }

    public function testASignedInUserIsNotCountedAgainstTheLoginThrottle(): void
    {
        $this->actingAs('one@example.com', 'secret');

        // A route without its own limit, on a restored session: nothing is
        // presented, nothing is counted.
        for ($i = 0; $i < 5; ++$i) {
            $this->assertSame(200, $this->get('/admin/dashboard')->getResponse()->getStatusCode());
        }

        $this->assertSame([], array_filter(
            $this->events->of(RateLimitExceededEvent::class),
            static fn (RateLimitExceededEvent $e): bool => 'login' === $e->limiter,
        ));
    }

    // ── Routes: #[RateLimit] ────────────────────────────────────────────────

    public function testARouteLimitCountsPerUserOnceSignedIn(): void
    {
        $this->actingAs('one@example.com', 'secret');

        $this->assertSame(200, $this->get('/throttled')->getResponse()->getStatusCode());
        $this->assertSame(200, $this->get('/throttled')->getResponse()->getStatusCode());

        $response = $this->get('/throttled')->getResponse();
        $this->assertSame(429, $response->getStatusCode());
        $this->assertNotSame('', $response->getHeaderLine('Retry-After'));

        $exceeded = $this->events->last(RateLimitExceededEvent::class);
        $this->assertNotNull($exceeded);
        $this->assertSame('api', $exceeded->limiter);
        $this->assertSame('user:one@example.com', $exceeded->key);
        $this->assertSame('one@example.com', $exceeded->userIdentifier);
    }

    public function testAnotherUserHasTheirOwnWindow(): void
    {
        $this->actingAs('one@example.com', 'secret');
        $this->get('/throttled');
        $this->get('/throttled');
        $this->assertSame(429, $this->get('/throttled')->getResponse()->getStatusCode());

        $this->logout();
        $this->actingAs('two@example.com', 'secret');

        $this->assertSame(200, $this->get('/throttled')->getResponse()->getStatusCode());
    }

    public function testEveryLimitOnARouteApplies(): void
    {
        $this->actingAs('one@example.com', 'secret');

        $this->assertSame(200, $this->get('/throttled/expensive')->getResponse()->getStatusCode());

        // The stricter, per-address method-level limiter is spent first.
        $response = $this->get('/throttled/expensive')->getResponse();
        $this->assertSame(429, $response->getStatusCode());
        $exceeded = $this->events->last(RateLimitExceededEvent::class);
        $this->assertNotNull($exceeded);
        $this->assertSame('expensive', $exceeded->limiter);
        $this->assertSame('ip:unknown', $exceeded->key, 'by: ip counts the address even when signed in');
    }

    // ── Configuration ───────────────────────────────────────────────────────

    public function testAnUndeclaredLimiterIsAWiringError(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('No rate limiter named "nope"');

        $this->app()->rateLimiter('nope');
    }

    public function testALimiterNeedsAPolicy(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new SecurityConfigurator())->rateLimiter('login', ['limit' => 5]);
    }

    public function testTheConfiguratorCarriesLimitersToTheKernel(): void
    {
        $security = (new SecurityConfigurator())
            ->rateLimiter('login', ['policy' => 'sliding_window', 'limit' => 5, 'interval' => '15 minutes']);

        $this->assertSame(['login'], array_keys($security->getRateLimiters()));

        $this->app()->configureSecurity($security);
        $limiter = $this->app()->rateLimiter('login')->create('ip:test');

        $this->assertSame(5, $limiter->consume()->getLimit());
    }
}
