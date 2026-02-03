<?php

namespace Modufolio\Appkit\Tests\Traits;

use Modufolio\Appkit\Toolkit\Structurer;
use PHPUnit\Framework\Attributes\After;

trait WithFileStructure
{
    private ?Structurer $folderStructure = null;
    private ?string $testRoot = null;

    /**
     * Set up a folder structure for testing.
     *
     * @param array $structure The folder/file structure definition
     * @param string|null $resourceDir Optional directory containing resource files to copy
     * @return string The root path where structure was created
     */
    protected function setupFolderStructure(array $structure, ?string $resourceDir = null): string
    {
        // Create a unique test directory
        $this->testRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpunit_test_' . uniqid();

        $this->folderStructure = new Structurer(
            $this->testRoot,
            $structure,
            $resourceDir
        );

        $this->folderStructure->setup();

        return $this->testRoot;
    }

    /**
     * Get the test root directory path.
     */
    protected function getTestRoot(): string
    {
        if (!$this->testRoot) {
            throw new \RuntimeException('Test root not initialized. Call setupFolderStructure() first.');
        }

        return $this->testRoot;
    }

    /**
     * Get a full path within the test structure.
     */
    protected function getTestPath(string $relativePath): string
    {
        return $this->getTestRoot() . DIRECTORY_SEPARATOR . ltrim($relativePath, DIRECTORY_SEPARATOR);
    }

    /**
     * Assert that the folder structure matches expectations.
     */
    protected function assertFolderStructureMatches(): void
    {
        if (!$this->folderStructure) {
            throw new \RuntimeException('Folder structure not initialized.');
        }

        $differences = $this->folderStructure->diff();

        $this->assertEmpty(
            $differences,
            'Folder structure has missing items: ' . implode(', ', $differences)
        );
    }

    /**
     * Clean up the test folder structure.
     */
    #[After]
    protected function tearDownFolderStructure(): void
    {
        if ($this->folderStructure) {
            $this->folderStructure->teardown();

            // Remove the test root directory if it exists and is empty
            if ($this->testRoot && is_dir($this->testRoot)) {
                @rmdir($this->testRoot);
            }

            $this->folderStructure = null;
            $this->testRoot = null;
        }
    }
}