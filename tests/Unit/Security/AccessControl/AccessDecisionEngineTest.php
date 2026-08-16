<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Security\AccessControl;

use Modufolio\Appkit\Security\AccessControl\AccessDecisionEngine;
use Modufolio\Appkit\Security\AccessControl\AccessRule;
use Modufolio\Appkit\Security\AccessControl\RuleConstraintInterface;
use Modufolio\Appkit\Security\Exception\AccessDeniedException;
use Modufolio\Appkit\Security\Exception\AuthenticationException;
use Modufolio\Appkit\Security\RoleHierarchy;
use Modufolio\Appkit\Security\Token\TokenInterface;
use Modufolio\Appkit\Security\Token\UsernamePasswordToken;
use Modufolio\Appkit\Security\User\InMemoryUser;
use Modufolio\Psr7\Http\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;

#[CoversClass(AccessDecisionEngine::class)]
class AccessDecisionEngineTest extends TestCase
{
    private function request(string $path, string $method = 'GET', array $serverParams = []): ServerRequest
    {
        return new ServerRequest($method, $path, [], null, '1.1', $serverParams);
    }

    private function token(array $roles = ['ROLE_USER']): UsernamePasswordToken
    {
        return new UsernamePasswordToken(new InMemoryUser('johndoe', 'secret', $roles), 'main', $roles);
    }

    // -----------------------------------------------------------------
    // enforce()
    // -----------------------------------------------------------------

    public function testUnmatchedPathAbstains(): void
    {
        $engine = new AccessDecisionEngine([['path' => '/admin', 'roles' => ['ROLE_ADMIN']]]);

        $engine->enforce($this->request('/blog'), null);

        $this->addToAssertionCount(1);
    }

    public function testMissingTokenRequiresAuthentication(): void
    {
        $engine = new AccessDecisionEngine([['path' => '/admin', 'roles' => ['ROLE_ADMIN']]]);

        $this->expectException(AuthenticationException::class);
        $engine->enforce($this->request('/admin'), null);
    }

    public function testInsufficientRolesAreDenied(): void
    {
        $engine = new AccessDecisionEngine([['path' => '/admin', 'roles' => ['ROLE_ADMIN']]]);

        $this->expectException(AccessDeniedException::class);
        $engine->enforce($this->request('/admin'), $this->token(['ROLE_USER']));
    }

    public function testRoleHierarchyGrantsInheritedRole(): void
    {
        $engine = new AccessDecisionEngine(
            [['path' => '/admin', 'roles' => ['ROLE_USER']]],
            new RoleHierarchy(['ROLE_ADMIN' => ['ROLE_USER']]),
        );

        $engine->enforce($this->request('/admin'), $this->token(['ROLE_ADMIN']));

        $this->addToAssertionCount(1);
    }

    public function testFirstMatchingRuleWins(): void
    {
        // The first matching rule passes, so the stricter later rule is not consulted.
        $engine = new AccessDecisionEngine([
            ['path' => '/admin', 'roles' => ['ROLE_USER']],
            ['path' => '/admin', 'roles' => ['ROLE_ADMIN']],
        ]);

        $engine->enforce($this->request('/admin'), $this->token(['ROLE_USER']));

        $this->addToAssertionCount(1);
    }

    public function testPublicRuleIsSkippedDuringEnforcement(): void
    {
        $engine = new AccessDecisionEngine([
            ['path' => '/admin', 'roles' => ['PUBLIC_ACCESS']],
            ['path' => '/admin', 'roles' => ['ROLE_ADMIN']],
        ]);

        $this->expectException(AuthenticationException::class);
        $engine->enforce($this->request('/admin'), null);
    }

    public function testDisallowedMethodIsRejected(): void
    {
        $engine = new AccessDecisionEngine([['path' => '/profile', 'roles' => ['ROLE_USER'], 'methods' => ['GET']]]);

        $this->expectException(MethodNotAllowedException::class);
        $engine->enforce($this->request('/profile', 'DELETE'), $this->token());
    }

    public function testHttpsRequirementIsEnforced(): void
    {
        $engine = new AccessDecisionEngine([['path' => '/secure', 'requires_channel' => 'https']]);

        $this->expectException(AuthenticationException::class);
        $engine->enforce($this->request('http://example.com/secure'), null);
    }

