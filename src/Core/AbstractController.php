<?php

namespace Modufolio\Appkit\Core;

use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
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
    protected EntityManagerInterface $entityManager;
    protected FlashBagInterface $flashBag;
    protected TokenStorageInterface $tokenStorage;
    protected UrlGeneratorInterface $urlGenerator;
    protected UserProviderInterface $userProvider;
    protected ValidatorInterface $validator;
    /**
     * For `$this->inertia->flash()` before a redirect, or to render a page
     * yourself. Returning an Inertia page needs neither — the kernel
     * finishes it. Always set: where the host never wired Inertia, using it
     * fails with the message that names what to add
     * ({@see AppInterface::inertia()}).
     */
    protected InertiaRendererInterface $inertia;

    /**
     * @throws DbalException
     */
    /** @internal Called by the kernel when it builds the controller. */
    final public function setSubscribedServices(AppInterface $app): void
    {
        $this->entityManager = $app->entityManager();
        $this->flashBag = $app->session()->getFlashBag();
        $this->tokenStorage = $app->tokenStorage();
        $this->urlGenerator = $app->urlGenerator();
        $this->userProvider = $app->userProvider();
        $this->validator = $app->validator();

        // Never left unassigned: reading an uninitialized typed property
        // raises a fatal Error, which says nothing about the module the host
        // forgot. The unwired renderer raises the kernel's own message.
        try {
            $this->inertia = $app->inertia();
        } catch (\LogicException) {
            $this->inertia = new UnwiredRenderer();
        }
    }

    protected function getUser(): ?UserInterface
    {
        return $this->tokenStorage->getToken()?->getUser();
    }
}
