<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Toolkit;

use Modufolio\Appkit\Toolkit\Dir;
use Modufolio\Appkit\Toolkit\F;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(F::class)]
class FTest extends TestCase
{
    public const FIXTURES = __DIR__.'/fixtures/f';

    protected string $fixtures = __DIR__.'/fixtures/f';
    protected string $tmp = __DIR__.'/tmp-f';

    public function setUp(): void
    {
        Dir::make($this->tmp);
    }

    public function tearDown(): void
    {
        // restore permissions of protected test folders
        // before removing the tmp folder
        if (true === is_dir($this->tmp.'/protected')) {
            chmod($this->tmp.'/protected', 0o755);
        }

        Dir::remove($this->tmp);
    }

    public function testAppend(): void
    {
        $file = $this->tmp.'/append.txt';

        $this->assertTrue(F::write($file, 'a'));
        $this->assertTrue(F::append($file, 'b'));
        $this->assertSame('ab', F::read($file));
    }

    public function testBase64(): void
    {
        $file = $this->tmp.'/base64.txt';

        F::write($file, 'test');

        $this->assertSame(base64_encode('test'), F::base64($file));
        $this->assertSame('', F::base64($this->tmp.'/does-not-exist.txt'));
    }

    public function testCopy(): void
    {
        $source = $this->tmp.'/a.txt';
        $target = $this->tmp.'/b.txt';

        F::write($source, 'test');

        $this->assertTrue(F::copy($source, $target));
        $this->assertSame('test', F::read($target));
    }

    public function testCopyMissingSource(): void
    {
        $this->assertFalse(F::copy($this->tmp.'/does-not-exist.txt', $this->tmp.'/b.txt'));
    }

    public function testCopyExistingTarget(): void
    {
        $source = $this->tmp.'/a.txt';
        $target = $this->tmp.'/b.txt';

        F::write($source, 'a');
        F::write($target, 'b');

        $this->assertFalse(F::copy($source, $target));
        $this->assertTrue(F::copy($source, $target, true));
        $this->assertSame('a', F::read($target));
    }

    public function testCopyToMissingDirectory(): void
    {
        $source = $this->tmp.'/a.txt';
        $target = $this->tmp.'/sub/folder/b.txt';

        F::write($source, 'test');

        $this->assertTrue(F::copy($source, $target));
        $this->assertFileExists($target);
    }

    public function testDirname(): void
    {
        $this->assertSame('/var/www', F::dirname('/var/www/test.txt'));
    }

    public function testExists(): void
    {
        $file = $this->tmp.'/exists.txt';

        $this->assertFalse(F::exists($file));

        F::write($file, 'test');

        $this->assertTrue(F::exists($file));
        $this->assertTrue(F::exists($file, $this->tmp));
        $this->assertFalse(F::exists($file, __DIR__.'/fixtures'));
    }

    public function testExtension(): void
    {
        $this->assertSame('php', F::extension('/var/www/test.PHP'));
        $this->assertSame('test.jpg', F::extension('/var/www/test.php', 'jpg'));
        $this->assertSame('', F::extension('/var/www/test'));
    }

    public function testExtensionToMime(): void
    {
        $this->assertSame('image/jpeg', F::extensionToMime('jpg'));
        $this->assertNull(F::extensionToMime('foo'));
    }

    public function testExtensionToType(): void
    {
        $this->assertSame('image', F::extensionToType('jpg'));
        $this->assertSame('document', F::extensionToType('pdf'));
        $this->assertFalse(F::extensionToType('foo'));
    }

    public function testExtensions(): void
    {
        $this->assertSame(array_keys(\Modufolio\Appkit\Toolkit\Mime::types()), F::extensions());
        $this->assertSame(F::$types['image'], F::extensions('image'));
        $this->assertSame([], F::extensions('foo'));
    }

    public function testFilename(): void
    {
        $this->assertSame('test.txt', F::filename('/var/www/test.txt'));
    }

    public function testInvalidateOpcodeCache(): void
    {
        $file = $this->tmp.'/opcode.php';

        F::write($file, '<?php return 1;');

        // opcache is disabled in the test environment, so invalidation reports false
        $this->assertFalse(F::invalidateOpcodeCache($file));
    }

    public function testIs(): void
    {
        $file = $this->tmp.'/test.txt';

        F::write($file, 'test');

        $this->assertTrue(F::is($file, 'txt'));
        $this->assertFalse(F::is($file, 'jpg'));
        $this->assertTrue(F::is($file, 'text/plain'));
        $this->assertFalse(F::is($file, 'image/jpeg'));
        $this->assertFalse(F::is($file, 'something-else'));
    }

    public function testIsReadable(): void
    {
        $file = $this->tmp.'/readable.txt';

        F::write($file, 'test');

        $this->assertSame(is_readable($file), F::isReadable($file));
    }

