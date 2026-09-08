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

    /**
     * Seed a series directly, so the previous-value grace window can be placed
     * on either side of "now" without a clock abstraction.
     */
    private function seed(string $series, string $current, ?string $previous, int $previousExpiresAt): void
    {
        $this->tokens->createNewToken(new PersistentToken(
            userIdentifier: 'test@example.com',
            series: $series,
            tokenValue: hash('sha256', $current),
            lastUsed: time(),
            previousTokenValue: null === $previous ? null : hash('sha256', $previous),
            previousValueExpiresAt: $previousExpiresAt,
        ));
    }

    public function testReplayingACookieOlderThanTheGraceWindowIsDetectedAsTheft(): void
    {
        $auth = $this->authenticator();

        // A value the series rotated away from, whose grace window has closed.
        $this->seed('series1', 'current-value', 'stale-value', time() - 1);

        try {
            $auth->authenticate($this->requestWithCookie(base64_encode('series1:stale-value')));
            $this->fail('Expected CookieTheftException');
        } catch (CookieTheftException) {
            $this->addToAssertionCount(1);
        }

        // Theft revokes every token for the user: the legit device is logged out too.
        $this->assertNull($this->tokens->loadTokenBySeries('series1'), 'all user tokens revoked on theft');
    }

    public function testAValueTheSeriesNeverHeldIsDetectedAsTheft(): void
    {
        $auth = $this->authenticator();
        $this->seed('series1', 'current-value', 'stale-value', time() + 60);

        $this->expectException(CookieTheftException::class);
        $auth->authenticate($this->requestWithCookie(base64_encode('series1:never-issued')));
    }

    /**
     * The race this grace window exists to close: several requests fired at
     * once all carry the value that was current when the page loaded. One wins
     * the rotation; the rest must authenticate rather than log the user out of
     * every device.
     */
    public function testSupersededValueInsideTheGraceWindowIsNotTheft(): void
    {
        $auth = $this->authenticator();
        $this->seed('series1', 'rotated-value', 'in-flight-value', time() + 60);

        $user = $auth->authenticate($this->requestWithCookie(base64_encode('series1:in-flight-value')));

        $this->assertSame('test@example.com', $user->getUserIdentifier());
        $this->assertNotNull($this->tokens->loadTokenBySeries('series1'), 'tokens must survive a parallel request');
    }

    public function testTheStragglerDoesNotRotateOrReissueTheCookie(): void
    {
        $auth = $this->authenticator();
        $this->seed('series1', 'rotated-value', 'in-flight-value', time() + 60);

        $auth->authenticate($this->requestWithCookie(base64_encode('series1:in-flight-value')));

        // The winning request's response already carries the new cookie; this
        // one must not overwrite it with a value the store never saw.
        $this->assertNull($auth->consumePendingCookieHeader(), 'straggler must not queue a cookie');
        $this->assertSame(
            hash('sha256', 'rotated-value'),
            $this->tokens->loadTokenBySeries('series1')?->tokenValue,
            'straggler must not rotate the stored value',
        );
    }

    public function testRotationKeepsThePreviousValueAcceptable(): void
    {
        $auth = $this->authenticator();
        $cookie1 = $auth->generateRememberMeCookie($this->userProvider->loadUserByIdentifier('test@example.com'));
        [$series, $value1] = explode(':', (string) base64_decode($cookie1, true), 2);

        $auth->authenticate($this->requestWithCookie($cookie1));
        $auth->consumePendingCookieHeader();

        $stored = $this->tokens->loadTokenBySeries($series);
        $this->assertNotNull($stored);
        $this->assertTrue(
            $stored->acceptsPreviousValue(hash('sha256', $value1), time()),
            'the value just rotated away from stays acceptable for the grace window',
        );
        $this->assertFalse(
            $stored->acceptsPreviousValue(hash('sha256', $value1), time() + 3600),
            'and stops being acceptable once the window closes',
        );
    }

    public function testOnlyOneOfTwoConcurrentRotationsLands(): void
    {
        $this->seed('series1', 'current-value', null, 0);
        $read = $this->tokens->loadTokenBySeries('series1');
        $this->assertNotNull($read);

        $rotation = fn (string $to) => new PersistentToken(
            userIdentifier: $read->userIdentifier,
            series: 'series1',
            tokenValue: hash('sha256', $to),
            lastUsed: time(),
            previousTokenValue: $read->tokenValue,
            previousValueExpiresAt: time() + 60,
        );

        // Both requests read the same record and mint a replacement.
        $this->assertTrue($this->tokens->updateExistingToken($rotation('winner'), $read->tokenValue));
        $this->assertFalse(
            $this->tokens->updateExistingToken($rotation('loser'), $read->tokenValue),
            'the second rotation must be refused, not overwrite the first',
        );

        $this->assertSame(
            hash('sha256', 'winner'),
            $this->tokens->loadTokenBySeries('series1')?->tokenValue,
        );
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

        $rotate = static fn (string $to, string $from) => new PersistentToken(
            userIdentifier: 'a@example.com',
            series: 's1',
            tokenValue: $to,
            lastUsed: 2000,
            previousTokenValue: $from,
            previousValueExpiresAt: 2060,
        );

        $this->assertTrue($provider->updateExistingToken($rotate('hash2', 'hash1'), 'hash1'));
        $rotated = $provider->loadTokenBySeries('s1');
        $this->assertNotNull($rotated);
        $this->assertSame('hash2', $rotated->tokenValue);
        $this->assertSame('hash1', $rotated->previousTokenValue);

        // Compare-and-swap: a rotation from a value that is no longer stored is
        // a request that lost the race, and must not overwrite the winner.
        $this->assertFalse($provider->updateExistingToken($rotate('hash3', 'hash1'), 'hash1'));
        $this->assertSame('hash2', $provider->loadTokenBySeries('s1')?->tokenValue);

        // An unknown series has nothing to swap.
        $this->assertFalse($provider->updateExistingToken(
            new PersistentToken(userIdentifier: 'a@example.com', series: 'ghost', tokenValue: 'hash9', lastUsed: 2000),
            'hash1',
        ));

        $provider->createNewToken(new PersistentToken(userIdentifier: 'a@example.com', series: 's2', tokenValue: 'hashX', lastUsed: 1000));
        $provider->deleteTokensByUserIdentifier('a@example.com');
        $this->assertNull($provider->loadTokenBySeries('s1'));
        $this->assertNull($provider->loadTokenBySeries('s2'));

        @rmdir($dir);
    }
}
