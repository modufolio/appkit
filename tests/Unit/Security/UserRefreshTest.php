<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Security;

use Modufolio\Appkit\Tests\App\Entity\User;
use Modufolio\Appkit\Tests\Case\AppTestCase;

/**
 * Functional coverage of the per-request user refresh
 * (AppSecurity::refreshUser / hasUserChanged): the session survives changes
 * that are not security-relevant and is dropped for those that are.
 */
class UserRefreshTest extends AppTestCase
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
                    'logout' => ['path' => '/logout', 'target' => '/login'],
                ],
            ],
        ]);

        $user = new User();
        $user->setEmail('refresh@example.com')
            ->setPassword(password_hash('secret', PASSWORD_BCRYPT))
            ->setRoles(['ROLE_ADMIN', 'ROLE_EDITOR']);

        $em = $this->app()->entityManager();
        $em->persist($user);
        $em->flush();
    }

    private function currentIdentifier(): ?string
    {
        return $this->app()->tokenStorage()->getToken()?->getUser()?->getUserIdentifier();
    }

    /**
     * @param list<string> $roles
     */
    private function storeRoles(array $roles): void
    {
        $em = $this->app()->entityManager();
        $user = $em->getRepository(User::class)->findOneBy(['email' => 'refresh@example.com']);
        $this->assertInstanceOf(User::class, $user);

        $user->setRoles($roles);
        $em->flush();
        $em->clear();
    }

    public function testReorderedRolesKeepTheSessionAlive(): void
    {
        $this->actingAs('refresh@example.com', 'secret');
        $this->assertSame('refresh@example.com', $this->currentIdentifier());

        $this->storeRoles(['ROLE_EDITOR', 'ROLE_ADMIN']);

        $this->get('/');

        $this->assertSame('refresh@example.com', $this->currentIdentifier());
    }

    public function testRevokedRoleEndsTheSession(): void
    {
        $this->actingAs('refresh@example.com', 'secret');

        $this->storeRoles(['ROLE_EDITOR']);

        $this->get('/');

        $this->assertNull($this->currentIdentifier());
    }
}
