<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\App\Controller;

/**
 * Fixture for the controller-wiring tests.
 *
 * Both parameters are strings on purpose: a positional list that drifts out of
 * sync with the constructor cannot be caught by a type error here, which is the
 * failure mode named keys are meant to prevent.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
class WiringController
{
    public function __construct(
        public string $alpha,
        public string $beta,
    ) {
    }
}
