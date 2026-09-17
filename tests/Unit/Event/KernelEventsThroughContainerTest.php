<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Event;

use Modufolio\Appkit\DependencyInjection\ContainerFactoryInterface;
use Modufolio\Appkit\Event\Security\UserLoggedInEvent;
use Modufolio\Appkit\Tests\App\Entity\LoginAudit;
use Modufolio\Appkit\Tests\App\Entity\User;
use Modufolio\Appkit\Tests\App\Symfony\LoginAuditListener;
use Modufolio\Appkit\Tests\Case\AppTestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * The end of the wire: a class in the container carrying #[AsEventListener]
 * and nothing else receives the kernel's own security events, dispatched
 * from a real login. Nothing registers it, and no test double stands in for
 * the dispatcher — the kernel adopts the one the container compiled.
 */
class KernelEventsThroughContainerTest extends AppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->refreshDatabase();
        $this->loadFixtures();

        // The test app declares its RecordingEventDispatcher in
        // config/services.php, which wins over the container's. Drop it for
        // this case so the kernel takes the compiled one.
        $this->app()->forgetDeclaredEventDispatcher();
        LoginAuditListener::clear();

        $this->app()->configureFirewall([
            'firewalls' => [
                'main' => [
                    'pattern' => '/',
                    'authenticators' => ['form_login'],
                    'entry_point' => '/login',
                    'logout' => ['path' => '/logout', 'target' => '/'],
                ],
            ],
        ]);

        $em = $this->app()->entityManager();
        $user = (new User())
            ->setEmail('member@example.com')
            ->setPassword(password_hash('secret', PASSWORD_BCRYPT))
            ->setRoles(['ROLE_USER']);
        $em->persist($user);
        $em->flush();
    }

    public function tearDown(): void
    {
        $this->app()->restoreDeclaredEventDispatcher();
        LoginAuditListener::clear();

        parent::tearDown();
    }

    public function testTheKernelDispatchesThroughTheContainersDispatcher(): void
    {
        $this->assertSame(
            $this->app()->get(ContainerFactoryInterface::DISPATCHER_ID),
            $this->app()->eventDispatcher(),
        );
    }

    public function testAnAttributedListenerReceivesARealLogin(): void
    {
        $this->actingAs('member@example.com', 'secret');

        $this->assertSame(['in:member@example.com:main'], LoginAuditListener::$seen);
    }

    public function testTheSameListenerClassTakesASecondEventOnItsOtherMethod(): void
    {
        $this->actingAs('member@example.com', 'secret');
        $this->logout();

        $this->assertSame(
            ['in:member@example.com:main', 'out:member@example.com'],
            LoginAuditListener::$seen,
            'One class, one attribute per method, two kernel events.'
        );
    }

    public function testAFailedLoginReachesNoLoginListener(): void
    {
        $csrf = $this->app()->csrfTokenManager()->getToken('authenticate')->getValue();

        $this->post('/login', [
            'email' => 'member@example.com',
            'password' => 'wrong',
            '_csrf_token' => $csrf,
        ]);

        $this->assertSame([], LoginAuditListener::$seen);
    }

    /**
     * @return list<LoginAudit>
     */
    private function auditRows(): array
    {
        return $this->app()->entityManager()->getRepository(LoginAudit::class)->findAll();
    }

    public function testAnAutowiredListenerWritesTheLoginToTheDatabase(): void
    {
        $this->assertSame([], $this->auditRows(), 'The table starts empty.');

        $this->actingAs('member@example.com', 'secret');

        $rows = $this->auditRows();
        $this->assertCount(1, $rows, 'One login, one row: the container built the listener with a working EntityManager.');
        $this->assertSame('member@example.com', $rows[0]->getUserIdentifier());
        $this->assertSame('main', $rows[0]->getFirewallName());
    }

    public function testNothingIsWrittenWhenTheLoginFails(): void
    {
        $csrf = $this->app()->csrfTokenManager()->getToken('authenticate')->getValue();

        $this->post('/login', [
            'email' => 'member@example.com',
            'password' => 'wrong',
            '_csrf_token' => $csrf,
        ]);

        $this->assertSame([], $this->auditRows());
    }

    public function testTheDispatchedObjectIsTheKernelsOwnEvent(): void
    {
        $seen = [];
        $dispatcher = $this->app()->eventDispatcher();
        $this->assertInstanceOf(EventDispatcher::class, $dispatcher);
        $dispatcher->addListener(
            UserLoggedInEvent::class,
            static function (UserLoggedInEvent $event) use (&$seen): void {
                $seen[] = $event;
            },
        );

        $this->actingAs('member@example.com', 'secret');

        $this->assertCount(1, $seen);
        $this->assertSame('member@example.com', $seen[0]->userIdentifier);
        $this->assertSame('form_login', $seen[0]->authenticator);
    }
}
