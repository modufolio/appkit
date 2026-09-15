<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Security;

use Modufolio\Appkit\DependencyInjection\ServiceConfigurator;
use Modufolio\Appkit\Security\SessionIdleStatus;
use Modufolio\Appkit\Tests\Case\AppTestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

/**
 * `idle_timeout`: an authenticated session is terminated once it has gone
 * unused for that many seconds. Activity slides the deadline; a path listed in
 * `idle_ignore_paths` is served without sliding it, which is what keeps a
 * status/heartbeat endpoint from renewing the session forever.
 */
class IdleTimeoutTest extends AppTestCase
{
    private const TIMEOUT = 900;

    private MockClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->refreshDatabase();
        $this->loadFixtures();

        $this->clock = new MockClock();

        $services = new ServiceConfigurator();
        $services->set(ClockInterface::class, fn (): ClockInterface => $this->clock);
        $this->app()->configureServices($services);

        $this->app()->configureFirewall([
            'firewalls' => [
                'main' => [
                    'pattern' => '/',
                    'authenticators' => ['form_login'],
                    'entry_point' => '/login',
                    'logout' => ['path' => '/logout', 'target' => '/login'],
                    'idle_timeout' => self::TIMEOUT,
                    'idle_ignore_paths' => ['/session-status'],
                ],
            ],
        ]);
    }

    private function deadline(): ?int
    {
        $value = $this->app()->session()->get(SessionIdleStatus::DEADLINE_KEY.'main');

        return is_int($value) ? $value : null;
    }

    public function testLoginStartsTheIdleClock(): void
    {
        $this->login();

        $this->assertSame(
            $this->clock->now()->getTimestamp() + self::TIMEOUT,
            $this->deadline(),
        );
    }

    public function testSessionSurvivesInactivityShorterThanTheTimeout(): void
    {
        $this->login();

        $this->clock->sleep(self::TIMEOUT - 1);
        $this->get('/')->assertStatus(200);

        $this->assertNotNull(
            $this->app()->tokenStorage()->getToken(),
            'Still signed in one second before the deadline',
        );
    }

    public function testSessionExpiresAfterTheIdleTimeout(): void
    {
        $this->login();

        $this->clock->sleep(self::TIMEOUT);

        $this->get('/')->assertRedirect('/login');
        $this->assertNull($this->app()->tokenStorage()->getToken());
    }

    public function testExpiryFlashesWhyTheVisitorWasSignedOut(): void
    {
        $this->login();
        $this->clock->sleep(self::TIMEOUT);
        $this->get('/');

        $this->assertSame(
            [SessionIdleStatus::TIMEOUT_MESSAGE],
            $this->app()->session()->getFlashBag()->get('info'),
        );
    }

    public function testActivitySlidesTheDeadline(): void
    {
        $this->login();

        $this->clock->sleep(self::TIMEOUT - 60);
        $this->get('/')->assertStatus(200);

        $this->assertSame(
            $this->clock->now()->getTimestamp() + self::TIMEOUT,
            $this->deadline(),
            'A normal request moves the deadline to now + timeout',
        );

        // Having been extended, the session outlives the original deadline.
        $this->clock->sleep(120);
        $this->get('/')->assertStatus(200);
    }

    /**
     * The point of `idle_ignore_paths`. Without it, a panel polling "how long
     * have I got left?" would renew the very deadline it is reporting on, and
     * the session would never time out.
     */
    public function testAnIgnoredPathIsServedWithoutSlidingTheDeadline(): void
    {
        $this->login();
        $deadlineAtLogin = $this->deadline();

        $this->clock->sleep(60);
        $this->get('/session-status')->assertStatus(200);

        $this->assertSame($deadlineAtLogin, $this->deadline());
    }

    public function testPollingAnIgnoredPathDoesNotPreventExpiry(): void
    {
        $this->login();

        // A browser asking every minute for a quarter of an hour.
        for ($minute = 0; $minute < 15; ++$minute) {
            $this->clock->sleep(60);
            $this->get('/session-status');
        }

        $this->get('/')->assertRedirect('/login');
    }

    public function testRemainingSecondsCountDownAndNeverGoNegative(): void
    {
        $this->login();
        $this->assertSame(self::TIMEOUT, $this->app()->idleSecondsRemaining('main'));

        $this->clock->sleep(60);
        $this->assertSame(self::TIMEOUT - 60, $this->app()->idleSecondsRemaining('main'));

        $this->clock->sleep(self::TIMEOUT);
        $this->assertSame(0, $this->app()->idleSecondsRemaining('main'));
    }

    public function testNoTimeoutConfiguredMeansNoExpiryAndNoRemainingTime(): void
    {
        $this->app()->configureFirewall([
            'firewalls' => [
                'main' => [
                    'pattern' => '/',
                    'authenticators' => ['form_login'],
                    'entry_point' => '/login',
                    'logout' => ['path' => '/logout', 'target' => '/login'],
                ],
            ],
        ]);

        $this->login();

        $this->clock->sleep(self::TIMEOUT * 10);
        $this->get('/')->assertStatus(200);

        $this->assertNull($this->app()->idleSecondsRemaining('main'));
    }
}
