<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Security;

use Modufolio\Appkit\Tests\App\Entity\User;
use Modufolio\Appkit\Tests\Case\AppTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The switch-user flow accepts a caller-supplied `_target_path` to land on.
 * That is a redirect sink: if it can be pointed off-site, an attacker mails a
 * link to the genuine panel and the victim ends up on a look-alike login page
 * having already been redirected from a trusted origin.
 *
 * Payload shapes are drawn from cujanovic/Open-Redirect-Payloads. The subtle
 * class — and the one that got past an earlier `!str_starts_with('//')` check —
 * is the backslash: the WHATWG URL parser treats `\` as `/` while reading the
 * authority, so a browser resolves `/\evil.example` as `//evil.example` and
 * leaves the site, even though the string plainly starts with a single slash.
 *
 * Asserted on the Location header rather than on the private helper, so the
 * test pins observable behaviour and survives refactoring.
 */
class OpenRedirectTest extends AppTestCase
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
                    'switch_user' => ['enabled' => true, 'role' => 'ROLE_SUPER_ADMIN'],
                ],
            ],
            'role_hierarchy' => ['ROLE_SUPER_ADMIN' => ['ROLE_ADMIN'], 'ROLE_ADMIN' => ['ROLE_USER']],
        ]);

        $em = $this->app()->entityManager();

        foreach ([['super@example.com', ['ROLE_SUPER_ADMIN']], ['target@example.com', ['ROLE_USER']]] as [$email, $roles]) {
            $user = new User();
            $user->setEmail($email)
                ->setPassword(password_hash('secret', PASSWORD_BCRYPT))
                ->setRoles($roles);
            $em->persist($user);
        }

        $em->flush();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideOffSiteTargets(): iterable
    {
        yield 'protocol relative' => ['//evil.example'];
        yield 'triple slash' => ['///evil.example'];
        yield 'quadruple slash' => ['////evil.example'];
        yield 'backslash after slash' => ['/\\evil.example'];
        yield 'backslash slash' => ['/\\/evil.example/'];
        yield 'double backslash' => ['\\\\evil.example'];
        yield 'backslash then slash' => ['\\/evil.example'];
        yield 'absolute https' => ['https://evil.example/'];
        yield 'absolute http' => ['http://evil.example/'];
        yield 'userinfo confusion' => ['//localhost@evil.example/'];
        yield 'userinfo absolute' => ['https://localhost@evil.example/'];
        yield 'protocol relative with encoded path' => ['//evil.example/%2f..'];
        yield 'backslash userinfo' => ['/\\localhost@evil.example'];
        yield 'javascript scheme' => ['javascript:alert(1)'];
        yield 'data scheme' => ['data:text/html,<script>alert(1)</script>'];
    }

    /**
     * Whatever the payload, the redirect must stay on this host: either a plain
     * path, or nothing that a browser would read as an authority.
     */
    #[DataProvider('provideOffSiteTargets')]
    public function testSwitchUserWillNotRedirectOffSite(string $target): void
    {
        $this->actingAs('super@example.com', 'secret');

        $response = $this->post('/', [
            '_switch_user' => 'target@example.com',
            '_target_path' => $target,
        ], ['Content-Type' => 'application/x-www-form-urlencoded'])->getResponse();

        $location = $response->getHeaderLine('Location');

        // Fold backslashes exactly as a browser does before judging the result.
        $normalised = str_replace('\\', '/', $location);

        $this->assertStringStartsWith('/', $normalised, sprintf('Location %s is not a path', var_export($location, true)));
        $this->assertStringNotContainsString('evil.example', $location);
        $this->assertFalse(
            str_starts_with($normalised, '//'),
            sprintf('Location %s resolves to another host', var_export($location, true))
        );
    }

    /**
     * The legitimate case still works — a same-site path is honoured, so the
     * guard above is not passing merely by refusing everything.
     */
    public function testSameSiteTargetIsHonoured(): void
    {
        $this->actingAs('super@example.com', 'secret');

        $response = $this->post('/', [
            '_switch_user' => 'target@example.com',
            '_target_path' => '/dashboard?tab=1',
        ], ['Content-Type' => 'application/x-www-form-urlencoded'])->getResponse();

        $this->assertSame('/dashboard?tab=1', $response->getHeaderLine('Location'));
    }
}
