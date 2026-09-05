<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Http;

use Modufolio\Appkit\Exception\UntrustedHostException;

/**
 * Allowlist of hostnames a request may claim in its Host header.
 *
 * The Host header is attacker-controlled. Anything that copies it into an
 * absolute URL — the kernel's base URL, template `url()` helpers, the router's
 * request context, the https upgrade redirect — will happily emit
 * `https://attacker.example/...` unless the host is checked first. A poisoned
 * password-reset link mailed to a victim is the classic outcome.
 *
 * The kernel checks the incoming host against this list before request state
 * is constructed (see Kernel::createState() and handleAuthentication()), so
 * everything downstream can trust `$request->getUri()->getHost()`.
 *
 * Entries mirror Symfony's `framework.trusted_hosts` /
 * `Request::setTrustedHosts()` so a list moves between the two unchanged:
 *
 *   - a regular expression, exactly as Symfony takes it: `^(.+\.)?example\.com$`.
 *     Compiled as `{pattern}i` like Symfony, and like Symfony *not* anchored
 *     for you — write `^...$`, or `example\.com` also matches
 *     `example.com.attacker.test`. Anything containing a regex metacharacter
 *     other than "." is treated as a pattern.
 *   - an exact hostname: `example.com` (shorthand for `^example\.com$`).
 *   - a subdomain wildcard: `*.example.com` (shorthand for `^.+\.example\.com$`)
 *     — matches `www.example.com` and `a.b.example.com`, but not the apex.
 *
 * toSymfonyPatterns() renders the whole list as regexes, ready for
 * `Request::setTrustedHosts()` or a `framework.trusted_hosts` entry.
 *
 * Matching normalises the host the way Symfony's Request::getHost() does:
 * trimmed, lower-cased, a trailing `:port` removed. A host that is not even
 * syntactically valid (Symfony's isHostValid rule: hostname characters, an
 * IPv4 address, or a bracketed IPv6 literal) is rejected whether or not a
 * list is configured, as Symfony does. A request with no host at all is
 * accepted: it produces path-only URLs, so there is nothing to poison.
 *
 * An empty list accepts any (valid) host, which is only safe behind a proxy
 * or load balancer that pins the Host header itself.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
final class TrustedHosts
{
    /** @var list<string> entries as configured, normalised */
    private array $entries = [];

    /** @var list<string> compiled `{pattern}i` regexes, one per entry, in order */
    private array $patterns = [];

    /** @var list<string> the Symfony-style regex body of each entry, in order */
    private array $symfonyPatterns = [];

    /**
     * @param iterable<mixed> $hosts
     */
    public function __construct(iterable $hosts = [])
    {
        foreach ($hosts as $host) {
            if (!is_string($host) || '' === trim($host)) {
                throw new \InvalidArgumentException('Every trusted host must be a non-empty string; got '.get_debug_type($host).'.');
            }

            $host = trim($host);

            // A URL is a common slip; as a regex it could never match a host,
            // so fail loudly rather than silently trusting nothing.
            if (str_contains($host, '://')) {
                throw new \InvalidArgumentException(sprintf('Invalid trusted host "%s": give a hostname or pattern, not a URL.', $host));
            }

            if (self::looksLikeRegex($host)) {
                $this->add($host, $host);
                continue;
            }

            $host = strtolower($host);

            if (str_starts_with($host, '*.')) {
                $suffix = substr($host, 2); // "example.com"
                if ('' === $suffix || !self::isValid($suffix)) {
                    throw new \InvalidArgumentException(sprintf('Invalid trusted host wildcard "%s": expected "*.example.com".', $host));
                }
                $this->add($host, '^.+\.'.preg_quote($suffix, '{').'$');
                continue;
            }

            if (!self::isValid($host)) {
                throw new \InvalidArgumentException(sprintf('Invalid trusted host "%s": expected a hostname such as "example.com", a "*.example.com" wildcard, or a regular expression such as "^(.+\.)?example\.com$".', $host));
            }

            $this->add($host, '^'.preg_quote($host, '{').'$');
        }
    }

    /**
     * True when no hosts are configured, i.e. every valid host is accepted.
     */
    public function isEmpty(): bool
    {
        return [] === $this->entries;
    }

    public function allows(string $host): bool
    {
        $host = self::normalize($host);

        // A request without a host (relative URI: console, tests, a missing
        // Host header) yields an empty base URL and path-only links, so there
        // is nothing to poison. Rejecting it would only break those callers.
        if ('' === $host) {
            return true;
        }

        if (!self::isValid($host)) {
            return false;
        }

        if ($this->isEmpty()) {
            return true;
        }

        foreach ($this->patterns as $pattern) {
            if (1 === preg_match($pattern, $host)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws UntrustedHostException when the host is invalid or not on the allowlist
     */
    public function assert(string $host): void
    {
        if (!$this->allows($host)) {
            throw new UntrustedHostException($host);
        }
    }

    /**
     * @return list<string> the configured entries, normalised (hostnames lower-cased, regexes untouched)
     */
    public function toArray(): array
    {
        return $this->entries;
    }

    /**
     * The list as Symfony regex patterns: pass straight to
     * `Request::setTrustedHosts()` or paste into `framework.trusted_hosts`.
     *
     * @return list<string>
     */
    public function toSymfonyPatterns(): array
    {
        return $this->symfonyPatterns;
    }

    /**
     * Symfony's Request::isHostValid(): hostname characters, an IPv4
     * address, or a bracketed IPv6 literal.
     */
    public static function isValid(string $host): bool
    {
        if ('' === $host) {
            return false;
        }

        if ('[' === $host[0]) {
            return ']' === $host[-1] && false !== filter_var(substr($host, 1, -1), \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6);
        }

        if (preg_match('/\.[0-9]++\.?$/D', $host)) {
            return null !== filter_var($host, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4 | \FILTER_NULL_ON_FAILURE);
        }

        // preg_replace() rather than preg_match() to prevent DoS with long host names (as in Symfony)
        return '' === preg_replace('/[-a-zA-Z0-9_]++\.?/', '', $host);
    }

    private function add(string $entry, string $symfonyPattern): void
    {
        $compiled = sprintf('{%s}i', $symfonyPattern);

        if (false === @preg_match($compiled, '')) {
            throw new \InvalidArgumentException(sprintf('Invalid trusted host pattern "%s": %s.', $entry, preg_last_error_msg()));
        }

        $this->entries[] = $entry;
        $this->symfonyPatterns[] = $symfonyPattern;
        $this->patterns[] = $compiled;
    }

    /**
     * Symfony's Request::getHost() normalisation: trim, drop a trailing
     * port, lower-case (RFC 952/2181).
     */
    private static function normalize(string $host): string
    {
        return strtolower(preg_replace('/:\d+$/', '', trim($host)) ?? $host);
    }

    /**
     * A hostname only ever contains letters, digits, "-", "_", "." and, for
     * IPv6 literals, "[", "]" and ":". Anything else marks a regex.
     */
    private static function looksLikeRegex(string $entry): bool
    {
        return 1 === preg_match('/[^A-Za-z0-9\-_.\[\]:*]/', $entry) || str_starts_with($entry, '^');
    }
}
