<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Event;

use Modufolio\Appkit\Event\ExceptionCaughtEvent;
use Modufolio\Appkit\Event\Http\RequestHandledEvent;
use Modufolio\Appkit\Event\Security\AccessDeniedEvent;
use Modufolio\Appkit\Event\Security\ImpersonationEndedEvent;
use Modufolio\Appkit\Event\Security\ImpersonationStartedEvent;
use Modufolio\Appkit\Event\Security\LoginFailedEvent;
use Modufolio\Appkit\Event\Security\UserLoggedInEvent;
use Modufolio\Appkit\Event\Security\UserLoggedOutEvent;
use Modufolio\Appkit\Security\Exception\BadCredentialsException;
use Modufolio\Appkit\Tests\App\Entity\User;
use Modufolio\Appkit\Tests\App\RecordingEventDispatcher;
use Modufolio\Appkit\Tests\Case\AppTestCase;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

/**
 * What the kernel says, and when: each security moment is one event,
 * dispatched after the state it reports is committed, and never able to
 * change what the kernel decided.
 */
class SecurityEventsTest extends AppTestCase
{
    private RecordingEventDispatcher $events;

    protected function setUp(): void
    {
        parent::setUp();

        $this->refreshDatabase();
        $this->loadFixtures();

        $this->events = RecordingEventDispatcher::instance();
        $this->events->clear();

        $this->app()->configureFirewall([
            'firewalls' => [
                'main' => [
                    'pattern' => '/',
                    'authenticators' => ['form_login'],
                    'entry_point' => '/login',
                    'logout' => ['path' => '/logout', 'target' => '/'],
                    'switch_user' => [
                        'enabled' => true,
                        'role' => 'ROLE_ADMIN',
                        'parameter' => '_switch_user',
                    ],
                ],
            ],
            'role_hierarchy' => [
                'ROLE_ADMIN' => ['ROLE_USER'],
            ],
        ]);

        $this->createUser('admin@example.com', ['ROLE_ADMIN']);
        $this->createUser('member@example.com', ['ROLE_USER']);
    }

    public function tearDown(): void
    {
        $this->events->clear();

        parent::tearDown();
    }

    /**
     * @param list<string> $roles
     */
    private function createUser(string $email, array $roles): void
    {
        $em = $this->app()->entityManager();
        $user = (new User())
            ->setEmail($email)
            ->setPassword(password_hash('secret', PASSWORD_BCRYPT))
            ->setRoles($roles);
        $em->persist($user);
        $em->flush();
    }

    public function testASuccessfulLoginIsAnnouncedOnceWithTheCommittedState(): void
    {
        $this->actingAs('member@example.com', 'secret');

        $logins = $this->events->of(UserLoggedInEvent::class);
        $this->assertCount(1, $logins);

        $event = $logins[0];
        $this->assertSame('member@example.com', $event->userIdentifier);
        $this->assertSame('main', $event->firewallName);
        $this->assertSame('form_login', $event->authenticator);
        $this->assertSame(['ROLE_USER'], $event->roles);
        $this->assertFalse($event->viaRememberMe);
        $this->assertNull($event->clientIp, 'The test harness sends no REMOTE_ADDR, and nothing is invented for it');

        $this->assertSame([], $this->events->of(LoginFailedEvent::class));
    }

    public function testAFailedLoginNamesTheAttemptedAccountButNotTheDetail(): void
    {
        $csrf = $this->app()->csrfTokenManager()->getToken('authenticate')->getValue();

        $this->form('/login', [
            'email' => 'member@example.com',
            'password' => 'wrong',
            '_csrf_token' => $csrf,
        ]);

        $failed = $this->events->of(LoginFailedEvent::class);
        $this->assertCount(1, $failed);
        $this->assertSame('member@example.com', $failed[0]->userIdentifier);
        $this->assertSame('form_login', $failed[0]->authenticator);
        $this->assertSame(BadCredentialsException::class, $failed[0]->reason);
        $this->assertSame('main', $failed[0]->firewallName);

        $this->assertSame([], $this->events->of(UserLoggedInEvent::class));
    }

    public function testAnUnknownAccountFailsTheSameWay(): void
    {
        $csrf = $this->app()->csrfTokenManager()->getToken('authenticate')->getValue();

        $this->form('/login', ['email' => 'nobody@example.com', 'password' => 'x', '_csrf_token' => $csrf]);

        $failed = $this->events->last(LoginFailedEvent::class);
        $this->assertNotNull($failed);
        $this->assertSame('nobody@example.com', $failed->userIdentifier);
    }

