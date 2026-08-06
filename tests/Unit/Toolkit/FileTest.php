<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Toolkit;

use Modufolio\Appkit\Toolkit\Dir;
use Modufolio\Appkit\Toolkit\File;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(File::class)]
class FileTest extends TestCase
{
    protected string $tmp = __DIR__.'/tmp-file';

    public function setUp(): void
    {
        Dir::make($this->tmp);
    }

    public function tearDown(): void
    {
        Dir::remove($this->tmp);
    }

    protected function file(string $content = ''): string
    {
        $root = $this->tmp.'/test.txt';

        file_put_contents($root, $content);

        return $root;
    }

    public function testConstructMissingFile(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('File does not exist: '.$this->tmp.'/does-not-exist.txt');

        new File($this->tmp.'/does-not-exist.txt');
    }

    public function testConstructMissingFileWriteMode(): void
    {
        $root = $this->tmp.'/new.txt';

        $file = new File($root, 'w');
        $file->close();

        $this->assertFileExists($root);
    }

    public function testReadLine(): void
    {
        $file = new File($this->file("line one\nline two\n"));

        $this->assertSame("line one\n", $file->readLine());
        $this->assertSame("line two\n", $file->readLine());
    }

    public function testReadAll(): void
    {
        $content = "line one\nline two\nline three";
        $file = new File($this->file($content));

        $this->assertSame($content, $file->readAll());
    }

    public function testWriteLine(): void
    {
        $root = $this->tmp.'/write.txt';

        $file = new File($root, 'w');
        $file->writeLine('line one');
        $file->writeLine('line two');
        $file->close();

        $this->assertSame('line one'.PHP_EOL.'line two'.PHP_EOL, file_get_contents($root));
    }

    public function testWriteAll(): void
    {
        $root = $this->file('previous content that is longer');

        $file = new File($root, 'r+');
        $file->writeAll('new');
        $file->close();

        $this->assertSame('new', file_get_contents($root));
    }

    public function testGetLines(): void
    {
        $file = new File($this->file("a\nb\nc"));

        $this->assertSame(["a\n", "b\n", 'c'], $file->getLines());
    }

    public function testReadCsv(): void
    {
        $file = new File($this->file("a,b\n\nc,d\n"));

        $this->assertSame([['a', 'b'], ['c', 'd']], $file->readCsv());
    }

    public function testReadCsvWithCustomDelimiter(): void
    {
        $file = new File($this->file("a;b\nc;d\n"));

        $this->assertSame([['a', 'b'], ['c', 'd']], $file->readCsv(';'));
    }

    public function testWriteCsv(): void
    {
        $root = $this->tmp.'/write.csv';

        $file = new File($root, 'w');

        $this->assertTrue($file->writeCsv([['a', 'b'], ['c', 'd']]));

        $file->close();

        $reader = new File($root);

        $this->assertSame([['a', 'b'], ['c', 'd']], $reader->readCsv());
    }

    public function testWriteCsvEmpty(): void
    {
        $file = new File($this->tmp.'/write.csv', 'w');

        $this->assertFalse($file->writeCsv([]));
    }

    public function testGetPath(): void
    {
        $root = $this->file('test');
        $file = new File($root);

        $this->assertSame(realpath($root), $file->getPath());
    }

    public function testGetSize(): void
    {
        $file = new File($this->file('test'));

        $this->assertSame(4, $file->getSize());
    }

    public function testFindLinesContaining(): void
    {
        $file = new File($this->file("foo bar\nbaz\nanother foo\n"));

        $this->assertSame(["foo bar\n", "another foo\n"], $file->findLinesContaining('foo'));
        $this->assertSame([], $file->findLinesContaining('missing'));
    }

    public function testClose(): void
    {
        $file = new File($this->file('test'));
        $file->close();

        // closing again does not cause any errors
        $file->close();

        $this->expectException(\Error::class);
        $file->readLine();
    }
}
