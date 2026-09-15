# Trusted hosts

The `Host` header is attacker-controlled, and several things copy it into absolute URLs: the kernel's `baseUrl()`/`url()`, the template `url()` helper, `generateUrl(..., ABSOLUTE_URL)` and the `requires_channel` https redirect. Without a check, a request carrying `Host: attacker.example` makes the application emit `https://attacker.example/...` — the classic way to poison a password-reset link that is then mailed to the victim.

Declare the hostnames the application answers for with the `trusted_hosts` router option. It takes exactly what Symfony's `framework.trusted_hosts` / `Request::setTrustedHosts()` takes — a list of regular expressions — plus two shorthands:

```php
$app->setRouterOptions([
    'trusted_hosts' => [
        '^(.+\.)?example\.com$',   // a Symfony pattern, unchanged
        'example.org',              // shorthand for '^example\.org$'
        '*.example.org',            // shorthand for '^.+\.example\.org$' (subdomains, not the apex)
    ],
]);
```

Patterns are compiled the way Symfony compiles them (`{pattern}i`, case-insensitive) and, like Symfony, are **not** anchored for you: write `^...$`, or `example\.com` also matches `example.com.attacker.test`. The host is normalised as `Request::getHost()` does — trimmed, lower-cased, trailing `:port` dropped — and a host that is not even syntactically valid (Symfony's `isHostValid` rule) is refused whether or not a list is configured. Call `setRouterOptions()` wherever you configure the kernel — before or after `boot()` both work, because `boot()` only sets its own keys. Reading the list from the environment keeps it out of the code:

```php
$app->setRouterOptions([
    'trusted_hosts' => array_filter(array_map('trim', explode(',', env('TRUSTED_HOSTS', '')))),
]);
```

**Moving to or from Symfony.** A Symfony `trusted_hosts` list drops into this option as-is. In the other direction, `$app->trustedHosts()->toSymfonyPatterns()` renders every entry (shorthands included) as an anchored regex you can paste into `framework.yaml` or hand to `Request::setTrustedHosts()`:

```yaml
# config/packages/framework.yaml
framework:
    trusted_hosts: ['^example\.org$', '^.+\.example\.org$']
```

Once the list is non-empty, a request whose host is not on it is answered with **400 Bad Request** — from `Kernel::createState()`, before any request state (and therefore any base URL) exists, and again at the top of `handleAuthentication()` as a backstop, so a `handle()` that still builds `ApplicationState` by hand is covered too. The router applies the same list when used on its own. Because the check runs before access control, the https-upgrade redirect can never point at an untrusted host either. The rejected host is logged but not echoed in the response.

Use `createState()` in your `handle()` rather than constructing the state directly:

```php
public function handle(ServerRequestInterface $request): ResponseInterface
{
    $this->state?->reset();

    try {
        $this->state = $this->createState($request);
        $response = $this->handleAuthentication($request);
    } catch (\Throwable $e) {
        $response = $this->exceptionHandler()->handle($e, $request);
    }

    return $this->prepareResponse()->prepare($request, $response);
}
```

An empty list (the default) accepts any syntactically valid host. That is only safe when a reverse proxy or load balancer in front of PHP pins the `Host` header itself; if the application is reachable directly, or the proxy forwards whatever the client sent, configure the list. A request with no host at all (a relative URI, as in console commands and the test client) is always accepted: it produces path-only URLs, so there is nothing to poison. Only the `Host` header is checked — a proxy's `X-Forwarded-Host` is never consulted.

