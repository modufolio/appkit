<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Security;

use Modufolio\Appkit\Tests\App\Entity\User;
use Modufolio\Appkit\Tests\Case\AppTestCase;

/**
 * Functional coverage of #[IsGranted] attribute enforcement
 * (AppSecurity::enforceAttributeAccessControl via _is_granted_roles route
 * defaults set by AttributeClassLoader).
 *
 * AdminController declares #[IsGranted('ROLE_USER')] at class level; the
 * /admin/settings route adds #[IsGranted('ROLE_ADMIN')] (groups are AND'd)
 * and /admin/audit accepts ROLE_ADMIN or ROLE_AUDITOR (OR within a group).
 * The role hierarchy in config/security.php maps ROLE_ADMIN => ROLE_USER.
 */
class IsGrantedAttributeTest extends AppTestCase
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
                ],
            ],
            'role_hierarchy' => [
                'ROLE_ADMIN' => ['ROLE_USER'],
            ],
        ]);

        $this->createUser('admin@example.com', ['ROLE_ADMIN']);
        $this->createUser('auditor@example.com', ['ROLE_AUDITOR']);
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

    public function testUnauthenticatedRequestIsRejected(): void
    {
        $status = $this->get('/admin/dashboard')->getResponse()->getStatusCode();

        // Not authenticated: either redirected to the entry point or rejected
        $this->assertContains($status, [302, 401]);
    }

    public function testClassLevelRoleIsEnforcedForAllRoutes(): void
    {
        // johndoe has ROLE_USER (implicit) which satisfies the class attribute
        $this->actingAs('johndoe@example.com', 'secret');

        $this->get('/admin/dashboard')->assertStatus(200);
    }

    public function testMethodLevelAttributeTightensClassAttribute(): void
    {
        // ROLE_USER passes the class gate but not the method's ROLE_ADMIN
        $this->actingAs('johndoe@example.com', 'secret');

        $this->get('/admin/settings')->assertStatus(403);
    }

    public function testRoleFromHierarchySatisfiesMethodAttribute(): void
    {
        // ROLE_ADMIN reaches ROLE_USER via the hierarchy, satisfying both
        // the class-level ROLE_USER gate and the method-level ROLE_ADMIN gate.
        $this->actingAs('admin@example.com', 'secret');

        $this->get('/admin/settings')->assertStatus(200);
        $this->get('/admin/dashboard')->assertStatus(200);
    }

    public function testAnyRoleWithinGroupIsSufficient(): void
    {
        // /admin/audit requires ROLE_ADMIN OR ROLE_AUDITOR
        $this->actingAs('auditor@example.com', 'secret');

        $this->get('/admin/audit')->assertStatus(200);
    }

    public function testUserWithoutAnyGroupRoleIsDenied(): void
    {
        $this->actingAs('johndoe@example.com', 'secret');

        $this->get('/admin/audit')->assertStatus(403);
    }

    public function testMethodScopedAttributeIsSkippedForOtherMethods(): void
    {
        // /admin/posts requires ROLE_ADMIN on POST only; GET is left to the
        // class-level ROLE_USER gate, which johndoe satisfies.
        $this->actingAs('johndoe@example.com', 'secret');

        $this->get('/admin/posts')->assertStatus(200);
    }

    public function testMethodScopedAttributeIsEnforcedForItsMethod(): void
    {
        $this->actingAs('johndoe@example.com', 'secret');

        $this->post('/admin/posts')->assertStatus(403);
    }

    public function testMethodScopedAttributePassesForPermittedUser(): void
    {
        $this->actingAs('admin@example.com', 'secret');

        $this->post('/admin/posts')->assertStatus(200);
    }
}
