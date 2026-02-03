<?php

namespace Modufolio\Appkit\Resolver;

use Modufolio\Appkit\Attributes\CurrentUser;
use Modufolio\Appkit\Security\Token\TokenStorageInterface;
use Modufolio\Appkit\Security\User\UserInterface;

class UserResolver implements AttributeResolverInterface
{
    public function __construct(
        private TokenStorageInterface $tokenStorage,
    ) {
    }

    public function supports(\ReflectionParameter $parameter): bool
    {
        return !empty($parameter->getAttributes(CurrentUser::class));
    }


    public function resolve(\ReflectionParameter $parameter, array $providedParameters): ?UserInterface
    {
        return $this->tokenStorage->getToken()?->getUser();
    }
}
