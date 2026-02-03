<?php

namespace Modufolio\Appkit\Tests\Unit\Toolkit;

use Modufolio\Appkit\Toolkit\Structurer;
use PHPUnit\Framework\TestCase;

class StructurerTest extends TestCase
{
    private string $testRoot;
    private string $resourceRoot;

    protected function setUp(): void
    {
        parent::setUp();

        // Create temporary test directories
        $this->testRoot = sys_get_temp_dir() . '/structurer_test_' . uniqid();
        $this->resourceRoot = sys_get_temp_dir() . '/structurer_resources_' . uniqid();

        mkdir($this->testRoot, 0777, true);
        mkdir($this->resourceRoot, 0777, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Clean up test directories
        $this->removeDirectory($this->testRoot);
        $this->removeDirectory($this->resourceRoot);
    }

    public function testSetupCreatesDirectories(): void
    {
        $structure = [
            'dir1' => [],
            'dir2' => [
                'subdir1' => []
            ]
        ];

        $structurer = new Structurer($this->testRoot, $structure);
        $structurer->setup();

        $this->assertDirectoryExists($this->testRoot . '/dir1');
        $this->assertDirectoryExists($this->testRoot . '/dir2');
        $this->assertDirectoryExists($this->testRoot . '/dir2/subdir1');
    }

    public function testSetupCreatesFiles(): void
    {
        $structure = [
            'file1.txt',
            'dir1' => [
                'file2.txt'
            ]
        ];

        $structurer = new Structurer($this->testRoot, $structure);
        $structurer->setup();

        $this->assertFileExists($this->testRoot . '/file1.txt');
        $this->assertFileExists($this->testRoot . '/dir1/file2.txt');
    }

    public function testSetupWithResources(): void
    {
        // Create resource files
        mkdir($this->resourceRoot . '/dir1', 0777, true);
        file_put_contents($this->resourceRoot . '/file1.txt', 'content1');
        file_put_contents($this->resourceRoot . '/dir1/file2.txt', 'content2');

        $structure = [
            'file1.txt',
            'dir1' => [
                'file2.txt'
            ]
        ];

        $structurer = new Structurer($this->testRoot, $structure, $this->resourceRoot);
        $structurer->setup();

        $this->assertFileExists($this->testRoot . '/file1.txt');
        $this->assertEquals('content1', file_get_contents($this->testRoot . '/file1.txt'));
        $this->assertFileExists($this->testRoot . '/dir1/file2.txt');
        $this->assertEquals('content2', file_get_contents($this->testRoot . '/dir1/file2.txt'));
    }

    public function testSetupIsIdempotent(): void
    {
        $structure = [
            'file1.txt',
            'dir1' => []
        ];

        $structurer = new Structurer($this->testRoot, $structure);

        // Run setup twice
        $structurer->setup();
        file_put_contents($this->testRoot . '/file1.txt', 'modified content');
        $structurer->setup();

        // File should not be overwritten
        $this->assertEquals('modified content', file_get_contents($this->testRoot . '/file1.txt'));
    }

    public function testSetupCopiesDirectoryResources(): void
    {
        // Create resource directory with multiple files
        mkdir($this->resourceRoot . '/assets', 0777, true);
        file_put_contents($this->resourceRoot . '/assets/style.css', 'body {}');
        file_put_contents($this->resourceRoot . '/assets/script.js', 'alert();');

        $structure = [
            'assets' => []
        ];

        $structurer = new Structurer($this->testRoot, $structure, $this->resourceRoot);
        $structurer->setup();

        $this->assertFileExists($this->testRoot . '/assets/style.css');
        $this->assertFileExists($this->testRoot . '/assets/script.js');
        $this->assertEquals('body {}', file_get_contents($this->testRoot . '/assets/style.css'));
    }

    public function testTeardownRemovesStructure(): void
    {
        $structure = [
            'file1.txt',
            'dir1' => [
                'file2.txt',
                'subdir' => []
            ]
        ];

        $structurer = new Structurer($this->testRoot, $structure);
        $structurer->setup();

        // Verify structure exists
        $this->assertFileExists($this->testRoot . '/file1.txt');
        $this->assertDirectoryExists($this->testRoot . '/dir1');

        $structurer->teardown();

        // Verify structure is removed
        $this->assertFileDoesNotExist($this->testRoot . '/file1.txt');
        $this->assertDirectoryDoesNotExist($this->testRoot . '/dir1/subdir');
        $this->assertDirectoryDoesNotExist($this->testRoot . '/dir1');
    }

    public function testTeardownOnlyRemovesEmptyDirectories(): void
    {
        $structure = [
            'dir1' => [
                'file1.txt'
            ]
        ];

        $structurer = new Structurer($this->testRoot, $structure);
        $structurer->setup();

        // Add an extra file not in structure
        file_put_contents($this->testRoot . '/dir1/extra.txt', 'extra');

        $structurer->teardown();

        // dir1 should still exist because it's not empty
        $this->assertDirectoryExists($this->testRoot . '/dir1');
        $this->assertFileDoesNotExist($this->testRoot . '/dir1/file1.txt');
        $this->assertFileExists($this->testRoot . '/dir1/extra.txt');
    }

    public function testDiffReturnsEmptyWhenStructureMatches(): void
    {
        $structure = [
            'file1.txt',
            'dir1' => [
                'file2.txt'
            ]
        ];

        $structurer = new Structurer($this->testRoot, $structure);
        $structurer->setup();

        $diff = $structurer->diff();

        $this->assertEmpty($diff);
    }

    public function testDiffReturnsMissingFiles(): void
    {
        $structure = [
            'file1.txt',
            'dir1' => [
                'file2.txt',
                'file3.txt'
            ]
        ];

        $structurer = new Structurer($this->testRoot, $structure);

        // Create only partial structure
        mkdir($this->testRoot . '/dir1', 0777, true);
        file_put_contents($this->testRoot . '/file1.txt', '');

        $diff = $structurer->diff();

        $this->assertContains('dir1/file2.txt', $diff);
        $this->assertContains('dir1/file3.txt', $diff);
        $this->assertCount(2, $diff);
    }

    public function testDiffReturnsMissingDirectories(): void
    {
        $structure = [
            'dir1' => [
                'subdir1' => [],
                'subdir2' => []
            ]
        ];

        $structurer = new Structurer($this->testRoot, $structure);

        $diff = $structurer->diff();

        $this->assertContains('dir1', $diff);
    }

    public function testDiffReturnsNestedMissingItems(): void
    {
        $structure = [
            'level1' => [
                'level2' => [
                    'level3' => [
                        'deep.txt'
                    ]
                ]
            ]
        ];

        $structurer = new Structurer($this->testRoot, $structure);

        $diff = $structurer->diff();

        $this->assertContains('level1', $diff);
    }

    public function testComplexStructure(): void
    {
        $structure = [
            'config.json',
            'src' => [
                'index.php',
                'models' => [
                    'User.php',
                    'Post.php'
                ],
                'controllers' => []
            ],
            'tests' => [
                'TestCase.php'
            ],
            'README.md'
        ];

        $structurer = new Structurer($this->testRoot, $structure);
        $structurer->setup();

        // Verify all files and directories
        $this->assertFileExists($this->testRoot . '/config.json');
        $this->assertFileExists($this->testRoot . '/README.md');
        $this->assertFileExists($this->testRoot . '/src/index.php');
        $this->assertFileExists($this->testRoot . '/src/models/User.php');
        $this->assertFileExists($this->testRoot . '/src/models/Post.php');
        $this->assertDirectoryExists($this->testRoot . '/src/controllers');
        $this->assertFileExists($this->testRoot . '/tests/TestCase.php');

        // Verify diff returns empty
        $diff = $structurer->diff();
        $this->assertEmpty($diff);

        // Teardown and verify
        $structurer->teardown();
        $this->assertDirectoryDoesNotExist($this->testRoot . '/src');
        $this->assertDirectoryDoesNotExist($this->testRoot . '/tests');
    }

    public function testRootPathNormalization(): void
    {
        // Test with trailing slash
        $structurer = new Structurer($this->testRoot . '/', ['file.txt']);
        $structurer->setup();

        $this->assertFileExists($this->testRoot . '/file.txt');
    }

    /**
     * Helper method to recursively remove a directory
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);

        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}