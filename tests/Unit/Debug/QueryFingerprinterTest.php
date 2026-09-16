<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Debug;

use Modufolio\Appkit\Debug\QueryFingerprinter;
use Modufolio\Appkit\Doctrine\Middleware\Debug\DebugStack;
use Modufolio\Appkit\Doctrine\Middleware\Debug\Query;
use PHPUnit\Framework\TestCase;

/**
 * Pure: the fingerprinter only sees strings and numbers.
 */
class QueryFingerprinterTest extends TestCase
{
    public function testBindingsCollapseOntoOneShape(): void
    {
        $f = new QueryFingerprinter();

        $this->assertSame(
            $f->fingerprint('SELECT * FROM issues WHERE project_id = 8'),
            $f->fingerprint('SELECT * FROM issues WHERE project_id = 9'),
        );
    }

    public function testStringLiteralsAndWhitespaceAreNormalized(): void
    {
        $f = new QueryFingerprinter();

        $this->assertSame(
            $f->fingerprint("SELECT * FROM actors WHERE name = 'Al Pacino'"),
            $f->fingerprint("SELECT  *\n FROM actors\tWHERE name = 'Meryl Streep'"),
        );
    }

    public function testInListsOfDifferentLengthShareAShape(): void
    {
        $f = new QueryFingerprinter();

        $this->assertSame(
            $f->fingerprint('SELECT * FROM issues WHERE id IN (1, 2, 3)'),
            $f->fingerprint('SELECT * FROM issues WHERE id IN (7)'),
        );
    }

    public function testDifferentQueriesKeepDistinctShapes(): void
    {
        $f = new QueryFingerprinter();

        $this->assertNotSame(
            $f->fingerprint('SELECT * FROM issues WHERE project_id = 1'),
            $f->fingerprint('SELECT * FROM actors WHERE project_id = 1'),
        );
    }

    /**
     * Keyset next/prev probes hit one table with different predicates;
     * one shape for both would cry N+1 on a page behaving correctly.
     */
    public function testDifferentPredicatesOnTheSameTableStayDistinct(): void
    {
        $f = new QueryFingerprinter();

        $this->assertNotSame(
            $f->fingerprint('SELECT * FROM actors a0_ WHERE a0_.name > ? ORDER BY a0_.name ASC'),
            $f->fingerprint('SELECT * FROM actors a0_ WHERE a0_.name < ? ORDER BY a0_.name ASC'),
        );
    }

    public function testARepeatedShapeIsReportedAsASuspect(): void
    {
        $result = (new QueryFingerprinter())->analyze([
            ['sql' => 'SELECT * FROM projects', 'ms' => 1.5],
            ['sql' => 'SELECT * FROM issues WHERE project_id = 1', 'ms' => 0.5],
            ['sql' => 'SELECT * FROM issues WHERE project_id = 2', 'ms' => 0.25],
            ['sql' => 'SELECT * FROM issues WHERE project_id = 3', 'ms' => 0.25],
        ]);

        $this->assertCount(1, $result['suspects']);
        $this->assertSame(3, $result['suspects'][0]['count']);
        $this->assertSame(1.0, $result['suspects'][0]['totalMs']);
        $this->assertStringContainsString('FROM issues', $result['suspects'][0]['sql']);
        $this->assertSame(2, $result['distinctShapes']);
    }

    public function testRepeatsBelowTheThresholdAreNotSuspects(): void
    {
        $result = (new QueryFingerprinter())->analyze([
            ['sql' => 'SELECT * FROM issues WHERE project_id = 1', 'ms' => 0.5],
            ['sql' => 'SELECT * FROM issues WHERE project_id = 2', 'ms' => 0.5],
        ]);

        $this->assertSame([], $result['suspects']);
    }

    public function testPseudoStatementsAreIgnored(): void
    {
        $result = (new QueryFingerprinter())->analyze([
            ['sql' => 'CONNECT', 'ms' => 0.1],
            ['sql' => 'BEGINNING TRANSACTION', 'ms' => 0.1],
            ['sql' => 'COMMITTING TRANSACTION', 'ms' => 0.1],
            ['sql' => 'BEGINNING TRANSACTION', 'ms' => 0.1],
            ['sql' => 'COMMITTING TRANSACTION', 'ms' => 0.1],
            ['sql' => 'BEGINNING TRANSACTION', 'ms' => 0.1],
        ]);

        $this->assertSame([], $result['suspects']);
        $this->assertSame(0, $result['distinctShapes']);
    }

    public function testReadsTheDebugStacksOwnRecords(): void
    {
        $stack = new DebugStack();
        foreach ([1, 2, 3] as $id) {
            $stack->append(new Query('SELECT * FROM issues WHERE id = '.$id, [], [], 0.2));
        }

        $result = (new QueryFingerprinter())->analyzeStack($stack);

        $this->assertCount(1, $result['suspects']);
        $this->assertSame(3, $result['suspects'][0]['count']);
        $this->assertEqualsWithDelta(0.6, $result['suspects'][0]['totalMs'], 0.001);
    }

    public function testMostRepeatedSuspectComesFirst(): void
    {
        $queries = [];
        foreach (range(1, 3) as $i) {
            $queries[] = ['sql' => "SELECT * FROM a WHERE id = $i", 'ms' => 1];
        }
        foreach (range(1, 5) as $i) {
            $queries[] = ['sql' => "SELECT * FROM b WHERE id = $i", 'ms' => 1];
        }

        $suspects = (new QueryFingerprinter())->analyze($queries)['suspects'];

        $this->assertStringContainsString('FROM b', $suspects[0]['sql']);
        $this->assertStringContainsString('FROM a', $suspects[1]['sql']);
    }

    public function testAThresholdBelowTwoIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new QueryFingerprinter(1);
    }
}
