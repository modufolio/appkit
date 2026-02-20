<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Form;

use Modufolio\Appkit\Form\Form;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\ValidatorBuilder;

class TestContactForm extends Form
{
    protected function rules(): Constraint
    {
        return new Assert\Collection([
            'email' => [
                new Assert\NotBlank(),
                new Assert\Email(),
            ],
            'name' => [
                new Assert\NotBlank(),
                new Assert\Length(['min' => 2, 'max' => 100]),
            ],
            'message' => [
                new Assert\NotBlank(),
                new Assert\Length(['min' => 10]),
            ],
        ]);
    }
}

class TestLoginForm extends Form
{
    protected function rules(): Constraint
    {
        return new Assert\Collection([
            'email' => [
                new Assert\NotBlank(),
                new Assert\Email(),
            ],
            'password' => [
                new Assert\NotBlank(),
                new Assert\Length(['min' => 6]),
            ],
        ]);
    }
}

class FormTest extends TestCase
{
    public function testValidateValidData(): void
    {
        $form = new TestContactForm();
        $data = [
            'email' => 'test@example.com',
            'name' => 'John Doe',
            'message' => 'This is a test message',
        ];

        $result = $form->validate($data);

        $this->assertTrue($result->isValid());
        $this->assertFalse($result->hasErrors());
        $this->assertEmpty($result->errors());
    }

    public function testValidateInvalidEmail(): void
    {
        $form = new TestContactForm();
        $data = [
            'email' => 'invalid-email',
            'name' => 'John Doe',
            'message' => 'This is a test message',
        ];

        $result = $form->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertTrue($result->hasErrors());
        $errors = $result->errors();
        $this->assertArrayHasKey('email', $errors);
    }

    public function testValidateBlankEmail(): void
    {
        $form = new TestContactForm();
        $data = [
            'email' => '',
            'name' => 'John Doe',
            'message' => 'This is a test message',
        ];

        $result = $form->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertTrue($result->hasErrors());
        $this->assertArrayHasKey('email', $result->errors());
    }

    public function testValidateMultipleErrors(): void
    {
        $form = new TestContactForm();
        $data = [
            'email' => 'invalid',
            'name' => 'J', // Too short
            'message' => 'Short', // Too short
        ];

        $result = $form->validate($data);

        $this->assertFalse($result->isValid());
        $errors = $result->errors();
        $this->assertCount(3, $errors); // email, name, message all have errors
    }

    public function testValidationResultFirst(): void
    {
        $form = new TestContactForm();
        $data = [
            'email' => 'invalid',
            'name' => 'Jo',
            'message' => 'Test',
        ];

        $result = $form->validate($data);

        // Since there are errors, first() should return error messages
        $emailError = $result->first('email');
        $nameError = $result->first('name');

        // If there are no errors for a field, first() returns null
        $this->assertNull($result->first('nonexistent'));

        // At least one of these should have an error
        $this->assertTrue($emailError !== null || $nameError !== null);
    }

    public function testValidationResultMessages(): void
    {
        $form = new TestContactForm();
        $data = [
            'email' => 'invalid',
            'name' => '',
            'message' => '',
        ];

        $result = $form->validate($data);

        $messages = $result->messages();
        $this->assertNotEmpty($messages);
        $this->assertIsArray($messages);
    }

    public function testValidationResultViolations(): void
    {
        $form = new TestContactForm();
        $data = [
            'email' => 'invalid',
            'name' => 'Valid',
            'message' => 'This is a valid message',
        ];

        $result = $form->validate($data);

        $violations = $result->violations();
        $this->assertNotNull($violations);
    }

    public function testDifferentFormValidation(): void
    {
        $form = new TestLoginForm();
        $data = [
            'email' => 'user@example.com',
            'password' => 'securepass123',
        ];

        $result = $form->validate($data);

        $this->assertTrue($result->isValid());
    }

    public function testLoginFormInvalidPassword(): void
    {
        $form = new TestLoginForm();
        $data = [
            'email' => 'user@example.com',
            'password' => '123', // Too short
        ];

        $result = $form->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertArrayHasKey('password', $result->errors());
    }

    public function testValidationExceptionOnThrowIfFailed(): void
    {
        $form = new TestContactForm();
        $data = [
            'email' => 'invalid',
            'name' => 'John',
            'message' => 'Valid message here',
        ];

        $result = $form->validate($data);

        $this->expectException(\Modufolio\Appkit\Form\ValidationException::class);
        $result->throwIfFailed();
    }

    public function testNoExceptionOnThrowIfFailedWhenValid(): void
    {
        $form = new TestContactForm();
        $data = [
            'email' => 'valid@example.com',
            'name' => 'John Doe',
            'message' => 'This is a valid message',
        ];

        $result = $form->validate($data);

        // Should not throw
        $result->throwIfFailed();
        $this->assertTrue(true);
    }
}
