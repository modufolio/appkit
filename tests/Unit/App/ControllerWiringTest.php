<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\App;

use Modufolio\Appkit\Tests\App\Controller\WiringController;
use Modufolio\Appkit\Tests\Case\AppTestCase;

/**
 * The shapes `config/controllers.php` accepts.
 *
 * A plain list is spread positionally, an array keyed by constructor parameter
 * name is spread as named arguments. The named form is the safer one: a
 * positional list that drifts out of sync with the constructor transposes the
 * arguments silently whenever the mismatched parameters share a type.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
class ControllerWiringTest extends AppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app()->setParameter('alpha', 'A');
        $this->app()->setParameter('beta', 'B');
    }

    public function testPositionalListIsPassedInOrder(): void
    {
        $controller = $this->wire(['%alpha%', '%beta%']);

        $this->assertSame('A', $controller->alpha);
        $this->assertSame('B', $controller->beta);
    }

    public function testNamedKeysArePassedAsNamedArguments(): void
    {
        $controller = $this->wire(['alpha' => '%alpha%', 'beta' => '%beta%']);

        $this->assertSame('A', $controller->alpha);
        $this->assertSame('B', $controller->beta);
    }

    public function testNamedKeysIgnoreTheOrderTheyAreWrittenIn(): void
    {
        $controller = $this->wire(['beta' => '%beta%', 'alpha' => '%alpha%']);

        $this->assertSame('A', $controller->alpha);
        $this->assertSame('B', $controller->beta);
    }

    /**
     * The reason to prefer named keys: this mistake cannot be type-checked.
     */
    public function testPositionalListTransposesSilentlyWhenItDriftsOutOfSync(): void
    {
        $controller = $this->wire(['%beta%', '%alpha%']);

        $this->assertSame('B', $controller->alpha);
        $this->assertSame('A', $controller->beta);
    }

    public function testUnknownKeyIsRejectedAtConstruction(): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Unknown named parameter $gamma');

        $this->wire(['alpha' => '%alpha%', 'beta' => '%beta%', 'gamma' => '%alpha%']);
    }

    /**
     * @param array<int|string, mixed> $dependencies
     */
    private function wire(array $dependencies): WiringController
    {
        $this->app()->withController(WiringController::class, $dependencies);

        $controller = $this->app()->getController(WiringController::class);
        $this->assertInstanceOf(WiringController::class, $controller);

        return $controller;
    }
}
