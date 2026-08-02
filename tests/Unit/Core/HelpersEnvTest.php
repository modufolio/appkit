<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;

/**
 * env() must work without the BASE_DIR constant: a one-off CLI script, a
 * worker bootstrap, or a test harness that never defines it should get a
 * normal $_ENV / $_SERVER / default lookup, not a fatal undefined-constant
 * error. BASE_DIR is defined by this suite's bootstrap, so the guard is
 * exercised in a separate PHP process.
 */
class HelpersEnvTest extends TestCase
{
    public function testEnvDoesNotRequireBaseDir(): void
    {
        $helpers = dirname(__DIR__, 3).'/src/helpers.php';

        $code = sprintf(
            'require %s; $_ENV["HELPERS_ENV_TEST"] = "from-env"; '
            .'echo env("HELPERS_ENV_TEST"), "|", env("HELPERS_ENV_MISSING", "fallback");',
            var_export($helpers, true),
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

        $this->assertSame(0, $exitCode, 'env() without BASE_DIR must not be fatal: '.$stderr);
        $this->assertSame('from-env|fallback', $stdout);
    }

    public function testEnvStillReadsTheEnvironmentWhenBaseDirIsDefined(): void
    {
        $_ENV['HELPERS_ENV_DEFINED_TEST'] = 'value';

        try {
            $this->assertSame('value', env('HELPERS_ENV_DEFINED_TEST'));
            $this->assertSame('default', env('HELPERS_ENV_DEFINED_MISSING', 'default'));
        } finally {
            unset($_ENV['HELPERS_ENV_DEFINED_TEST']);
        }
    }
}
