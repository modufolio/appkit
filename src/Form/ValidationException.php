<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Form;

use RuntimeException;

/**
 * Exception thrown when form validation fails.
 *
 * @package   Appkit Core
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
class ValidationException extends RuntimeException
{
    public function __construct(
        private readonly ValidationResult $result,
        string $message = 'Validation failed'
    ) {
        parent::__construct($message);
    }

    /**
     * Get the validation result.
     */
    public function getValidationResult(): ValidationResult
    {
        return $this->result;
    }

    /**
     * Get validation errors.
     *
     * @return array<string, array<string>>
     */
    public function getErrors(): array
    {
        return $this->result->errors();
    }
}
