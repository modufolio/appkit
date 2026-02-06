<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Form;

use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Immutable validation result object.
 *
 * This class is readonly and safe for RoadRunner - no mutable state.
 *
 * @package   Appkit Core
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
final readonly class ValidationResult
{
    public function __construct(
        private ConstraintViolationListInterface $violations
    ) {}

    /**
     * Check if validation passed.
     */
    public function passed(): bool
    {
        return $this->violations->count() === 0;
    }

    /**
     * Check if validation failed.
     */
    public function failed(): bool
    {
        return !$this->passed();
    }

    /**
     * Get all validation errors as an array.
     *
     * @return array<string, array<string>> Field names mapped to error messages
     */
    public function errors(): array
    {
        $errors = [];

        foreach ($this->violations as $violation) {
            $field = $violation->getPropertyPath();
            $message = $violation->getMessage();

            // Remove the surrounding brackets from property path
            // e.g., "[email]" becomes "email"
            $field = trim($field, '[]');

            if (!isset($errors[$field])) {
                $errors[$field] = [];
            }

            $errors[$field][] = $message;
        }

        return $errors;
    }

    /**
     * Get the first error message for a field.
     *
     * @return string|null
     */
    public function first(string $field): string|null
    {
        $errors = $this->errors();

        return $errors[$field][0] ?? null;
    }

    /**
     * Get all error messages as a flat array.
     *
     * @return array<string>
     */
    public function messages(): array
    {
        $messages = [];

        foreach ($this->violations as $violation) {
            $messages[] = $violation->getMessage();
        }

        return $messages;
    }

    /**
     * Get the underlying violations list.
     */
    public function violations(): ConstraintViolationListInterface
    {
        return $this->violations;
    }

    /**
     * Throw an exception if validation failed.
     *
     * @throws ValidationException
     */
    public function throwIfFailed(): void
    {
        if ($this->failed()) {
            throw new ValidationException($this);
        }
    }
}
