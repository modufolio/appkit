<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Toolkit;

/**
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
class File
{
    private ?\SplFileObject $fileObject = null;

    /**
     * @throws \Exception
     */
    public function __construct(string $filename, $mode = 'r')
    {
        if (!file_exists($filename) && 'r' === $mode) {
            throw new \RuntimeException("File does not exist: {$filename}");
        }
        $this->fileObject = new \SplFileObject($filename, $mode);
    }

    public function readLine(): string
    {
        return $this->fileObject->fgets();
    }

    public function readAll(): string
    {
        $this->fileObject->rewind();
        $content = '';
        while (!$this->fileObject->eof()) {
            $content .= $this->fileObject->fgets();
        }

        return $content;
    }

    public function writeLine($data): void
    {
        $this->fileObject->fwrite($data.PHP_EOL);
    }

    public function writeAll($data): void
    {
        $this->fileObject->ftruncate(0);
        $this->fileObject->rewind();
        $this->fileObject->fwrite($data);
    }

    public function getLines(): array
    {
        $lines = [];
        foreach ($this->fileObject as $line) {
            $lines[] = $line;
        }

        return $lines;
    }

    public function getPath(): bool|string
    {
        return $this->fileObject->getRealPath();
    }

    public function getSize(): false|int
    {
        return $this->fileObject->getSize();
    }

    public function findLinesContaining($string): array
    {
        $lines = [];
        foreach ($this->fileObject as $line) {
            // SplFileObject yields false past the final line, which
            // str_contains() rejects as of PHP 8.6.
            if (!is_string($line) || !str_contains($line, $string)) {
                continue;
            }

            $lines[] = $line;
        }

        return $lines;
    }

    public function close(): void
    {
        $this->fileObject = null;
    }

    public function __destruct()
    {
        $this->close();
    }
}
