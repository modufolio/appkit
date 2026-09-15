<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Resolver;

use Modufolio\Appkit\Attributes\CurrentUser;
use Modufolio\Appkit\Security\Exception\AuthenticationException;
use Modufolio\Appkit\Security\Token\TokenStorageInterface;
use Modufolio\Appkit\Security\User\UserInterface;

/**
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
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

    /**
     * @throws AuthenticationException when nobody is signed in and the parameter is not nullable
     */
    public function resolve(\ReflectionParameter $parameter, array $providedParameters): ?UserInterface
    {
        $user = $this->tokenStorage->getToken()?->getUser();

        // A non-nullable #[CurrentUser] on an anonymous request is an
        // authentication problem, not a TypeError: answer 401 (or the login
        // redirect), the same as any other request that needs a user.
        if (null === $user && !$parameter->allowsNull()) {
            throw new AuthenticationException(sprintf('Parameter "$%s" requires an authenticated user.', $parameter->getName()));
        }

        return $user;
    }
}