    public function testLogoutIsAnnouncedForTheUserWhoLeft(): void
    {
        $this->actingAs('member@example.com', 'secret');
        $this->events->clear();

        $this->logout();

        $out = $this->events->of(UserLoggedOutEvent::class);
        $this->assertCount(1, $out);
        $this->assertSame('member@example.com', $out[0]->userIdentifier);
        $this->assertSame('main', $out[0]->firewallName);
    }

    public function testImpersonationIsAnnouncedOnSwitchAndOnExit(): void
    {
        $this->actingAs('admin@example.com', 'secret');
        $this->events->clear();

        $csrf = $this->app()->csrfTokenManager()->getToken('switch_user')->getValue();
        $this->post('/', ['_switch_user' => 'member@example.com', '_csrf_token' => $csrf], [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ]);

        $started = $this->events->of(ImpersonationStartedEvent::class);
        $this->assertCount(1, $started);
        $this->assertSame('admin@example.com', $started[0]->impersonatorIdentifier);
        $this->assertSame('member@example.com', $started[0]->targetIdentifier);
        $this->assertSame([], $this->events->of(UserLoggedInEvent::class), 'A switch is not a login');

        $csrf = $this->app()->csrfTokenManager()->getToken('switch_user')->getValue();
        $this->post('/', ['_switch_user' => '_exit', '_csrf_token' => $csrf], [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ]);

        $ended = $this->events->of(ImpersonationEndedEvent::class);
        $this->assertCount(1, $ended);
        $this->assertSame('admin@example.com', $ended[0]->impersonatorIdentifier);
        $this->assertSame('member@example.com', $ended[0]->impersonatedIdentifier);
    }

    public function testARefusedRequestIsAnnouncedWithWhoAndWhere(): void
    {
        $this->actingAs('member@example.com', 'secret');
        $this->events->clear();

        $response = $this->get('/admin/settings')->getResponse();
        $this->assertSame(403, $response->getStatusCode());

        $denied = $this->events->of(AccessDeniedEvent::class);
        $this->assertCount(1, $denied);
        $this->assertSame('/admin/settings', $denied[0]->path);
        $this->assertSame('GET', $denied[0]->method);
        $this->assertSame('member@example.com', $denied[0]->userIdentifier);
        $this->assertSame('main', $denied[0]->firewallName);
        $this->assertStringContainsString('ROLE_ADMIN', $denied[0]->reason);
    }

    public function testEveryResponseEndsInRequestHandled(): void
    {
        $response = $this->get('/login')->getResponse();

        $handled = $this->events->of(RequestHandledEvent::class);
        $this->assertCount(1, $handled);
        $this->assertSame('/login', $handled[0]->request->getUri()->getPath());
        $this->assertSame($response->getStatusCode(), $handled[0]->response->getStatusCode());
    }

    public function testAnExceptionIsAnnouncedBeforeItIsRendered(): void
    {
        $this->actingAs('member@example.com', 'secret');
        $this->events->clear();

        $response = $this->get('/no/such/page')->getResponse();
        $this->assertSame(404, $response->getStatusCode());

        $caught = $this->events->of(ExceptionCaughtEvent::class);
        $this->assertCount(1, $caught);
        $this->assertInstanceOf(ResourceNotFoundException::class, $caught[0]->exception);
        $this->assertSame('/no/such/page', $caught[0]->request->getUri()->getPath());

        // The error response is still a response, and still announced.
        $this->assertCount(1, $this->events->of(RequestHandledEvent::class));
    }

    /**
     * The seam is for notifications: a listener that fails cannot undo the
     * login it was told about, nor turn it into an error page.
     */
    public function testAFailingListenerDoesNotChangeTheOutcome(): void
    {
        $this->events->throwOnDispatch = new \RuntimeException('mail server down');

        $csrf = $this->app()->csrfTokenManager()->getToken('authenticate')->getValue();
        $response = $this->form('/login', [
            'email' => 'member@example.com',
            'password' => 'secret',
            '_csrf_token' => $csrf,
        ])->getResponse();

        $this->assertLessThan(400, $response->getStatusCode());
        $this->assertSame('member@example.com', $this->app()->tokenStorage()->getToken()?->getUserIdentifier());
        $this->assertCount(1, $this->events->of(UserLoggedInEvent::class), 'The event was dispatched; the listener failed');
    }
}
