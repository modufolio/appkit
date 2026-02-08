<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Resolver;

use Modufolio\Appkit\Form\ValidationResult;

final readonly class ResolvedPayload
{
    public function __construct(
        public object $payload,
        public ValidationResult $validationResult
    ) {
    }
}
