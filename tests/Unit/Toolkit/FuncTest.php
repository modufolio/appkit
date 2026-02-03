<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Tests\Unit\Toolkit;

use Modufolio\Appkit\Toolkit\Func;
use PHPUnit\Framework\TestCase;

class FuncTest extends TestCase
{
    public function testCompose(): void
    {
        $addOne = static function ($x) {
            return $x + 1;
        };

        $double = static function ($x) {
            return $x * 2;
        };

        $subtractThree = static function ($x) {
            return $x - 3;
        };

        $composedFn = Func::compose($addOne, $double, $subtractThree);

        $result = $composedFn(5);
        $this->assertEquals(9, $result);
    }

    public function testPipe(): void
    {
        $functions = [
            function ($x) {
                return $x + 1;
            },
            function ($x) {
                return $x * 2;
            },
            function ($x) {
                return $x - 3;
            },
        ];

        $result = Func::pipe(5, ...$functions);
        $this->assertEquals(9, $result);
    }

    public function testReduce(): void
    {
        $sumFn = static function ($acc, $value) {
            return $acc + $value;
        };

        $values = [1, 2, 3, 4, 5];
        $result = Func::reduce($sumFn, $values, 0);

        $this->assertEquals(15, $result);
    }


}
