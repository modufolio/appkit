<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Security;

use Modufolio\Appkit\Security\Token\SwitchUserToken;
use Modufolio\Appkit\Tests\App\Entity\User;
use Modufolio\Appkit\Tests\Case\AppTestCase;

/**
 * Functional coverage of user impersonation (AppSecurity::handleSwitchUser),
 * the appkit counterpart of Symfony's SwitchUserListener.
 *
 * The firewall enables it with:
 *
 *     'switch_user' => ['enabled' => true, 'role' => 'ROLE_SUPER_ADMIN']
 *
 * Impersonation is deliberately POST-only and CSRF-protected here, unlike
 * Symfony's `?_switch_user=` link — see the method docblock for why.
 */
class SwitchUserTest extends AppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->refreshDatabase();
        $this->loadFixtures();

        $this->app()->configureFirewall([
            'firewalls' => [
                'main' => [
                    'pattern' => '/',
                    'authenticators' => ['form_login'],
                    'entry_point' => '/login',
                    'logout' => ['path' => '/logout', 'target' => '/'],
                    'switch_user' => [
                        'enabled' => true,
                        'role' => 'ROLE_SUPER_ADMIN',
                        'parameter' => '_switch_user',
                    ],
                ],
            ],
            'role_hierarchy' => [
                'ROLE_SUPER_ADMIN' => ['ROLE_ADMIN'],
                'ROLE_ADMIN' => ['ROLE_USER'],
            ],
        ]);

        $this->createUser('super@example.com', ['ROLE_SUPER_ADMIN']);
        $this->createUser('admin@example.com', ['ROLE_ADMIN']);
        $this->createUser('target@example.com', ['ROLE_USER']);
    }

    /**
     * @param list<string> $roles
     */
    private function createUser(string $email, array $roles): void
    {
        $em = $this->app()->entityManager();

        $user = new User();
        $user->setEmail($email)
            ->setPassword(password_hash('secret', PASSWORD_BCRYPT))
            ->setRoles($roles);

        $em->persist($user);
        $em->flush();
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function switchTo(string $identifier, array $extra = []): \Modufolio\Appkit\Testing\TestResponse
    {
        return $this->post('/', ['_switch_user' => $identifier] + $extra, [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ]);
    }

    private function currentIdentifier(): ?string
    {
        return $this->app()->tokenStorage()->getToken()?->getUser()?->getUserIdentifier();
    }

    public function testSuperAdminSwitchesToAnotherUser(): void
    {
        $this->actingAs('super@example.com', 'secret');

        $this->switchTo('target@example.com')->assertStatus(302);

        $token = $this->app()->tokenStorage()->getToken();

        $this->assertInstanceOf(SwitchUserToken::class, $token);
        $this->assertSame('target@example.com', $token->getUser()?->getUserIdentifier());
        $this->assertSame('super@example.com', $token->getOriginalToken()->getUser()?->getUserIdentifier());
    }

    /**
     * The configured role is read through the role hierarchy — the whole point
     * of the reported bug, where a super admin was refused a switch their role
     * plainly reaches.
     */
    public function testRoleIsResolvedThroughTheHierarchy(): void
    {
        $this->app()->configureFirewall([
            'firewalls' => [
                'main' => [
                    'pattern' => '/',
                    'authenticators' => ['form_login'],
                    'entry_point' => '/login',
                    'switch_user' => ['enabled' => true, 'role' => 'ROLE_ADMIN'],
                ],
            ],
            'role_hierarchy' => [
                'ROLE_SUPER_ADMIN' => ['ROLE_ADMIN'],
                'ROLE_ADMIN' => ['ROLE_USER'],
            ],
        ]);

        // Holds ROLE_SUPER_ADMIN, which reaches the configured ROLE_ADMIN.
        $this->actingAs('super@example.com', 'secret');
        $this->switchTo('target@example.com');

        $this->assertSame('target@example.com', $this->currentIdentifier());
    }

    public function testUserWithoutTheConfiguredRoleIsDenied(): void
    {
        $this->actingAs('admin@example.com', 'secret');

        $this->switchTo('target@example.com')->assertStatus(403);
        $this->assertSame('admin@example.com', $this->currentIdentifier());
    }

    public function testExitReturnsToTheOriginalAccount(): void
    {
        $this->actingAs('super@example.com', 'secret');
        $this->switchTo('target@example.com');

        $this->switchTo('_exit')->assertStatus(302);

        $this->assertNotInstanceOf(SwitchUserToken::class, $this->app()->tokenStorage()->getToken());
        $this->assertSame('super@example.com', $this->currentIdentifier());
    }

    /**
     * Switching again while impersonating must unwind first, so "exit" always
     * lands on the real administrator rather than a previous impersonation.
     */
    public function testChainedSwitchesDoNotNest(): void
    {
        $this->actingAs('super@example.com', 'secret');
        $this->switchTo('target@example.com');
        $this->switchTo('admin@example.com');

        $token = $this->app()->tokenStorage()->getToken();

        $this->assertInstanceOf(SwitchUserToken::class, $token);
        $this->assertSame('admin@example.com', $token->getUser()?->getUserIdentifier());
        $this->assertSame('super@example.com', $token->getOriginalToken()->getUser()?->getUserIdentifier());
    }

    /**
     * A GET link would let a third-party page silently switch an
     * administrator's session via <img src="/?_switch_user=victim">.
     */
    public function testGetRequestDoesNotSwitch(): void
    {
        $this->actingAs('super@example.com', 'secret');

        $this->get('/', ['_switch_user' => 'target@example.com']);

        $this->assertSame('super@example.com', $this->currentIdentifier());
    }

    public function testSwitchWithoutCsrfTokenIsRejected(): void
    {
        $this->actingAs('super@example.com', 'secret');

        $this->withoutCsrfToken()->switchTo('target@example.com');

        $this->assertSame('super@example.com', $this->currentIdentifier());
    }

    /**
     * A caller past the role check must not learn which identifiers exist:
     * an unknown user is refused exactly like a forbidden one.
     */
    public function testUnknownIdentifierIsDeniedWithoutEnumeration(): void
    {
        $this->actingAs('super@example.com', 'secret');

        $this->switchTo('nobody@example.com')->assertStatus(403);
        $this->assertSame('super@example.com', $this->currentIdentifier());
    }

    public function testSwitchIsInertWhenTheFirewallDoesNotEnableIt(): void
    {
        $this->app()->configureFirewall([
            'firewalls' => [
                'main' => [
                    'pattern' => '/',
                    'authenticators' => ['form_login'],
                    'entry_point' => '/login',
                    'switch_user' => ['enabled' => false, 'role' => 'ROLE_SUPER_ADMIN'],
                ],
            ],
        ]);

        $this->actingAs('super@example.com', 'secret');
        $this->switchTo('target@example.com');

        $this->assertSame('super@example.com', $this->currentIdentifier());
    }

    public function testExitingWithoutImpersonatingDoesNotChangeTheSession(): void
    {
        $this->actingAs('super@example.com', 'secret');

        $this->switchTo('_exit');

        $this->assertSame('super@example.com', $this->currentIdentifier());
    }
}
