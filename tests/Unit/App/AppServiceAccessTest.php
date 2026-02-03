<?php

namespace Modufolio\Appkit\Tests\Unit\App;

use Modufolio\Appkit\Tests\Case\AppTestCase;
use Modufolio\Appkit\Exception\ExceptionHandler;
use Modufolio\Appkit\Resolver\ParameterResolverInterface;
use Modufolio\Appkit\Security\Token\TokenStorageInterface;
use Modufolio\Appkit\Security\User\UserProviderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Modufolio\Psr7\Http\EmitterInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class AppServiceAccessTest extends AppTestCase
{

    #[DataProvider('serviceAccessProvider')]
    public function testAppServiceAccess(string $method, string $expectedClass): void
    {
        $app = $this->app();


        $service = $app->$method();

        $this->assertInstanceOf($expectedClass, $service);
    }

    public static function serviceAccessProvider(): array
    {
        return [
            'Emitter'             => ['emitter', EmitterInterface::class],
            'EntityManager'       => ['entityManager', EntityManagerInterface::class],
            'ExceptionHandler'    => ['exceptionHandler', ExceptionHandler::class],
            'ParameterResolver'   => ['parameterResolver', ParameterResolverInterface::class],
            'Serializer'          => ['serializer', SerializerInterface::class],
            'Session'             => ['session', SessionInterface::class],
            'TokenStorage'        => ['tokenStorage', TokenStorageInterface::class],
            'Validator'           => ['validator', ValidatorInterface::class],
        ];
    }


}
