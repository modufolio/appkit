<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Toolkit;

use Exception;
use SplFileObject;

class File
{
    private ?SplFileObject $fileObject = null;

    /**
     * @throws Exception
     */
    public function __construct(string $filename, $mode = 'r')
    {
        if (!file_exists($filename) && $mode === 'r') {
            throw new \RuntimeException("File does not exist: $filename");
        }
        $this->fileObject = new SplFileObject($filename, $mode);
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
        $this->fileObject->fwrite($data . PHP_EOL);
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

    public function readCsv($delimiter = ',', $enclosure = '"', $escape = '\\'): array
    {
        $this->fileObject->setFlags(SplFileObject::READ_CSV);
        $rows = [];
        while (!$this->fileObject->eof()) {
            $row = $this->fileObject->fgetcsv($delimiter, $enclosure, $escape);
            if (is_array($row) && $row[0] !== null) { // avoiding empty lines
                $rows[] = $row;
            }
        }
        return $rows;
    }

    public function writeCsv(array $data, $delimiter = ',', $enclosure = '"', $escape = '\\'): bool
    {
        $state = false;
        foreach ($data as $row) {
            $state = is_int($this->fileObject->fputcsv($row, $delimiter, $enclosure, $escape));
        }
        return $state;
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
            if (str_contains($line, $string)) {
                $lines[] = $line;
            }
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
