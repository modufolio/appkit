<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Resolver;

use Modufolio\Appkit\Attributes\MapQueryString;
use Modufolio\Appkit\Attributes\MapRequestPayload;
use Modufolio\Appkit\Form\ValidationResult;
use Psr\Http\Message\ServerRequestInterface;

class TestPayloadController
{
    public function storeWithThrow(
        ServerRequestInterface $request,
        #[MapRequestPayload] TestCreateUserDto $dto
    ): void {
    }

    public function storeWithValidationResult(
        ServerRequestInterface $request,
        #[MapRequestPayload(throwOnError: false)] TestCreateUserDto $dto,
        ?ValidationResult $result = null
    ): void {
    }

    public function listWithValidationResult(
        ServerRequestInterface $request,
        #[MapQueryString(throwOnError: false)] TestCreateUserDto $query,
        ?ValidationResult $result = null
    ): void {
    }

    public function storeDefault(
        ServerRequestInterface $request,
        #[MapRequestPayload] TestCreateUserDto $dto
    ): void {
    }
}
