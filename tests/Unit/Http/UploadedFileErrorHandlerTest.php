<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Tests\Unit\Http;


use Modufolio\Psr7\Http\Factory\Psr17Factory;
use Modufolio\Psr7\Http\Stream;
use Modufolio\Appkit\Http\UploadedFileErrorHandler;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UploadedFileInterface;

class UploadedFileErrorHandlerTest extends TestCase
{
    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
    }

    private function createUploadedFile(
        string $content = 'test content',
        string $filename = 'test.txt',
        string $mediaType = 'text/plain',
        int $error = UPLOAD_ERR_OK
    ): UploadedFileInterface {
        $stream = Stream::create($content);
        return $this->factory->createUploadedFile(
            $stream,
            strlen($content),
            $error,
            $filename,
            $mediaType
        );
    }

    public function testConstructorWithValidFile(): void
    {
        $file = $this->createUploadedFile();
        $handler = new UploadedFileErrorHandler($file);

        $this->assertFalse($handler->hasErrors());
        $this->assertEmpty($handler->getErrors());
    }

    public function testConstructorWithUploadError(): void
    {
        $file = $this->createUploadedFile('', 'test.txt', 'text/plain', UPLOAD_ERR_NO_FILE);
        $handler = new UploadedFileErrorHandler($file);

        $this->assertTrue($handler->hasErrors());
        $this->assertCount(1, $handler->getErrors());
    }

    public function testFromFactoryMethod(): void
    {
        $file = $this->createUploadedFile();
        $handler = UploadedFileErrorHandler::from($file);

        $this->assertInstanceOf(UploadedFileErrorHandler::class, $handler);
        $this->assertFalse($handler->hasErrors());
    }

    public function testHasExtensionWithValidExtension(): void
    {
        $file = $this->createUploadedFile('content', 'document.pdf');
        $handler = UploadedFileErrorHandler::from($file);

        $handler->hasExtension('pdf');

        $this->assertFalse($handler->hasErrors());
    }

    public function testHasExtensionWithInvalidExtension(): void
    {
        $file = $this->createUploadedFile('content', 'document.pdf');
        $handler = UploadedFileErrorHandler::from($file);

        $handler->hasExtension('jpg');

        $this->assertTrue($handler->hasErrors());
        $this->assertStringContainsString('File must have one of the following extensions', $handler->getErrors()[0]);
    }

    public function testHasExtensionWithMultipleExtensions(): void
    {
        $file = $this->createUploadedFile('content', 'image.png');
        $handler = UploadedFileErrorHandler::from($file);

        $handler->hasExtension(['jpg', 'png', 'gif']);

        $this->assertFalse($handler->hasErrors());
    }

    public function testHasExtensionCaseInsensitive(): void
    {
        $file = $this->createUploadedFile('content', 'document.PDF');
        $handler = UploadedFileErrorHandler::from($file);

        $handler->hasExtension('pdf');

        $this->assertFalse($handler->hasErrors());
    }

    public function testHasExtensionWithCustomMessage(): void
    {
        $file = $this->createUploadedFile('content', 'document.pdf');
        $handler = UploadedFileErrorHandler::from($file);

        $handler->hasExtension('jpg', 'Only JPG files are allowed');

        $this->assertTrue($handler->hasErrors());
        $this->assertContains('Only JPG files are allowed', $handler->getErrors());
    }

    public function testMaxSizeWithValidSize(): void
    {
        $file = $this->createUploadedFile('small');
        $handler = UploadedFileErrorHandler::from($file);

        $handler->maxSize(1024);

        $this->assertFalse($handler->hasErrors());
    }

    public function testMaxSizeWithExceededSize(): void
    {
        $file = $this->createUploadedFile(str_repeat('x', 1000));
        $handler = UploadedFileErrorHandler::from($file);

        $handler->maxSize(100);

        $this->assertTrue($handler->hasErrors());
        $this->assertStringContainsString('File size must not exceed', $handler->getErrors()[0]);
    }

    public function testMaxSizeWithCustomMessage(): void
    {
        $file = $this->createUploadedFile(str_repeat('x', 1000));
        $handler = UploadedFileErrorHandler::from($file);

        $handler->maxSize(100, 'File is too large');

        $this->assertTrue($handler->hasErrors());
        $this->assertContains('File is too large', $handler->getErrors());
    }

    public function testMinSizeWithValidSize(): void
    {
        $file = $this->createUploadedFile(str_repeat('x', 1000));
        $handler = UploadedFileErrorHandler::from($file);

        $handler->minSize(100);

        $this->assertFalse($handler->hasErrors());
    }

    public function testMinSizeWithTooSmallSize(): void
    {
        $file = $this->createUploadedFile('small');
        $handler = UploadedFileErrorHandler::from($file);

        $handler->minSize(1000);

        $this->assertTrue($handler->hasErrors());
        $this->assertStringContainsString('File size must be at least', $handler->getErrors()[0]);
    }

    public function testMinSizeWithCustomMessage(): void
    {
        $file = $this->createUploadedFile('small');
        $handler = UploadedFileErrorHandler::from($file);

        $handler->minSize(1000, 'File is too small');

        $this->assertTrue($handler->hasErrors());
        $this->assertContains('File is too small', $handler->getErrors());
    }

    public function testMatchesFilenamePatternWithValidPattern(): void
    {
        $file = $this->createUploadedFile('content', 'report_2023_Q1.pdf');
        $handler = UploadedFileErrorHandler::from($file);

        $handler->matchesFilenamePattern('/^report_\d{4}_Q\d\.pdf$/');

        $this->assertFalse($handler->hasErrors());
    }

    public function testMatchesFilenamePatternWithInvalidPattern(): void
    {
        $file = $this->createUploadedFile('content', 'document.pdf');
        $handler = UploadedFileErrorHandler::from($file);

        $handler->matchesFilenamePattern('/^report_\d{4}\.pdf$/');

        $this->assertTrue($handler->hasErrors());
        $this->assertStringContainsString('Filename must match pattern', $handler->getErrors()[0]);
    }

    public function testMatchesFilenamePatternWithCustomMessage(): void
    {
        $file = $this->createUploadedFile('content', 'invalid.pdf');
        $handler = UploadedFileErrorHandler::from($file);

        $handler->matchesFilenamePattern('/^valid\.pdf$/', 'Invalid filename format');

        $this->assertTrue($handler->hasErrors());
        $this->assertContains('Invalid filename format', $handler->getErrors());
    }

    public function testAssertWithValidCallback(): void
    {
        $file = $this->createUploadedFile('content', 'test.txt');
        $handler = UploadedFileErrorHandler::from($file);

        $handler->assert(function ($file) {
            return $file->getClientFilename() === 'test.txt';
        }, 'Filename must be test.txt');

        $this->assertFalse($handler->hasErrors());
    }

    public function testAssertWithInvalidCallback(): void
    {
        $file = $this->createUploadedFile('content', 'test.txt');
        $handler = UploadedFileErrorHandler::from($file);

        $handler->assert(function ($file) {
            return $file->getClientFilename() === 'other.txt';
        }, 'Filename must be other.txt');

        $this->assertTrue($handler->hasErrors());
        $this->assertContains('Filename must be other.txt', $handler->getErrors());
    }

    public function testGetFile(): void
    {
        $file = $this->createUploadedFile('content', 'test.txt');
        $handler = UploadedFileErrorHandler::from($file);

        $this->assertSame($file, $handler->getFile());
    }

    public function testChainingValidations(): void
    {
        $file = $this->createUploadedFile(str_repeat('x', 500), 'document.pdf');
        $handler = UploadedFileErrorHandler::from($file);

        $handler
            ->hasExtension(['pdf', 'doc'])
            ->minSize(100)
            ->maxSize(1000)
            ->matchesFilenamePattern('/^[a-z]+\.pdf$/');

        $this->assertFalse($handler->hasErrors());
    }

    public function testChainingValidationsWithErrors(): void
    {
        $file = $this->createUploadedFile(str_repeat('x', 2000), 'invalid_file.txt');
        $handler = UploadedFileErrorHandler::from($file);

        $handler
            ->hasExtension('pdf')
            ->maxSize(1000)
            ->matchesFilenamePattern('/^valid\.pdf$/');

        $this->assertTrue($handler->hasErrors());
        $this->assertCount(3, $handler->getErrors());
    }

    public function testSaveToWithValidFile(): void
    {
        $tmpDir = sys_get_temp_dir() . '/upload_test_' . uniqid();

        $file = $this->createUploadedFile('test content', 'test.txt');
        $handler = UploadedFileErrorHandler::from($file);

        try {
            $handler->saveTo($tmpDir);

            $savedPath = $handler->getStoredFilePath();
            $this->assertFileExists($savedPath);
            $this->assertEquals('test content', file_get_contents($savedPath));
        } finally {
            // Cleanup
            if (file_exists($tmpDir . '/test.txt')) {
                unlink($tmpDir . '/test.txt');
            }
            if (is_dir($tmpDir)) {
                rmdir($tmpDir);
            }
        }
    }

    public function testSaveToWithCustomFilename(): void
    {
        $tmpDir = sys_get_temp_dir() . '/upload_test_' . uniqid();

        $file = $this->createUploadedFile('test content', 'original.txt');
        $handler = UploadedFileErrorHandler::from($file);

        try {
            $handler->saveTo($tmpDir, 'custom.txt');

            $savedPath = $handler->getStoredFilePath();
            $this->assertStringEndsWith('custom.txt', $savedPath);
            $this->assertFileExists($savedPath);
        } finally {
            // Cleanup
            if (file_exists($tmpDir . '/custom.txt')) {
                unlink($tmpDir . '/custom.txt');
            }
            if (is_dir($tmpDir)) {
                rmdir($tmpDir);
            }
        }
    }

    public function testSaveToWithValidationErrorsThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot save file due to validation errors');

        $tmpDir = sys_get_temp_dir() . '/upload_test_' . uniqid();

        $file = $this->createUploadedFile('content', 'test.txt');
        $handler = UploadedFileErrorHandler::from($file);

        $handler->hasExtension('pdf'); // This will fail
        $handler->saveTo($tmpDir);
    }

    public function testUploadErrorTranslations(): void
    {
        $testCases = [
            UPLOAD_ERR_INI_SIZE => 'upload_max_filesize',
            UPLOAD_ERR_FORM_SIZE => 'MAX_FILE_SIZE',
            UPLOAD_ERR_PARTIAL => 'partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file',
            UPLOAD_ERR_NO_TMP_DIR => 'temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'write file to disk',
            UPLOAD_ERR_EXTENSION => 'extension stopped',
        ];

        foreach ($testCases as $errorCode => $expectedMessage) {
            $file = $this->createUploadedFile('', 'test.txt', 'text/plain', $errorCode);
            $handler = new UploadedFileErrorHandler($file);

            $this->assertTrue($handler->hasErrors());
            $this->assertStringContainsString($expectedMessage, $handler->getErrors()[0]);
        }
    }

    public function testGetStoredFilePath(): void
    {
        $tmpDir = sys_get_temp_dir() . '/upload_test_' . uniqid();

        $file = $this->createUploadedFile('content', 'test.txt');
        $handler = UploadedFileErrorHandler::from($file);

        try {
            $handler->saveTo($tmpDir, 'saved.txt');

            $path = $handler->getStoredFilePath();
            $this->assertStringContainsString('saved.txt', $path);
        } finally {
            // Cleanup
            if (file_exists($tmpDir . '/saved.txt')) {
                unlink($tmpDir . '/saved.txt');
            }
            if (is_dir($tmpDir)) {
                rmdir($tmpDir);
            }
        }
    }
}
