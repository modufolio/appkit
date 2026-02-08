<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Form;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Base class for form objects that encapsulate validation logic.
 *
 * Form objects solve the problem of duplicated validation across models,
 * controllers, and different contexts. They are reusable, testable, and
 * keep entities clean from validation concerns.
 *
 * Designed to be stateless for RoadRunner compatibility.
 *
 * Example:
 * ```php
 * class CreateUserForm extends Form
 * {
 *     protected function rules(): Constraint
 *     {
 *         return new Assert\Collection([
 *             'email' => [
 *                 new Assert\NotBlank(),
 *                 new Assert\Email()
 *             ],
 *             'password' => [
 *                 new Assert\NotBlank(),
 *                 new Assert\Length(['min' => 8])
 *             ],
 *             'name' => new Assert\NotBlank(),
 *         ]);
 *     }
 * }
 *
 * // In controller
 * $form = new CreateUserForm();
 * $result = $form->validate($request->all());
 *
 * if ($result->hasErrors()) {
 *     return $this->error('Validation failed', $result->errors());
 * }
 * ```
 *
 * @package   Appkit Core
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
abstract class Form
{
    private ValidatorInterface|null $validator = null;

    /**
     * Define validation rules.
     *
     * @return Constraint The validation constraints
     */
    abstract protected function rules(): Constraint;

    /**
     * Validate the given data against the form rules.
     *
     * @param array<string, mixed> $data The data to validate
     * @return ValidationResult The validation result
     */
    public function validate(array $data): ValidationResult
    {
        $violations = $this->getValidator()->validate($data, $this->rules());

        return ValidationResult::fromViolations($violations);
    }

    /**
     * Get or create the validator instance.
     *
     * Note: We don't store the validator in a static property to avoid
     * memory leaks in RoadRunner. It's lightweight enough to recreate.
     */
    protected function getValidator(): ValidatorInterface
    {
        if ($this->validator === null) {
            $this->validator = Validation::createValidator();
        }

        return $this->validator;
    }

    /**
     * Set a custom validator (useful for testing).
     */
    public function setValidator(ValidatorInterface $validator): void
    {
        $this->validator = $validator;
    }
}
