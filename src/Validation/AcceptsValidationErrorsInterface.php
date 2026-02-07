<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Validation;

use Symfony\Component\Validator\ConstraintViolationListInterface;

interface AcceptsValidationErrorsInterface
{
    public function setViolations(ConstraintViolationListInterface $violations): void;
}
