<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Doctrine;

use Modufolio\Appkit\Doctrine\Middleware\Debug\DebugStack;
use Modufolio\Appkit\Doctrine\Middleware\Debug\Query;
use Modufolio\Appkit\Tests\Case\AppTestCase;

/**
 * Query origins: the application file and line that issued a query, so an
 * N+1 report can say which loop. A backtrace per query, so dev only.
 */
class DebugStackOriginTest extends AppTestCase
{
    public function testAnOriginIsTheFirstApplicationFrameAboveTheMiddleware(): void
    {
        $stack = new DebugStack(collectOrigin: true);

        $line = __LINE__ + 1;
        $stack->append(new Query('SELECT 1', [], [], 0.0));

        [$query] = $stack->getQueries();
        $this->assertSame(__FILE__, $query->file);
        $this->assertSame($line, $query->line);
        $this->assertSame('SELECT 1', $query->sql, 'Everything else is carried over.');
    }

    public function testOriginsAreOffUnlessAskedFor(): void
    {
        $stack = new DebugStack();
        $stack->append(new Query('SELECT 1', [], [], 0.0));

        $this->assertNull($stack->getQueries()[0]->file);
        $this->assertFalse($stack->isCollectingOrigin());

        $stack->collectOrigin();
        $stack->append(new Query('SELECT 2', [], [], 0.0));
        $this->assertSame(__FILE__, $stack->getQueries()[1]->file);
    }

    public function testAQueryThatAlreadyKnowsItsOriginKeepsIt(): void
    {
        $stack = new DebugStack(collectOrigin: true);
        $stack->append(new Query('SELECT 1', [], [], 0.0, '/app/src/Repository/Posts.php', 42));

        $this->assertSame('/app/src/Repository/Posts.php', $stack->getQueries()[0]->file);
        $this->assertSame(42, $stack->getQueries()[0]->line);
    }

    public function testTheKernelCollectsOriginsInDevOnly(): void
    {
        // The test app runs in the test environment: no backtraces.
        $this->assertFalse($this->app()->debugStack->isCollectingOrigin());
    }
}
