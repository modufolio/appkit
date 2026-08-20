<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Security\AccessControl;

use Modufolio\Appkit\Security\AccessControl\AccessDecisionEngine;
use Modufolio\Appkit\Security\AccessControl\AccessRule;
use Modufolio\Appkit\Security\AccessControl\RuleConstraintInterface;
use Modufolio\Appkit\Security\AuthenticationTrustResolverInterface;
use Modufolio\Appkit\Security\Exception\AccessDeniedException;
use Modufolio\Appkit\Security\Exception\AuthenticationException;
use Modufolio\Appkit\Security\Exception\InsecureChannelException;
use Modufolio\Appkit\Security\RoleHierarchy;
use Modufolio\Appkit\Security\Token\RememberMeToken;
use Modufolio\Appkit\Security\Token\SwitchUserToken;
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
    /**
     * @param array<string, mixed> $serverParams
     */
    private function request(string $path, string $method = 'GET', array $serverParams = []): ServerRequest
    {
        return new ServerRequest($method, $path, [], null, '1.1', $serverParams);
    }

    /**
     * @param list<string> $roles
     */
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
            rules: [['path' => '/admin', 'roles' => ['ROLE_USER']]],
            roleHierarchy: new RoleHierarchy(['ROLE_ADMIN' => ['ROLE_USER']]),
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

    public function testHttpsRequirementRedirectsToSecureUrl(): void
    {
        $engine = new AccessDecisionEngine([['path' => '/secure', 'requires_channel' => 'https']]);

        try {
            $engine->enforce($this->request('http://example.com/secure?a=1'), null);
            $this->fail('Expected InsecureChannelException');
        } catch (InsecureChannelException $e) {
            // Carries the https upgrade target so the handler can redirect.
            $this->assertSame('https://example.com/secure?a=1', $e->getTargetUrl());
        }
    }

    public function testHttpsRequirementPassesOverHttps(): void
    {
        $engine = new AccessDecisionEngine([['path' => '/secure', 'requires_channel' => 'https']]);

        $engine->enforce($this->request('https://example.com/secure'), null);

        $this->addToAssertionCount(1);
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

    // -----------------------------------------------------------------
    // Trust-level attributes (IS_AUTHENTICATED_FULLY / IS_IMPERSONATOR)
    // -----------------------------------------------------------------

    /**
     * @param list<string> $roles
     */
    private function rememberMeToken(array $roles = ['ROLE_USER']): RememberMeToken
    {
        return new RememberMeToken(new InMemoryUser('johndoe', 'secret', $roles), 'main', 'secret', $roles);
    }

    private function switchUserToken(): SwitchUserToken
    {
        return new SwitchUserToken(
            new InMemoryUser('victim', 'secret', ['ROLE_USER']),
            'main',
            ['ROLE_USER'],
            $this->token(['ROLE_ADMIN']),
        );
    }

    public function testFullyAuthenticatedRuleAllowsInteractiveLogin(): void
    {
        $engine = new AccessDecisionEngine([['path' => '/settings', 'roles' => [AuthenticationTrustResolverInterface::IS_AUTHENTICATED_FULLY]]]);

        $engine->enforce($this->request('/settings'), $this->token());

        $this->addToAssertionCount(1);
    }

    public function testFullyAuthenticatedRuleForcesStepUpForRememberMe(): void
    {
        // A remember-me session must re-authenticate for a FULLY-required path,
        // so this is an AuthenticationException (log in), not a hard 403.
        $engine = new AccessDecisionEngine([['path' => '/settings', 'roles' => [AuthenticationTrustResolverInterface::IS_AUTHENTICATED_FULLY]]]);

        $this->expectException(AuthenticationException::class);
        $engine->enforce($this->request('/settings'), $this->rememberMeToken());
    }

    public function testRememberedRuleAcceptsBothRememberMeAndFullLogin(): void
    {
        $engine = new AccessDecisionEngine([['path' => '/feed', 'roles' => [AuthenticationTrustResolverInterface::IS_AUTHENTICATED_REMEMBERED]]]);

        $engine->enforce($this->request('/feed'), $this->rememberMeToken());
        $engine->enforce($this->request('/feed'), $this->token());

        $this->addToAssertionCount(2);
    }

    public function testImpersonatorRuleOnlyAllowsSwitchUserToken(): void
    {
        $engine = new AccessDecisionEngine([['path' => '/_exit', 'roles' => [AuthenticationTrustResolverInterface::IS_IMPERSONATOR]]]);

        $engine->enforce($this->request('/_exit'), $this->switchUserToken());

        $this->expectException(AccessDeniedException::class);
        $engine->enforce($this->request('/_exit'), $this->token(['ROLE_ADMIN']));
    }

    public function testTrustAttributeWorksInRoleGroups(): void
    {
        $engine = new AccessDecisionEngine([]);

        $engine->enforceRoleGroups([[AuthenticationTrustResolverInterface::IS_AUTHENTICATED_FULLY]], $this->token());

        $this->expectException(AuthenticationException::class);
        $engine->enforceRoleGroups([[AuthenticationTrustResolverInterface::IS_AUTHENTICATED_FULLY]], $this->rememberMeToken());
    }

    // -----------------------------------------------------------------
    // Deny-by-default (fail-closed)
    // -----------------------------------------------------------------

    public function testUnmatchedPathAllowedByDefault(): void
    {
        $engine = new AccessDecisionEngine([['path' => '/admin', 'roles' => ['ROLE_ADMIN']]]);

        $engine->enforce($this->request('/blog'), null);

        $this->addToAssertionCount(1);
    }

    public function testDenyByDefaultSendsAnonymousToLogin(): void
    {
        $engine = new AccessDecisionEngine([['path' => '/admin', 'roles' => ['ROLE_ADMIN']]], denyByDefault: true);

        $this->expectException(AuthenticationException::class);
        $engine->enforce($this->request('/blog'), null);
    }

    public function testDenyByDefaultForbidsAuthenticatedUserOnUnmatchedPath(): void
    {
        $engine = new AccessDecisionEngine([['path' => '/admin', 'roles' => ['ROLE_ADMIN']]], denyByDefault: true);

        $this->expectException(AccessDeniedException::class);
        $engine->enforce($this->request('/blog'), $this->token());
    }

    public function testDenyByDefaultStillHonoursMatchingRules(): void
    {
        $engine = new AccessDecisionEngine([['path' => '/admin', 'roles' => ['ROLE_ADMIN']]], denyByDefault: true);

        // A matched, satisfied rule passes even with deny-by-default on.
        $engine->enforce($this->request('/admin'), $this->token(['ROLE_ADMIN']));

        $this->addToAssertionCount(1);
    }
}
