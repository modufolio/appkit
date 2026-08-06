<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Core;

use Modufolio\Appkit\Core\Env;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EnvTest extends TestCase
{
    private Env $env;

    /** @var list<string> */
    private array $keys = [];

    /** @var list<string> */
    private array $files = [];

    private Env $published;

    protected function setUp(): void
    {
        parent::setUp();

        $this->env = new Env();
        // Tests here freeze their own readers, which replaces the process-wide
        // one this suite's bootstrap published; remember it so tearDown can put
        // it back rather than leaking into unrelated tests.
        $this->published = Env::instance();
    }

    protected function tearDown(): void
    {
        foreach ($this->keys as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
        }

        foreach ($this->files as $file) {
            @unlink($file);
        }

        $this->keys = [];
        $this->files = [];
        $this->published->freeze();

        parent::tearDown();
    }

    private function set(string $key, string $value, bool $viaServer = false): void
    {
        $this->keys[] = $key;

        if ($viaServer) {
            $_SERVER[$key] = $value;

            return;
        }

        $_ENV[$key] = $value;
    }

    /**
     * The whole point of the typed reader: a real environment variable is
     * always a string, and the string "false" is truthy, so a plain (bool)
     * cast would turn a disabled flag back on.
     */
    #[DataProvider('booleans')]
    public function testGetBoolCastsTheStringsThatEnvironmentVariablesActuallyCarry(string $raw, bool $expected): void
    {
        $this->set('APPKIT_BOOL', $raw);

        $this->assertSame($expected, $this->env->getBool('APPKIT_BOOL'));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function booleans(): iterable
    {
        yield 'false' => ['false', false];
        yield 'mixed case False' => ['False', false];
        yield 'zero' => ['0', false];
        yield 'off' => ['off', false];
        yield 'no' => ['no', false];
        yield 'true' => ['true', true];
        yield 'uppercase TRUE' => ['TRUE', true];
        yield 'one' => ['1', true];
        yield 'on' => ['on', true];
        yield 'yes' => ['yes', true];
    }

    public function testGetBoolFallsBackToTheDefaultWhenUnset(): void
    {
        $this->assertTrue($this->env->getBool('APPKIT_ABSENT', true));
        $this->assertFalse($this->env->getBool('APPKIT_ABSENT', false));
    }

    public function testGetBoolRejectsAValueThatIsNotBooleanish(): void
    {
        $this->set('APPKIT_BOOL', 'maybe');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot be read as bool');

        $this->env->getBool('APPKIT_BOOL');
    }

    public function testTypedGettersFailLoudlyWhenNothingIsSetAndNoDefaultIsGiven(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is not set and no default was given');

        $this->env->getBool('APPKIT_ABSENT');
    }

    public function testGetIntCastsAndRejectsNonNumericValues(): void
    {
        $this->set('APPKIT_PORT', '3306');
        $this->assertSame(3306, $this->env->getInt('APPKIT_PORT'));
        $this->assertSame(5432, $this->env->getInt('APPKIT_ABSENT', 5432));

        $this->set('APPKIT_NOT_A_PORT', 'abc');
        $this->expectException(\RuntimeException::class);
        $this->env->getInt('APPKIT_NOT_A_PORT');
    }

    public function testGetFloatCasts(): void
    {
        $this->set('APPKIT_RATIO', '0.25');

        $this->assertSame(0.25, $this->env->getFloat('APPKIT_RATIO'));
    }

    public function testGetStringReturnsTheRawValueWithoutBooleanCoercion(): void
    {
        // A password that happens to read as a boolean must survive intact.
        $this->set('APPKIT_SECRET', 'false');

        $this->assertSame('false', $this->env->getString('APPKIT_SECRET'));
    }

    public function testGetRequiredReturnsTheValueOrExplainsWhatIsMissing(): void
    {
        $this->set('APPKIT_SECRET', 's3cret');
        $this->assertSame('s3cret', $this->env->getRequired('APPKIT_SECRET'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('APPKIT_MISSING_SECRET');

        $this->env->getRequired('APPKIT_MISSING_SECRET');
    }

    public function testGetRequiredTreatsAnEmptyValueAsMissing(): void
    {
        $this->set('APPKIT_EMPTY_SECRET', '');

        $this->expectException(\RuntimeException::class);

        $this->env->getRequired('APPKIT_EMPTY_SECRET');
    }

    public function testServerIsConsultedSoFastcgiParamAndSetEnvWork(): void
    {
        $this->set('APPKIT_FROM_SERVER', 'false', viaServer: true);

        $this->assertFalse($this->env->getBool('APPKIT_FROM_SERVER'));
    }

    public function testEnvWinsOverServer(): void
    {
        $this->set('APPKIT_PRECEDENCE', 'from-env');
        $_SERVER['APPKIT_PRECEDENCE'] = 'from-server';

        $this->assertSame('from-env', $this->env->getString('APPKIT_PRECEDENCE'));
    }

    public function testHasReportsPresence(): void
    {
        $this->set('APPKIT_PRESENT', 'x');

        $this->assertTrue($this->env->has('APPKIT_PRESENT'));
        $this->assertFalse($this->env->has('APPKIT_ABSENT'));
    }

    public function testGetKeepsTheLooselyTypedBehaviourTheHelperExposes(): void
    {
        $this->set('APPKIT_FLAG', 'false');

        $this->assertFalse($this->env->get('APPKIT_FLAG'));
        $this->assertSame('fallback', $this->env->get('APPKIT_ABSENT', 'fallback'));
        // A default is returned verbatim, never coerced.
        $this->assertSame('false', $this->env->get('APPKIT_ABSENT', 'false'));
    }

    public function testFromFileReadsAnEnvFileAndStripsTheQuotesIniLeavesBehind(): void
    {
        $path = $this->writeEnvFile("APPKIT_FILE_PLAIN=from-file\nAPPKIT_FILE_QUOTED=\"quoted\"\n");

        $env = (new Env())->fromFile($path);

        $this->assertSame('from-file', $env->getString('APPKIT_FILE_PLAIN'));
        $this->assertSame('quoted', $env->getString('APPKIT_FILE_QUOTED'));
    }

    public function testRealEnvironmentVariablesOutrankTheFile(): void
    {
        $path = $this->writeEnvFile("APPKIT_OVERRIDDEN=from-file\n");
        $this->set('APPKIT_OVERRIDDEN', 'from-env');

        $this->assertSame('from-env', (new Env())->fromFile($path)->getString('APPKIT_OVERRIDDEN'));
    }

    /**
     * Production sets real environment variables and ships no .env at all, so
     * pointing the bootstrap at a path that is not there must not blow up.
     */
    public function testFromFileToleratesAMissingFile(): void
    {
        $env = (new Env())->fromFile('/does/not/exist/.env');

        $this->assertSame('default', $env->getString('APPKIT_ABSENT', 'default'));
    }

    /**
     * parse_ini_file fails the whole file on one bad line, so swallowing the
     * failure would drop every variable and leave the eventual "X is required"
     * error pointing at a secret that is present in the file.
     */
    public function testAMalformedFileFailsLoudlyInsteadOfSilentlyLosingEveryVariable(): void
    {
        // An unquoted newline inside a value — the classic .env mistake.
        $path = $this->writeEnvFile("APPKIT_GOOD=kept\nAPPKIT_BROKEN=\"line1\nline2\"\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($path);

        (new Env())->fromFile($path);
    }

    public function testTheParseErrorNamesTheOffendingLine(): void
    {
        $path = $this->writeEnvFile("APPKIT_GOOD=kept\nAPPKIT_BROKEN=\"line1\nline2\"\n");

        try {
            (new Env())->fromFile($path);
            $this->fail('Expected a parse failure.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('line 3', $e->getMessage());
        }
    }

    /**
     * `export FOO=bar` is valid in a .env that also gets sourced by a shell,
     * and INI would otherwise register the key as the literal "export FOO".
     */
    public function testTheExportPrefixIsStripped(): void
    {
        $path = $this->writeEnvFile("export APPKIT_EXPORTED=yes\nAPPKIT_PLAIN=no\n");

        $env = (new Env())->fromFile($path);

        $this->assertSame('yes', $env->getString('APPKIT_EXPORTED'));
        $this->assertSame('fallback', $env->getString('export APPKIT_EXPORTED', 'fallback'));
        $this->assertSame('no', $env->getString('APPKIT_PLAIN'));
    }

    public function testLaterFilesWinOverEarlierOnes(): void
    {
        $base = $this->writeEnvFile("APPKIT_LAYERED=base\nAPPKIT_ONLY_BASE=kept\n");
        $local = $this->writeEnvFile("APPKIT_LAYERED=local\n");

        $env = (new Env())->fromFile($base)->fromFile($local);

        $this->assertSame('local', $env->getString('APPKIT_LAYERED'));
        $this->assertSame('kept', $env->getString('APPKIT_ONLY_BASE'));
    }

    public function testFreezingRejectsFurtherLoading(): void
    {
        $env = (new Env())->fromArray(['APPKIT_SEALED' => 'yes'])->freeze();

        $this->assertTrue($env->isFrozen());
        $this->assertSame('yes', $env->getString('APPKIT_SEALED'));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('frozen');

        $env->fromArray(['APPKIT_SEALED' => 'no']);
    }

    public function testFreezingPublishesTheReaderProcessWide(): void
    {
        $env = (new Env())->fromArray(['APPKIT_PUBLISHED' => 'from-frozen'])->freeze();

        $this->assertSame($env, Env::instance());
        $this->assertSame('from-frozen', env('APPKIT_PUBLISHED'));
    }

    /**
     * A process that never ran a bootstrap still has to resolve variables.
     */
    public function testInstanceFallsBackToAnEmptyFrozenReader(): void
    {
        Env::reset();

        $this->assertTrue(Env::instance()->isFrozen());
        $this->assertSame('fallback', Env::instance()->getString('APPKIT_ABSENT', 'fallback'));
    }

    private function writeEnvFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'appkit-env-');
        self::assertIsString($path);
        file_put_contents($path, $contents);

        $this->files[] = $path;

        return $path;
    }
}
