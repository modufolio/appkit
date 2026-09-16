<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Command;

use Modufolio\Appkit\Command\AssetsSriCommand;
use Modufolio\Appkit\Template\Asset\AssetIntegrity;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class AssetsSriCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/appkit_sri_'.uniqid();
        mkdir($this->dir.'/public/assets/js', 0o777, true);
        mkdir($this->dir.'/config', 0o777, true);
        file_put_contents($this->dir.'/public/assets/js/app.js', 'console.log(1)');
        file_put_contents($this->dir.'/public/assets/js/app.'.str_repeat('0', 32).'.js', 'console.log(1)');
        file_put_contents($this->dir.'/public/assets/js/notes.txt', 'ignored');
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->dir);
    }

    public function testWritesAMapTheTemplatesCanRead(): void
    {
        $tester = new CommandTester(new AssetsSriCommand($this->dir.'/public', $this->dir.'/config/sri.php'));

        $this->assertSame(0, $tester->execute([]));

        $integrity = AssetIntegrity::fromFile($this->dir.'/config/sri.php');
        $expected = 'sha384-'.base64_encode(hash('sha384', 'console.log(1)', true));

        $this->assertSame($expected, $integrity->for('/assets/js/app.js'));
        $this->assertCount(1, $integrity->all(), 'The hash-named copy and the .txt are skipped');
        $this->assertStringContainsString('1 asset(s) hashed with sha384', $tester->getDisplay());
    }

    public function testAlgorithmIsSelectable(): void
    {
        $tester = new CommandTester(new AssetsSriCommand($this->dir.'/public', $this->dir.'/config/sri.php'));
        $tester->execute(['--algorithm' => 'sha256']);

        $this->assertStringStartsWith('sha256-', (string) AssetIntegrity::fromFile($this->dir.'/config/sri.php')->for('/assets/js/app.js'));
    }

    public function testAnUnknownAlgorithmFails(): void
    {
        $tester = new CommandTester(new AssetsSriCommand($this->dir.'/public', $this->dir.'/config/sri.php'));

        $this->assertSame(1, $tester->execute(['--algorithm' => 'md5']));
        $this->assertFileDoesNotExist($this->dir.'/config/sri.php');
    }

    public function testAMissingDirectoryIsAWarningNotAFailure(): void
    {
        $tester = new CommandTester(new AssetsSriCommand($this->dir.'/public', $this->dir.'/config/sri.php', ['nope']));

        $this->assertSame(0, $tester->execute([]));
        $this->assertStringContainsString('No such directory', $tester->getDisplay());
        $this->assertTrue(AssetIntegrity::fromFile($this->dir.'/config/sri.php')->isEmpty());
    }
}