    public function testIpRestrictionIsEnforced(): void
    {
        $engine = new AccessDecisionEngine([['path' => '/internal', 'ips' => ['10.0.0.0/8']]]);

        $engine->enforce($this->request('/internal', 'GET', ['REMOTE_ADDR' => '10.1.2.3']), null);

        $this->expectException(AccessDeniedException::class);
        $engine->enforce($this->request('/internal', 'GET', ['REMOTE_ADDR' => '192.168.1.1']), null);
    }

    public function testEncodedPathIsDecodedBeforeMatching(): void
    {
        $engine = new AccessDecisionEngine([['path' => '/admin', 'roles' => ['ROLE_ADMIN']]]);

        // "/%61dmin" decodes to "/admin" — must not slip past the rule.
        $this->expectException(AuthenticationException::class);
        $engine->enforce($this->request('/%61dmin'), null);
    }

    public function testMalformedRuleFailsAtConstruction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AccessDecisionEngine([['path' => '/admin', 'roles' => 'ROLE_ADMIN']]);
    }

    public function testCustomConstraintRunsOnMatchedRule(): void
    {
        $engine = new AccessDecisionEngine([['path' => '/reports', 'lockdown' => true]]);
        $engine->registerConstraint(new class implements RuleConstraintInterface {
            public function assert(AccessRule $rule, ServerRequestInterface $request, ?TokenInterface $token): void
            {
                if ($rule->extra['lockdown'] ?? false) {
                    throw new AccessDeniedException('Lockdown in effect.');
                }
            }
        });

        $this->expectException(AccessDeniedException::class);
        $engine->enforce($this->request('/reports'), $this->token());
    }

    // -----------------------------------------------------------------
    // isPublic()
    // -----------------------------------------------------------------

    public function testPublicPathWaivesLogin(): void
    {
        $engine = new AccessDecisionEngine([['path' => '/contact', 'roles' => ['PUBLIC_ACCESS']]]);

        $this->assertTrue($engine->isPublic($this->request('/contact')));
        $this->assertFalse($engine->isPublic($this->request('/admin')));
    }

    public function testPublicPathCanBeMethodScoped(): void
    {
        $engine = new AccessDecisionEngine([['path' => '/page', 'roles' => ['PUBLIC_ACCESS'], 'methods' => ['GET']]]);

        $this->assertTrue($engine->isPublic($this->request('/page')));
        $this->assertFalse($engine->isPublic($this->request('/page', 'POST')));
    }

    public function testPublicPathCanBeFirewallScoped(): void
    {
        $engine = new AccessDecisionEngine([['path' => '/', 'roles' => ['PUBLIC_ACCESS'], 'firewall' => 'main']]);

        $this->assertTrue($engine->isPublic($this->request('/anything'), 'main'));
        $this->assertFalse($engine->isPublic($this->request('/anything'), 'admin'));
    }

    // -----------------------------------------------------------------
    // enforceRoleGroups()
    // -----------------------------------------------------------------

    public function testRoleGroupsRequireAuthentication(): void
    {
        $engine = new AccessDecisionEngine([]);

        $this->expectException(AuthenticationException::class);
        $engine->enforceRoleGroups([['ROLE_USER']], null);
    }

    public function testEveryRoleGroupMustBeSatisfied(): void
    {
        $engine = new AccessDecisionEngine([]);

        // AND across groups, OR within a group.
        $engine->enforceRoleGroups([['ROLE_USER', 'ROLE_STAFF'], ['ROLE_EDITOR']], $this->token(['ROLE_USER', 'ROLE_EDITOR']));

        $this->expectException(AccessDeniedException::class);
        $engine->enforceRoleGroups([['ROLE_USER'], ['ROLE_ADMIN']], $this->token(['ROLE_USER']));
    }

    public function testLegacyFlatRoleGroupIsTolerated(): void
    {
        $engine = new AccessDecisionEngine([]);

        $engine->enforceRoleGroups(['ROLE_USER'], $this->token(['ROLE_USER']));

        $this->addToAssertionCount(1);
    }
}
