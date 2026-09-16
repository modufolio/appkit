# Rate limiting

AppKit throttles through [symfony/rate-limiter](https://symfony.com/doc/current/rate_limiter.html):
a limiter is declared once, by name, and used in two places the kernel
owns — a firewall's login throttle and a route's `#[RateLimit]` — and in
any place of your own. A client past the limit is answered `429 Too Many
Requests` with a `Retry-After` header, and a
[`RateLimitExceededEvent`](../events.md) goes out first.

## Declaring limiters

```php
// config/security.php
$security
    ->rateLimiter('login', [
        'policy'   => 'sliding_window',
        'limit'    => 5,
        'interval' => '15 minutes',
    ])
    ->rateLimiter('api', [
        'policy'   => 'token_bucket',
        'limit'    => 100,
        'rate'     => ['interval' => '1 minute', 'amount' => 100],
    ]);
```

The options are symfony/rate-limiter's own and are validated when the
limiter is first built: `policy` is `fixed_window`, `sliding_window`,
`token_bucket` or `no_limit`; `limit` and `interval` size the window; a
token bucket refills at `rate`. The component's documentation covers what
each policy trades off.

They are the keys of Symfony's `framework.rate_limiter`, unchanged — the
name becomes the limiter's `id`, `interval` takes the same relative-date
strings (`'15 minutes'`, `'1 hour 30 minutes'`), and a bad option is
refused by the component's own resolver with the component's own message.
A Symfony configuration ports across as-is:

```yaml
# Symfony
framework:
    rate_limiter:
        api:
            policy: 'token_bucket'
            limit: 100
            rate: { interval: '1 minute', amount: 100 }
```

## The login throttle

```php
$security->firewall('main', [
    'pattern'        => '/',
    'authenticators' => ['form_login'],
    'entry_point'    => '/login',
    'rate_limit'     => 'login',
]);
```

With `rate_limit` set, every credential presented to the firewall counts
against that limiter, per client address, *before* the credential is read.
A spent window refuses a correct password as readily as a wrong one, which
is the point: the attacker's guesses and the owner's attempt are the same
request. Not counted: an anonymous visitor browsing public pages (nothing is
presented), a signed-in user (the session is restored before the
authenticators run), and a remember-me cookie (signed, not guessable).

This complements the account-based `BruteForceProtectionInterface`, it does
not replace it. The throttle is by address and stops a burst before any
lookup happens; the brute-force store is by account and survives an
attacker rotating addresses. Use both on a public login.

## Route limits

```php
use Modufolio\Appkit\Attributes\RateLimit;

#[RateLimit('api')]
final class SearchController
{
    #[Route('/api/search', methods: ['GET'])]
    #[RateLimit('expensive', by: RateLimit::BY_IP)]
    public function search(): ResponseInterface
```

Every `#[RateLimit]` on a route applies: the class-level one for the
controller, the method-level one on top. `by` says what one client is:

| `by` | Counts per |
|------|------------|
| `RateLimit::BY_AUTO` (default) | the signed-in user, or the client address for an anonymous request |
| `RateLimit::BY_USER` | the signed-in user; anonymous falls back to the address |
| `RateLimit::BY_IP` | the client address, signed in or not |

Route limits run after access control, so a request that is not allowed in
is refused for that reason and does not spend the caller's window.

The address is `REMOTE_ADDR` as the server reported it — the same value
firewall selection and `ips` rules trust. Behind a proxy, resolve the real
client address in your own `handle()` or at the edge; the kernel does not
read forwarded headers.

## Your own limits

`$app->rateLimiter('name')` is the component's `RateLimiterFactory`;
`create($key)` gives the limiter for one client and `consume()` decides:

```php
$limit = $this->app->rateLimiter('password_reset')->create('email:'.$email)->consume();

if (!$limit->isAccepted()) {
    throw new RateLimitExceededException('password_reset', $limit->getRetryAfter(), $limit->getLimit());
}
```

Throwing `RateLimitExceededException` gets the same 429 with `Retry-After`
the kernel produces.

## Storage and locks

Windows are counted in a cache pool on disk under
`var/cache/<env>/rate_limiter`, serialised through a flock under
`var/lock`. That is correct for one server. Behind a load balancer each
server would admit the full limit, so declare a shared storage and a shared
lock together:

```php
// config/services.php
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\RedisStore;
use Symfony\Component\RateLimiter\Storage\CacheStorage;
use Symfony\Component\RateLimiter\Storage\StorageInterface;

$services
    ->set(StorageInterface::class, fn () => new CacheStorage(new RedisAdapter($redis)))
    ->set(LockFactory::class, fn () => new LockFactory(new RedisStore($redis)));
```

The kernel builds each once per worker. In tests, `setRateLimiterStorage(new
InMemoryStorage())` gives every case a fresh window.

## Responses

The exception handler maps `RateLimitExceededException` to 429 in every
format it renders, with `Retry-After` (seconds) and `RateLimit-Limit` (the
window's size) headers. Register your own handler for the class to change
the body; keep the headers.
