<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Core;

/**
 * Interface for classes that can be reset to their initial state.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
interface ResetInterface
{
    /**
     * Reset the object to its initial state.
     *
     * This method should clear all request-scoped state and properly dispose
     * of resources like database connections to prevent memory leaks.
     */
    public function reset(): void;
}
