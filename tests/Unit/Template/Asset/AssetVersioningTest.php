<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Template\Asset;

use Modufolio\Appkit\Template\Asset\AssetIntegrity;
use Modufolio\Appkit\Template\Asset\FileHashVersioning;
use Modufolio\Appkit\Template\Asset\ManifestVersioning;
use Modufolio\Appkit\Template\Asset\NoVersioning;
use PHPUnit\Framework\TestCase;

class AssetVersioningTest extends TestCase
{
    private string $publicDir;

    protected function setUp(): void
    {
        $this->publicDir = sys_get_temp_dir().'/appkit_assets_'.uniqid();
        mkdir($this->publicDir.'/assets/css', 0o777, true);
        file_put_contents($this->publicDir.'/assets/css/app.css', 'body{color:red}');
        file_put_contents($this->publicDir.'/assets/README', 'no extension');
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->publicDir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->publicDir);
    }

    public function testNoVersioningReturnsThePathAsIs(): void
    {
        $this->assertSame('/assets/css/app.css', (new NoVersioning())->version('/assets/css/app.css'));
    }

    public function testFileHashGoesIntoTheNameBeforeTheExtension(): void
    {
        $versioned = (new FileHashVersioning($this->publicDir))->version('/assets/css/app.css');

        $this->assertMatchesRegularExpression('#^/assets/css/app\.[0-9a-f]{32}\.css$#', $versioned);
        $this->assertSame('/assets/css/app.css', FileHashVersioning::unversion($versioned));
    }

    public function testFileHashCanGoIntoTheQueryString(): void
    {
        $versioned = (new FileHashVersioning($this->publicDir, FileHashVersioning::IN_QUERY))->version('/assets/css/app.css');

        $this->assertMatchesRegularExpression('#^/assets/css/app\.css\?v=[0-9a-f]{32}$#', $versioned);
    }

    public function testTheHashFollowsTheContent(): void
    {
        $versioning = new FileHashVersioning($this->publicDir);
        $before = $versioning->version('/assets/css/app.css');

        // A later mtime, so the per-process cache notices the change.
        file_put_contents($this->publicDir.'/assets/css/app.css', 'body{color:blue}');
        touch($this->publicDir.'/assets/css/app.css', time() + 2);

        $this->assertNotSame($before, $versioning->version('/assets/css/app.css'));
    }

    public function testUnknownFilesAbsoluteUrlsAndQueriesPassThrough(): void
    {
        $versioning = new FileHashVersioning($this->publicDir);

        $this->assertSame('/assets/css/missing.css', $versioning->version('/assets/css/missing.css'));
        $this->assertSame('https://cdn.example.com/app.css', $versioning->version('https://cdn.example.com/app.css'));
        $this->assertSame('/assets/css/app.css?x=1', $versioning->version('/assets/css/app.css?x=1'));
    }

    public function testAFileWithoutAnExtensionIsVersionedInTheQuery(): void
    {
        $this->assertMatchesRegularExpression('#^/assets/README\?v=[0-9a-f]{32}$#', (new FileHashVersioning($this->publicDir))->version('/assets/README'));
    }

    public function testUnversionRecognisesOnlyVersionedNames(): void
    {
        $this->assertNull(FileHashVersioning::unversion('/assets/css/app.css'));
        $this->assertNull(FileHashVersioning::unversion('/assets/css/app.min.css'));
        $this->assertSame('/a/b.js', FileHashVersioning::unversion('/a/b.'.str_repeat('a', 32).'.js'));
    }

    public function testRejectsAnUnknownPlacement(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new FileHashVersioning($this->publicDir, 'header');
    }

    public function testManifestReadsAFlatMap(): void
    {
        $manifest = $this->publicDir.'/manifest.json';
        file_put_contents($manifest, json_encode(['/assets/css/app.css' => '/assets/css/app.3f2a1c.css']));

        $versioning = new ManifestVersioning($manifest);

        $this->assertSame('/assets/css/app.3f2a1c.css', $versioning->version('/assets/css/app.css'));
        $this->assertSame('/assets/css/other.css', $versioning->version('/assets/css/other.css'));
    }

    public function testManifestReadsViteEntries(): void
    {
        $manifest = $this->publicDir.'/manifest.json';
        file_put_contents($manifest, json_encode(['src/app.js' => ['file' => 'assets/app-3f2a1c.js', 'css' => ['assets/app-9b.css']]]));

        $this->assertSame('/build/assets/app-3f2a1c.js', (new ManifestVersioning($manifest, '/build'))->version('src/app.js'));
    }

    public function testAMissingManifestVersionsNothing(): void
    {
        $this->assertSame('/x.js', (new ManifestVersioning($this->publicDir.'/nope.json'))->version('/x.js'));
    }

    public function testAManifestThatIsNotAnObjectIsAnError(): void
    {
        $manifest = $this->publicDir.'/manifest.json';
        file_put_contents($manifest, '"just a string"');

        $this->expectException(\RuntimeException::class);

        (new ManifestVersioning($manifest))->version('/x.js');
    }

    public function testIntegrityIsKeyedByRootRelativePath(): void
    {
        $integrity = new AssetIntegrity(['assets/js/app.js' => 'sha384-abc']);

        $this->assertSame('sha384-abc', $integrity->for('/assets/js/app.js'));
        $this->assertSame('sha384-abc', $integrity->for('assets/js/app.js'));
        $this->assertNull($integrity->for('/assets/js/other.js'));
        $this->assertFalse($integrity->isEmpty());
    }

    public function testIntegrityFromAMissingFileIsEmpty(): void
    {
        $this->assertTrue(AssetIntegrity::fromFile($this->publicDir.'/sri.php')->isEmpty());
    }
}
