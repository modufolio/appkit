<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Core;

/**
 * Typed reader for environment variables.
 *
 * Distinct from {@see Environment}, which is the dev/test/prod enum: this class
 * is about reading raw configuration values out of the environment.
 *
 * The bootstrap builds one reader, loads whatever files it needs, and seals it:
 *
 *     (new Env())->fromFile(__DIR__ . '/.env')->freeze();
 *
 * freeze() publishes it process-wide, so env() and Env::instance() return it
 * from anywhere. Lookup order is $_ENV, then $_SERVER (where fastcgi_param and
 * SetEnv land), then the loaded files — a real environment variable always
 * outranks a file.
 *
 * Every value arriving from any of those sources is a string — "false", "0" and
 * "3306" all come through as text — so the typed getters below do the casting
 * that callers would otherwise hand-roll, in the spirit of Symfony's env var
 * processors (%env(bool:FOO)%, %env(int:FOO)%).
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
class Env
{
    private static ?self $instance = null;

    /**
     * Values read from .env files, lowest precedence.
     *
     * @var array<string, string>
     */
    private array $file = [];

    private bool $frozen = false;

    /**
     * The process-wide reader published by the bootstrap.
     *
     * Falls back to an empty frozen instance — one that sees $_ENV and $_SERVER
     * but no .env file — so a one-off CLI script or a test harness that never
     * ran the bootstrap still gets a working lookup instead of an error.
     */
    public static function instance(): self
    {
        return self::$instance ??= (new self())->freeze();
    }

    /**
     * Forget the published instance. For tests only.
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Merge a .env file into the reader, lowest precedence.
     *
     * A missing file is not an error: production deployments set real
     * environment variables and ship no .env at all.
     *
     * @throws \LogicException once the reader is frozen
     */
    public function fromFile(string $path): static
    {
        return $this->fromArray($this->parse($path));
    }

    /**
     * @param array<string, string> $values
     *
     * @throws \LogicException once the reader is frozen
     */
    public function fromArray(array $values): static
    {
        if ($this->frozen) {
            throw new \LogicException('The environment is frozen and can no longer be modified. Load every .env file before calling freeze().');
        }

        $this->file = array_merge($this->file, $values);

        return $this;
    }

    /**
     * Seal the reader and publish it process-wide, so env() and
     * Env::instance() return it from anywhere.
     *
     * Configuration is read on nearly every request; freezing means nothing can
     * quietly swap a value out from under code that already read it.
     */
    public function freeze(): static
    {
        $this->frozen = true;
        self::$instance = $this;

        return $this;
    }

    public function isFrozen(): bool
    {
        return $this->frozen;
    }

    /**
     * Read a value, casting the strings "true" and "false" to real booleans.
     *
     * This is the loosely-typed entry point kept for the env() helper; prefer
     * the typed getters when the shape of the value is known.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->raw($key);

        if (null === $value) {
            return $default;
        }

        return match (strtolower($value)) {
            'true' => true,
            'false' => false,
            default => $value,
        };
    }

    public function has(string $key): bool
    {
        return null !== $this->raw($key);
    }

    /**
     * Read a value that the application cannot run without.
     *
     * Replaces the `if (empty($_ENV['X'])) { throw ... }` preamble that config
     * files would otherwise repeat around every secret.
     *
     * @throws \RuntimeException when the variable is missing or empty
     */
    public function getRequired(string $key): string
    {
        $value = $this->raw($key);

        if (null === $value || '' === $value) {
            throw new \RuntimeException(sprintf('Environment variable "%s" is required but is not set. Add it to your .env file.', $key));
        }

        return $value;
    }

    public function getString(string $key, ?string $default = null): string
    {
        $value = $this->raw($key);

        return null === $value ? $this->orFail($key, $default, 'string') : $value;
    }

    /**
     * "true"/"1"/"on"/"yes" are true, "false"/"0"/"off"/"no"/"" are false.
     *
     * Uses filter_var's boolean rules rather than a bare (bool) cast, which
     * would read the string "false" as true and quietly turn a disabled flag
     * back on — the failure mode that matters for things like COOKIE_SECURE.
     */
    public function getBool(string $key, ?bool $default = null): bool
    {
        $value = $this->raw($key);

        if (null === $value) {
            return $this->orFail($key, $default, 'bool');
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)
            ?? throw $this->invalid($key, $value, 'bool');
    }

    public function getInt(string $key, ?int $default = null): int
    {
        $value = $this->raw($key);

        if (null === $value) {
            return $this->orFail($key, $default, 'int');
        }

        return filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE)
            ?? throw $this->invalid($key, $value, 'int');
    }

    public function getFloat(string $key, ?float $default = null): float
    {
        $value = $this->raw($key);

        if (null === $value) {
            return $this->orFail($key, $default, 'float');
        }

        return filter_var($value, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE)
            ?? throw $this->invalid($key, $value, 'float');
    }

    /**
     * The untouched string behind a key, or null when it is set nowhere.
     */
    private function raw(string $key): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? $this->file[$key] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * @return array<string, string>
     *
     * @throws \RuntimeException when the file exists but cannot be parsed
     */
    private function parse(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        // parse_ini_file reports syntax errors as a warning and then returns
        // false for the *whole file*, so one bad line would otherwise take every
        // variable with it — silently, leaving getRequired() to blame a secret
        // that is sitting right there in the file. Capture the warning instead;
        // it carries the offending line number.
        $warning = null;
        set_error_handler(static function (int $type, string $message) use (&$warning): bool {
            $warning ??= $message;

            return true;
        });

        try {
            $parsed = parse_ini_file($path, false, INI_SCANNER_RAW);
        } finally {
            restore_error_handler();
        }

        if (false === $parsed) {
            throw new \RuntimeException(sprintf('Failed to parse the environment file "%s"%s. Note that values containing spaces or newlines must be quoted.', $path, null === $warning ? '' : ': '.$warning));
        }

        $values = [];

        foreach ($parsed as $key => $value) {
            // `export FOO=bar` is valid in a shell-sourced .env, but INI reads
            // the whole `export FOO` as the key. Drop the prefix rather than
            // silently registering a variable nobody can look up.
            $key = preg_replace('/^export[ \t]++/', '', (string) $key) ?? (string) $key;

            // INI_SCANNER_RAW keeps the surrounding quotes; strip them so the
            // value matches what the same setting would look like as a real
            // environment variable.
            $values[$key] = trim((string) $value, '"');
        }

        return $values;
    }

    /**
     * Fall back to the caller's default, or fail when they did not give one.
     *
     * @template T
     *
     * @param T|null $default
     *
     * @return T
     */
    private function orFail(string $key, mixed $default, string $type): mixed
    {
        if (null === $default) {
            throw new \RuntimeException(sprintf('Environment variable "%s" is not set and no default was given for %s.', $key, $type));
        }

        return $default;
    }

    private function invalid(string $key, string $value, string $type): \RuntimeException
    {
        return new \RuntimeException(sprintf('Environment variable "%s" cannot be read as %s: %s given.', $key, $type, var_export($value, true)));
    }
}
