<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\AccessControl;

use Modufolio\Appkit\Security\SecurityConfigurator;

/**
 * A validated access-control rule.
 *
 * Built once at engine construction from the plain config arrays that
 * SecurityConfigurator collects, so a malformed rule (a `roles` string
 * instead of a list, a non-string pattern) fails loudly at boot instead of
 * being silently skipped at enforcement time — a skipped rule fails open.
 *
 * Keys not understood here are preserved in $extra for custom constraints
 * registered on the AccessDecisionEngine.
 */
final class AccessRule
{
    /**
     * @param list<string>         $roles
     * @param list<string>         $methods uppercased
     * @param list<string>         $ips     IPs or CIDR ranges
     * @param array<string, mixed> $extra   unrecognized keys, for custom constraints
     */
    private function __construct(
        public readonly string $path,
        public readonly array $roles,
        public readonly array $methods,
        public readonly ?string $firewall,
        public readonly ?string $requiresChannel,
        public readonly array $ips,
        public readonly array $extra,
    ) {
    }

    /**
     * @param array<string, mixed> $rule
     */
    public static function fromArray(array $rule): self
    {
        $path = $rule['path'] ?? '/';
        if (!is_string($path) || '' === $path) {
            throw new \InvalidArgumentException('Access-control rule "path" must be a non-empty string.');
        }

        $firewall = $rule['firewall'] ?? null;
        if (null !== $firewall && !is_string($firewall)) {
            throw new \InvalidArgumentException(sprintf('Access-control rule "firewall" for path "%s" must be a string.', $path));
        }

        $channel = $rule['requires_channel'] ?? null;
        if (null !== $channel && !is_string($channel)) {
            throw new \InvalidArgumentException(sprintf('Access-control rule "requires_channel" for path "%s" must be a string.', $path));
        }

        return new self(
            $path,
            self::stringList($rule, 'roles', $path),
            array_map('strtoupper', self::stringList($rule, 'methods', $path)),
            $firewall,
            $channel,
            self::stringList($rule, 'ips', $path),
            array_diff_key($rule, array_flip(['path', 'roles', 'methods', 'firewall', 'requires_channel', 'ips'])),
        );
    }

    public function isPublic(): bool
    {
        return in_array(SecurityConfigurator::PUBLIC_ACCESS, $this->roles, true);
    }

    public function matchesPath(string $path): bool
    {
        return RequestMatcher::matches($this->path, $path);
    }

    public function matchesMethod(string $method): bool
    {
        return [] === $this->methods || in_array(strtoupper($method), $this->methods, true);
    }

    /**
     * @param array<string, mixed> $rule
     *
     * @return list<string>
     */
    private static function stringList(array $rule, string $key, string $path): array
    {
        $value = $rule[$key] ?? [];

        if (!is_array($value)) {
            throw new \InvalidArgumentException(sprintf('Access-control rule "%s" for path "%s" must be a list of strings.', $key, $path));
        }

        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new \InvalidArgumentException(sprintf('Access-control rule "%s" for path "%s" must contain only strings.', $key, $path));
            }
        }

        return array_values($value);
    }
}
