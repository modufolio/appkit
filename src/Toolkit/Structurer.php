<?php

namespace Modufolio\Appkit\Toolkit;

class Structurer
{
    private string $root;
    private array $structure;
    private ?string $resourceDir;


    public function __construct(string $root, array $structure, ?string $resourceDir = null)
    {
        $this->root = rtrim($root, DIRECTORY_SEPARATOR);
        $this->structure = $structure;
        $this->resourceDir = $resourceDir ? rtrim($resourceDir, DIRECTORY_SEPARATOR) : null;
    }

    /**
     * Build the structure (setup).
     */
    public function setup(): void
    {
        $this->createStructure($this->root, $this->structure);
    }

    /**
     * Remove only the structure and files defined (teardown).
     */
    public function teardown(): void
    {
        $this->removeStructure($this->root, $this->structure);
    }

    /**
     * Compare actual filesystem against expected structure.
     * Returns array of differences found.
     */
    public function diff(): array
    {
        return $this->diffStructure($this->root, $this->structure, '');
    }

    private function createStructure(string $base, array $structure): void
    {
        foreach ($structure as $name => $children) {
            // Numeric key → file
            if (is_int($name) && is_string($children)) {
                $this->createFile($base, $children);
                continue;
            }

            // Otherwise → directory
            $path = $base . DIRECTORY_SEPARATOR . $name;
            if (!is_dir($path)) {
                mkdir($path, 0777, true);
            }

            // Copy resources if matching dir exists
            if ($this->resourceDir) {
                $relative = str_replace($this->root . DIRECTORY_SEPARATOR, '', $path);
                $resourceDir = $this->resourceDir . DIRECTORY_SEPARATOR . $relative;

                if (is_dir($resourceDir)) {
                    $this->copyDirectory($resourceDir, $path);
                }
            }

            if (is_array($children)) {
                $this->createStructure($path, $children);
            }
        }
    }

    private function createFile(string $dir, string $filename): void
    {
        $target = $dir . DIRECTORY_SEPARATOR . $filename;

        if (file_exists($target)) {
            return; // idempotent
        }

        if ($this->resourceDir) {
            $relative = str_replace($this->root . DIRECTORY_SEPARATOR, '', $dir . DIRECTORY_SEPARATOR . $filename);
            $resourceFile = $this->resourceDir . DIRECTORY_SEPARATOR . $relative;

            if (file_exists($resourceFile)) {
                $this->ensureDir(dirname($target));
                copy($resourceFile, $target);
                return;
            }
        }

        file_put_contents($target, ""); // empty file fallback
    }

    private function copyDirectory(string $src, string $dst): void
    {
        $dir = opendir($src);
        $this->ensureDir($dst);

        while (false !== ($file = readdir($dir))) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $srcPath = $src . DIRECTORY_SEPARATOR . $file;
            $dstPath = $dst . DIRECTORY_SEPARATOR . $file;

            if (is_dir($srcPath)) {
                $this->copyDirectory($srcPath, $dstPath);
            } elseif (!file_exists($dstPath)) {
                copy($srcPath, $dstPath);
            }
        }

        closedir($dir);
    }

    private function removeStructure(string $base, array $structure): void
    {
        foreach ($structure as $name => $children) {
            if (is_int($name) && is_string($children)) {
                $file = $base . DIRECTORY_SEPARATOR . $children;
                if (file_exists($file)) {
                    unlink($file);
                }
                continue;
            }

            $path = $base . DIRECTORY_SEPARATOR . $name;

            if (is_array($children)) {
                $this->removeStructure($path, $children);
            }

            // remove dir if empty
            if (is_dir($path) && count(scandir($path)) <= 2) {
                rmdir($path);
            }
        }
    }

    private function diffStructure(string $base, array $structure, string $relativePath): array
    {
        $differences = [];

        foreach ($structure as $name => $children) {
            // File
            if (is_int($name) && is_string($children)) {
                $filePath = $base . DIRECTORY_SEPARATOR . $children;
                $relativeFile = $relativePath ? $relativePath . '/' . $children : $children;

                if (!file_exists($filePath)) {
                    $differences[] = $relativeFile;
                }
                continue;
            }

            // Directory
            $dirPath = $base . DIRECTORY_SEPARATOR . $name;
            $relativeDir = $relativePath ? $relativePath . '/' . $name : $name;

            if (!file_exists($dirPath)) {
                $differences[] = $relativeDir;
                continue;
            }

            // Recurse
            if (is_array($children)) {
                $subDifferences = $this->diffStructure($dirPath, $children, $relativeDir);
                $differences = array_merge($differences, $subDifferences);
            }
        }

        return $differences;
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
    }
}