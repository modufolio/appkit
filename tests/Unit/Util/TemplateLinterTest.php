<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Util;

use Modufolio\Appkit\Exception\RuntimeCommandException;
use Modufolio\Appkit\Util\TemplateLinter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

#[CoversClass(TemplateLinter::class)]
class TemplateLinterTest extends TestCase
{
    private string $tmp;
    private string $fixerBinary;
    private string $config;

    public function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/appkit-linter-'.uniqid();
        mkdir($this->tmp, 0o777, true);

        $this->fixerBinary = dirname(__DIR__, 3).'/vendor/bin/php-cs-fixer';
        if (!is_file($this->fixerBinary)) {
            $this->markTestSkipped('php-cs-fixer binary not available');
        }

        $this->config = $this->tmp.'/fixer-config.php';
        file_put_contents(
            $this->config,
            "<?php\nreturn (new PhpCsFixer\\Config())->setUnsupportedPhpVersionAllowed(true)->setRules(['array_syntax' => ['syntax' => 'short']]);\n"
        );
    }

    public function tearDown(): void
    {
        foreach (glob($this->tmp.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->tmp);
    }

    public function testLintFilesFixesPhpFiles(): void
    {
        $file = $this->tmp.'/Messy.php';
        file_put_contents($file, "<?php\nclass Messy {\npublic function a(  ) {\nreturn array( );\n}\n}\n");
        $before = file_get_contents($file);

        $linter = new TemplateLinter($this->fixerBinary, $this->config);
        $linter->lintFiles([$file, $this->tmp.'/ignored.txt']);

        $this->assertNotSame($before, file_get_contents($file));
        $this->assertStringContainsString('return [', file_get_contents($file));
    }

    public function testLintPhpTemplateAcceptsSingleString(): void
    {
        $file = $this->tmp.'/Single.php';
        file_put_contents($file, "<?php\n\$a = array( );\n");

        (new TemplateLinter($this->fixerBinary, $this->config))->lintPhpTemplate($file);

        $this->assertStringContainsString('$a = [', file_get_contents($file));
    }

    public function testWriteLinterMessageWithSystemFixer(): void
    {
        $output = new BufferedOutput();

        (new TemplateLinter($this->fixerBinary))->writeLinterMessage($output);

        $display = $output->fetch();
        $this->assertStringContainsString('Linting Generated Files With:', $display);
        $this->assertStringContainsString('System PHP-CS-Fixer', $display);
        $this->assertStringContainsString('Bundled PHP-CS-Fixer Configuration', $display);
    }

    public function testWriteLinterMessageWithBundledFixer(): void
    {
        $output = new BufferedOutput();

        (new TemplateLinter())->writeLinterMessage($output);

        $display = $output->fetch();
        $this->assertStringContainsString('Bundled PHP-CS-Fixer &', $display);
    }

    public function testBinaryFoundInSystemPath(): void
    {
        // "php" definitely exists in the PATH; the linter accepts it as binary
        $output = new BufferedOutput();

        (new TemplateLinter('php'))->writeLinterMessage($output);

        $this->assertStringContainsString('System PHP-CS-Fixer', $output->fetch());
    }

    public function testMissingBinaryThrows(): void
    {
        $this->expectException(RuntimeCommandException::class);
        $this->expectExceptionMessage('MAKER_PHP_CS_FIXER_BINARY_PATH');

        new TemplateLinter('/does/not/exist/php-cs-fixer-nowhere');
    }

    public function testCustomConfigPath(): void
    {
        $file = $this->tmp.'/WithConfig.php';
        file_put_contents($file, "<?php\n\$a = array( );\n");

        $linter = new TemplateLinter($this->fixerBinary, $this->config);
        $linter->lintPhpTemplate($file);

        $this->assertStringContainsString('$a = [', file_get_contents($file));

        $output = new BufferedOutput();
        $linter->writeLinterMessage($output);
        $this->assertStringContainsString('System PHP-CS-Fixer Configuration', $output->fetch());
    }

    public function testMissingConfigThrows(): void
    {
        $this->expectException(RuntimeCommandException::class);
        $this->expectExceptionMessage('MAKER_PHP_CS_FIXER_CONFIG_PATH');

        new TemplateLinter($this->fixerBinary, '/does/not/exist/config.php');
    }
}
