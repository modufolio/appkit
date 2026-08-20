<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Security\RememberMe;

use Modufolio\Appkit\Security\Authenticator\RememberMeAuthenticator;
use Modufolio\Appkit\Security\Exception\AuthenticationException;
use Modufolio\Appkit\Security\Exception\CookieTheftException;
use Modufolio\Appkit\Security\RememberMe\FileTokenProvider;
use Modufolio\Appkit\Security\RememberMe\InMemoryTokenProvider;
use Modufolio\Appkit\Security\RememberMe\PersistentToken;
use Modufolio\Appkit\Security\User\InMemoryUser;
use Modufolio\Appkit\Tests\App\InMemoryUserProvider;
use Modufolio\Psr7\Http\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * Persistent (series + rotating value) remember-me: the theft-detection and
 * rotation behaviour a stateless signature cookie cannot provide.
 */
class PersistentRememberMeTest extends TestCase
{
    private InMemoryUserProvider $userProvider;
    private InMemoryTokenProvider $tokens;

    protected function setUp(): void
    {
        $this->userProvider = (new InMemoryUserProvider())->addUser(
            new InMemoryUser('test@example.com', 'hash', ['ROLE_USER']),
        );
        $this->tokens = new InMemoryTokenProvider();
    }

    private function authenticator(): RememberMeAuthenticator
    {
        return new RememberMeAuthenticator(
            $this->userProvider,
            ['secret' => 'x', 'cookie_secure' => false],
            tokenProvider: $this->tokens,
        );
    }

    private function requestWithCookie(string $value): ServerRequest
    {
        return (new ServerRequest('GET', '/'))->withCookieParams(['REMEMBERME' => $value]);
    }

    public function testIsPersistentWhenProviderGiven(): void
    {
        $this->assertTrue($this->authenticator()->isPersistent());
    }

    public function testIssueStoresATokenAndAuthenticatesOnce(): void
    {
        $auth = $this->authenticator();

        $cookie = $auth->generateRememberMeCookie($this->userProvider->loadUserByIdentifier('test@example.com'));

        $user = $auth->authenticate($this->requestWithCookie($cookie));
        $this->assertSame('test@example.com', $user->getUserIdentifier());

        // The value rotated, so a fresh cookie is queued for the response.
        $this->assertNotNull($auth->consumePendingCookieHeader());
    }

    public function testValueRotatesOnEveryUse(): void
    {
        $auth = $this->authenticator();
        $cookie1 = $auth->generateRememberMeCookie($this->userProvider->loadUserByIdentifier('test@example.com'));

        $auth->authenticate($this->requestWithCookie($cookie1));
        $header = $auth->consumePendingCookieHeader();
        $this->assertNotNull($header);

        // Extract the rotated cookie value from the Set-Cookie header.
        $this->assertMatchesRegularExpression('/REMEMBERME=([^;]+)/', $header, 'rotated cookie present');
        preg_match('/REMEMBERME=([^;]+)/', $header, $m);
        $cookie2 = $m[1] ?? self::fail('rotated cookie value missing');
        $this->assertNotSame($cookie1, $cookie2, 'value must change on use');

        // The rotated cookie authenticates.
        $user = $auth->authenticate($this->requestWithCookie($cookie2));
        $this->assertSame('test@example.com', $user->getUserIdentifier());
    }

    public function testReplayingAnOldCookieIsDetectedAsTheft(): void
    {
        $auth = $this->authenticator();
        $cookie1 = $auth->generateRememberMeCookie($this->userProvider->loadUserByIdentifier('test@example.com'));

        // Legit use rotates the stored value away from cookie1.
        $auth->authenticate($this->requestWithCookie($cookie1));
        $auth->consumePendingCookieHeader();

        // The series both cookies share (rotation changes only the value).
        [$series] = explode(':', (string) base64_decode($cookie1, true), 2);
        $this->assertNotNull($this->tokens->loadTokenBySeries($series), 'series still stored before theft');

        // Replaying the now-stale cookie1 (as a thief would) is unambiguous theft.
        try {
            $auth->authenticate($this->requestWithCookie($cookie1));
            $this->fail('Expected CookieTheftException');
        } catch (CookieTheftException) {
            $this->addToAssertionCount(1);
        }

        // Theft revokes every token for the user: the legit device is logged out too.
        $this->assertNull($this->tokens->loadTokenBySeries($series), 'all user tokens revoked on theft');
    }

    public function testUnknownSeriesIsRejected(): void
    {
        $auth = $this->authenticator();

        $this->expectException(AuthenticationException::class);
        $auth->authenticate($this->requestWithCookie(base64_encode('nope:whatever')));
    }

    public function testExpiredTokenIsRejectedAndDeleted(): void
    {
        $auth = new RememberMeAuthenticator(
            $this->userProvider,
            ['secret' => 'x', 'cookie_secure' => false, 'cookie_lifetime' => 1],
            tokenProvider: $this->tokens,
        );

        // Seed a token whose lastUsed is well past the 1-second lifetime.
        $value = 'plain-value';
        $this->tokens->createNewToken(new PersistentToken(
            userIdentifier: 'test@example.com',
            series: 'series1',
            tokenValue: hash('sha256', $value),
            lastUsed: time() - 100,
        ));

        try {
            $auth->authenticate($this->requestWithCookie(base64_encode('series1:'.$value)));
            $this->fail('Expected expiry rejection');
        } catch (CookieTheftException $e) {
            $this->fail('Expiry must not be misread as theft');
        } catch (AuthenticationException) {
            $this->addToAssertionCount(1);
        }

        $this->assertNull($this->tokens->loadTokenBySeries('series1'), 'expired token is deleted');
    }

    public function testFileTokenProviderRoundTrips(): void
    {
        $dir = sys_get_temp_dir().'/appkit-rememberme-'.bin2hex(random_bytes(6));
        $provider = new FileTokenProvider($dir);

        $provider->createNewToken(new PersistentToken(userIdentifier: 'a@example.com', series: 's1', tokenValue: 'hash1', lastUsed: 1000));
        $loaded = $provider->loadTokenBySeries('s1');
        $this->assertNotNull($loaded);
        $this->assertSame('a@example.com', $loaded->userIdentifier);
        $this->assertSame('hash1', $loaded->tokenValue);

        $provider->updateExistingToken('s1', 'hash2', 2000);
        $this->assertSame('hash2', $provider->loadTokenBySeries('s1')?->tokenValue);

        $provider->createNewToken(new PersistentToken(userIdentifier: 'a@example.com', series: 's2', tokenValue: 'hashX', lastUsed: 1000));
        $provider->deleteTokensByUserIdentifier('a@example.com');
        $this->assertNull($provider->loadTokenBySeries('s1'));
        $this->assertNull($provider->loadTokenBySeries('s2'));

        @rmdir($dir);
    }
}
