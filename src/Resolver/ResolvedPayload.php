<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Resolver;

use Modufolio\Appkit\Form\ValidationResult;

/**
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
final readonly class ResolvedPayload
{
    public function __construct(
        public object $payload,
        public ValidationResult $validationResult,
    ) {
    }
}