    public function testIsWritable(): void
    {
        // existing file
        $file = $this->tmp.'/writable.txt';
        F::write($file, 'test');
        $this->assertSame(is_writable($file), F::isWritable($file));

        // non-existing file: the parent directory is checked
        $this->assertTrue(F::isWritable($this->tmp.'/missing.txt'));
    }

    public function testLink(): void
    {
        $source = $this->tmp.'/source.txt';
        $link = $this->tmp.'/link.txt';

        F::write($source, 'test');

        $this->assertTrue(F::link($source, $link));
        $this->assertFileExists($link);

        // an existing link is not created again
        $this->assertTrue(F::link($source, $link));
    }

    public function testLinkSymlink(): void
    {
        $source = $this->tmp.'/source.txt';
        $link = $this->tmp.'/symlink.txt';

        F::write($source, 'test');

        $this->assertTrue(F::link($source, $link, 'symlink'));
        $this->assertTrue(is_link($link));
    }

    public function testLinkWithoutSource(): void
    {
        $source = $this->tmp.'/does-not-exist.txt';
        $link = $this->tmp.'/link.txt';

        $this->expectException('Exception');
        $this->expectExceptionMessage('The file "'.$source.'" does not exist and cannot be linked');

        F::link($source, $link);
    }

    public function testLinkWithInvalidMethod(): void
    {
        $source = $this->tmp.'/source.txt';
        $link = $this->tmp.'/link.txt';

        F::write($source, 'test');

        $this->assertFalse(F::link($source, $link, 'this-method-does-not-exist'));
    }

    public function testLoad(): void
    {
        // non-existing file with fallback
        $this->assertSame('fallback', F::load($this->tmp.'/does-not-exist.php', 'fallback'));

        // load a file that returns a string
        $file = $this->tmp.'/load.php';
        F::write($file, '<?php return "loaded";');
        $this->assertSame('loaded', F::load($file));

        // fallback with a non-matching type
        $this->assertSame([], F::load($file, []));

        // load with variables in the data scope
        $file = $this->tmp.'/load-data.php';
        F::write($file, '<?php return $variable;');
        $this->assertSame('test', F::load($file, null, ['variable' => 'test']));
    }

    public function testLoadOnce(): void
    {
        $this->assertFalse(F::loadOnce($this->tmp.'/does-not-exist.php'));

        $this->assertTrue(F::loadOnce($this->fixtures.'/load/B/B.php'));
        $this->assertTrue(class_exists('FTest\B'));

        // loading again is still successful
        $this->assertTrue(F::loadOnce($this->fixtures.'/load/B/B.php'));
    }

    public function testMime(): void
    {
        $file = $this->tmp.'/mime.txt';

        F::write($file, 'test');

        $this->assertSame('text/plain', F::mime($file));
    }

    public function testMimeToExtension(): void
    {
        $this->assertSame('jpg', F::mimeToExtension('image/jpeg'));
        $this->assertFalse(F::mimeToExtension('unknown/mime'));
    }

    public function testMimeToType(): void
    {
        $this->assertSame('image', F::mimeToType('image/jpeg'));
        $this->assertSame('document', F::mimeToType('application/pdf'));
        $this->assertFalse(F::mimeToType('unknown/mime'));
    }

    public function testModified(): void
    {
        $file = $this->tmp.'/modified.txt';

        F::write($file, 'test');

        $this->assertSame(filemtime($file), F::modified($file));
        $this->assertSame(date('Y', filemtime($file) ?: null), F::modified($file, 'Y'));
        $this->assertFalse(F::modified($this->tmp.'/does-not-exist.txt'));
    }

    public function testMove(): void
    {
        $source = $this->tmp.'/a.txt';
        $target = $this->tmp.'/b.txt';

        F::write($source, 'test');

        $this->assertTrue(F::move($source, $target));
        $this->assertFileDoesNotExist($source);
        $this->assertFileExists($target);
    }

    public function testMoveMissingSource(): void
    {
        $this->assertFalse(F::move($this->tmp.'/does-not-exist.txt', $this->tmp.'/b.txt'));
    }

    public function testMoveExistingTarget(): void
    {
        $source = $this->tmp.'/a.txt';
        $target = $this->tmp.'/b.txt';

        F::write($source, 'a');
        F::write($target, 'b');

        $this->assertFalse(F::move($source, $target));
        $this->assertTrue(F::move($source, $target, true));
        $this->assertSame('a', F::read($target));
    }

    public function testMoveToMissingDirectory(): void
    {
        $source = $this->tmp.'/a.txt';
        $target = $this->tmp.'/sub/folder/b.txt';

        F::write($source, 'test');

        $this->assertTrue(F::move($source, $target));
        $this->assertFileExists($target);
    }

