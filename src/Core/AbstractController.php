<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Core;

use Doctrine\ORM\EntityManagerInterface;
use Modufolio\Appkit\DependencyInjection\ServiceLocator;
use Modufolio\Appkit\Inertia\InertiaRendererInterface;
use Modufolio\Appkit\Inertia\UnwiredRenderer;
use Modufolio\Appkit\Security\Token\TokenStorageInterface;
use Modufolio\Appkit\Security\User\UserInterface;
use Modufolio\Appkit\Security\User\UserProviderInterface;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Abstract base class for Controllers.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
/**
 * The services every controller reaches for, as protected properties. The
 * kernel fills them in right after construction — the only controller it
 * treats that way, so nothing but this class ever sees the app itself. A
 * controller that needs anything else declares it in its constructor and in
 * config/controllers.php.
 */
class AbstractController
{
    private ServiceLocator $services;

    protected EntityManagerInterface $entityManager {
        get => $this->services->get(EntityManagerInterface::class);
    }

    protected FlashBagInterface $flashBag {
        get => $this->services->get(FlashBagInterface::class);
    }

    protected TokenStorageInterface $tokenStorage {
        get => $this->services->get(TokenStorageInterface::class);
    }

    protected UrlGeneratorInterface $urlGenerator {
        get => $this->services->get(UrlGeneratorInterface::class);
    }

    protected UserProviderInterface $userProvider {
        get => $this->services->get(UserProviderInterface::class);
    }

    protected ValidatorInterface $validator {
        get => $this->services->get(ValidatorInterface::class);
    }
    /**
     * For `$this->inertia->flash()` before a redirect, or to render a page
     * yourself. Returning an Inertia page needs neither — the kernel
     * finishes it. Always set: where the host never wired Inertia, using it
     * fails with the message that names what to add
     * ({@see AppInterface::inertia()}).
     */
    protected InertiaRendererInterface $inertia {
        get => $this->services->get(InertiaRendererInterface::class);
    }

    /** @internal Called by the kernel when it builds the controller. */
    final public function setSubscribedServices(AppInterface $app): void
    {
        // Declared here, built on first use. A controller rarely touches every
        // one of these, and two of them cost: reaching for the flash bag or the
        // Inertia renderer starts the session, and a session cookie makes the
        // response uncacheable by every shared cache.
        $this->services = new ServiceLocator([
            EntityManagerInterface::class => static fn () => $app->entityManager(),
            FlashBagInterface::class => static fn () => $app->session()->getFlashBag(),
            TokenStorageInterface::class => static fn () => $app->tokenStorage(),
            UrlGeneratorInterface::class => static fn () => $app->urlGenerator(),
            UserProviderInterface::class => static fn () => $app->userProvider(),
            ValidatorInterface::class => static fn () => $app->validator(),
            // Never left unresolvable: reading an uninitialized typed property
            // raises a fatal Error, which says nothing about the module the
            // host forgot. The unwired renderer raises the kernel's own message.
            InertiaRendererInterface::class => static function () use ($app): InertiaRendererInterface {
                try {
                    return $app->inertia();
                } catch (\LogicException) {
                    return new UnwiredRenderer();
                }
            },
        ]);
    }

    protected function getUser(): ?UserInterface
    {
        return $this->tokenStorage->getToken()?->getUser();
    }
}
