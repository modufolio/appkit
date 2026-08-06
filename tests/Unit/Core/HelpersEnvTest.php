<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Core;

use Modufolio\Appkit\Core\Env;
use PHPUnit\Framework\TestCase;

/**
 * env() must work in a process that never ran the bootstrap — a one-off CLI
 * script, a worker entry point, a test harness. No Env was published there, so
 * the helper has to fall back to a plain $_ENV / $_SERVER / default lookup
 * rather than erroring. Exercised in a separate PHP process, since this suite's
 * own bootstrap does publish one.
 */
class HelpersEnvTest extends TestCase
{
    public function testEnvWorksWithoutABootstrappedEnvironment(): void
    {
        // Loaded the way an application does, via Composer's `files` autoload
        // entry, so env()'s Env dependency resolves — but nothing calls
        // freeze(), so no instance is published.
        $autoloader = dirname(__DIR__, 3).'/vendor/autoload.php';

        $code = sprintf(
            'require %s; $_ENV["HELPERS_ENV_TEST"] = "from-env"; '
            .'echo env("HELPERS_ENV_TEST"), "|", env("HELPERS_ENV_MISSING", "fallback");',
            var_export($autoloader, true),
        );

        $process = proc_open(
            [PHP_BINARY, '-d', 'error_reporting=E_ALL', '-r', $code],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        $this->assertIsResource($process);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        $exitCode = proc_close($process);

        $this->assertSame(0, $exitCode, 'env() without a bootstrap must not be fatal: '.$stderr);
        $this->assertSame('from-env|fallback', $stdout);
    }

    public function testEnvReadsTheEnvironmentWhenBootstrapped(): void
    {
        $_ENV['HELPERS_ENV_DEFINED_TEST'] = 'value';

        try {
            $this->assertSame('value', env('HELPERS_ENV_DEFINED_TEST'));
            $this->assertSame('default', env('HELPERS_ENV_DEFINED_MISSING', 'default'));
        } finally {
            unset($_ENV['HELPERS_ENV_DEFINED_TEST']);
        }
    }

    /**
     * Value casting itself is covered by EnvTest; this pins the helper's own
     * contract, which config files depend on.
     */
    public function testEnvCastsBooleanStringsSoFlagsAreNotSilentlyTruthy(): void
    {
        $_ENV['HELPERS_ENV_BOOL_TEST'] = 'false';

        try {
            $this->assertFalse(env('HELPERS_ENV_BOOL_TEST'));
        } finally {
            unset($_ENV['HELPERS_ENV_BOOL_TEST']);
        }
    }

    public function testEnvWithoutAKeyReturnsTheReaderForTypedAccess(): void
    {
        $_ENV['HELPERS_ENV_TYPED'] = 'false';

        try {
            $this->assertInstanceOf(Env::class, env());
            $this->assertFalse(env()->getBool('HELPERS_ENV_TYPED'));
            $this->assertSame('false', env()->getString('HELPERS_ENV_TYPED'));
        } finally {
            unset($_ENV['HELPERS_ENV_TYPED']);
        }
    }
}