    public function testName(): void
    {
        $this->assertSame('test', F::name('/var/www/test.txt'));
    }

    public function testNiceSize(): void
    {
        // numbers
        $this->assertSame('0 KB', F::niceSize(0, false));
        $this->assertSame('0 KB', F::niceSize(-1, false));
        $this->assertSame('4 B', F::niceSize(4, false));
        $this->assertSame('4 KB', F::niceSize(4096, false));
        $this->assertSame('4 MB', F::niceSize(4194304, false));

        // localized number formatting
        $this->assertSame('4 KB', F::niceSize(4096, 'en_US'));

        // file mode
        $file = $this->tmp.'/size.txt';
        F::write($file, 'test');
        $this->assertSame('4 B', F::niceSize($file, false));

        // array of files
        $this->assertSame('8 B', F::niceSize([$file, $file], false));

        // missing file
        $this->assertSame('0 KB', F::niceSize($this->tmp.'/does-not-exist.txt', false));
    }

    public function testRead(): void
    {
        $this->assertSame('my content is awesome', F::read($this->fixtures.'/test.txt'));
        $this->assertFalse(F::read($this->tmp.'/does-not-exist.txt'));
    }

    public function testRename(): void
    {
        $file = $this->tmp.'/a.txt';

        F::write($file, 'test');

        $this->assertSame($this->tmp.'/b.txt', F::rename($file, 'b'));
        $this->assertFileExists($this->tmp.'/b.txt');
        $this->assertFileDoesNotExist($file);
    }

    public function testRenameSameName(): void
    {
        $file = $this->tmp.'/a.txt';

        F::write($file, 'test');

        $this->assertSame($file, F::rename($file, 'a'));
        $this->assertFileExists($file);
    }

    public function testRenameExistingTarget(): void
    {
        $a = $this->tmp.'/a.txt';
        $b = $this->tmp.'/b.txt';

        F::write($a, 'a');
        F::write($b, 'b');

        $this->assertFalse(F::rename($a, 'b'));
        $this->assertSame($b, F::rename($a, 'b', true));
        $this->assertSame('a', F::read($b));
    }

    public function testRealpath(): void
    {
        $file = $this->tmp.'/real.txt';

        F::write($file, 'test');

        $this->assertSame(realpath($file), F::realpath($file));
        $this->assertSame(realpath($file), F::realpath($file, $this->tmp));
    }

    public function testRealpathMissingFile(): void
    {
        $file = $this->tmp.'/does-not-exist.txt';

        $this->expectException('Exception');
        $this->expectExceptionMessage('The file does not exist at the given path: "'.$file.'"');

        F::realpath($file);
    }

    public function testRealpathMissingParent(): void
    {
        $file = $this->tmp.'/real.txt';

        F::write($file, 'test');

        $this->expectException('Exception');
        $this->expectExceptionMessage('The parent directory does not exist: "'.$this->tmp.'/does-not-exist"');

        F::realpath($file, $this->tmp.'/does-not-exist');
    }

    public function testRealpathOutsideParent(): void
    {
        Dir::make($this->tmp.'/other');

        $file = $this->tmp.'/real.txt';

        F::write($file, 'test');

        $this->expectException('Exception');
        $this->expectExceptionMessage('The file is not within the parent directory');

        F::realpath($file, $this->tmp.'/other');
    }

    public function testRelativepath(): void
    {
        $this->assertSame('test.txt', F::relativepath('/var/www/test.txt'));
        $this->assertSame('/test.txt', F::relativepath('/var/www/test.txt', '/var/www'));
        $this->assertSame('/test.txt', F::relativepath('/var/www/test.txt', '/var/www/'));
        $this->assertSame('../test.txt', F::relativepath('/var/www/test.txt', '/var/www/sub'));
        $this->assertSame('../../www/test.txt', F::relativepath('/var/www/test.txt', '/var/log/sub'));

        // windows paths
        $this->assertSame('/test.txt', F::relativepath('C:\www\test.txt', 'C:\www'));
    }

    public function testRemove(): void
    {
        $file = $this->tmp.'/remove.txt';

        F::write($file, 'test');

        $this->assertTrue(F::remove($file));
        $this->assertFileDoesNotExist($file);

        // removing a non-existing file is considered a success
        $this->assertTrue(F::remove($this->tmp.'/does-not-exist.txt'));
    }

    public function testRemoveGlob(): void
    {
        F::write($this->tmp.'/remove-a.txt', 'a');
        F::write($this->tmp.'/remove-b.txt', 'b');
        F::write($this->tmp.'/keep.md', 'c');

        $this->assertTrue(F::remove($this->tmp.'/remove-*.txt'));

        $this->assertFileDoesNotExist($this->tmp.'/remove-a.txt');
        $this->assertFileDoesNotExist($this->tmp.'/remove-b.txt');
        $this->assertFileExists($this->tmp.'/keep.md');
    }

    public function testSafeName(): void
    {
        $this->assertSame('uber-genius.txt', F::safeName('über genius.txt'));
        $this->assertSame('file', F::safeName('file'));
    }

    public function testSafeBasename(): void
    {
        $this->assertSame('uber-genius', F::safeBasename('über genius.txt'));
        $this->assertSame('uber-genius.txt', F::safeBasename('über genius.txt', false));
    }

    public function testSafeExtension(): void
    {
        $this->assertSame('txt', F::safeExtension('über genius.TXT'));
        $this->assertSame('jpg', F::safeExtension('JPG', false));
    }

    public function testSimilar(): void
    {
        F::write($a = $this->tmp.'/similar.txt', 'a');
        F::write($b = $this->tmp.'/similar-1.txt', 'b');
        F::write($this->tmp.'/other.txt', 'c');

        $this->assertSame([$b, $a], F::similar($a));
    }

    public function testSize(): void
    {
        $file = $this->tmp.'/size.txt';

        F::write($file, 'test');

        $this->assertSame(4, F::size($file));
        $this->assertSame(8, F::size([$file, $file]));
        $this->assertSame(0, F::size($this->tmp.'/does-not-exist.txt'));
    }

    public function testType(): void
    {
        // extension mode
        $this->assertSame('image', F::type('jpg'));
        $this->assertSame('document', F::type('pdf'));

        // filename mode
        $this->assertSame('audio', F::type('/var/www/test.mp3'));
        $this->assertSame('image', F::type('/var/www/test.JPG'));

        // unknown extension
        $this->assertNull(F::type('test.foobar'));

        // detect via mime type when the extension is missing
        $file = $this->tmp.'/no-extension';
        $image = imagecreatetruecolor(2, 2);
        imagepng($image, $file);
        imagedestroy($image);
        $this->assertSame('image', F::type($file));
    }

    public function testTypeToExtensions(): void
    {
        $this->assertSame(F::$types['audio'], F::typeToExtensions('audio'));
        $this->assertNull(F::typeToExtensions('foo'));
    }

    public function testUnlink(): void
    {
        $file = $this->tmp.'/unlink.txt';

        F::write($file, 'test');

        $this->assertTrue(F::unlink($file));
        $this->assertFileDoesNotExist($file);
    }

    public function testUnlinkRaceCondition(): void
    {
        // convert warnings to ErrorExceptions like many
        // frameworks do to simulate the race condition handling
        set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline): bool {
            throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
        });

        try {
            // a missing file counts as already deleted
            $this->assertTrue(F::unlink($this->tmp.'/does-not-exist.txt'));

            // other errors are re-thrown
            Dir::make($dir = $this->tmp.'/dir');
            $this->expectException(\ErrorException::class);
            F::unlink($dir);
        } finally {
            restore_error_handler();
        }
    }

    public function testUnzip(): void
    {
        $zipFile = $this->tmp.'/test.zip';

        $zip = new \ZipArchive();
        $zip->open($zipFile, \ZipArchive::CREATE);
        $zip->addFromString('zipped.txt', 'zipped content');
        $zip->close();

        $this->assertTrue(F::unzip($zipFile, $this->tmp.'/unzipped'));
        $this->assertSame('zipped content', F::read($this->tmp.'/unzipped/zipped.txt'));
    }

    public function testUnzipInvalidFile(): void
    {
        $file = $this->tmp.'/not-a-zip.zip';

        F::write($file, 'this is not a zip file');

        $this->assertFalse(F::unzip($file, $this->tmp.'/unzipped'));
    }

    public function testUri(): void
    {
        $file = $this->tmp.'/uri.txt';

        F::write($file, 'test');

        $this->assertSame('data:text/plain;base64,'.base64_encode('test'), F::uri($file));

        // files without detectable mime type
        $this->assertFalse(F::uri($this->tmp.'/does-not-exist'));
    }

    public function testWrite(): void
    {
        $file = $this->tmp.'/write.txt';

        $this->assertTrue(F::write($file, 'test'));
        $this->assertSame('test', F::read($file));

        // arrays are serialized
        $this->assertTrue(F::write($file, $array = ['a' => 'b']));
        $this->assertSame($array, unserialize(F::read($file)));
    }

    public function testWriteToMissingDirectory(): void
    {
        $file = $this->tmp.'/sub/folder/write.txt';

        $this->assertTrue(F::write($file, 'test'));
        $this->assertFileExists($file);
    }

    public function testWriteUnwritable(): void
    {
        Dir::make($dir = $this->tmp.'/protected');
        chmod($dir, 0o555);

        $file = $dir.'/write.txt';

        $this->expectException('Exception');
        $this->expectExceptionMessage('The file "'.$file.'" is not writable');

        F::write($file, 'test');
    }
}
