<?php

namespace Modufolio\Appkit\Core;

use Modufolio\Appkit\Security\Token\TokenStorageInterface;
use Modufolio\Appkit\Security\User\UserInterface;
use Modufolio\Appkit\Security\User\UserProviderInterface;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManagerInterface;
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
class AbstractController
{
    protected EntityManagerInterface $entityManager;
    protected FlashBagInterface $flashBag;
    protected TokenStorageInterface $tokenStorage;
    protected UrlGeneratorInterface $urlGenerator;
    protected UserProviderInterface $userProvider;
    protected ValidatorInterface $validator;

    /**
     * @throws Exception
     */
    public function setSubscribedServices(AppInterface $app): void
    {
        $this->entityManager = $app->entityManager();
        $this->flashBag = $app->session()->getFlashBag();
        $this->tokenStorage = $app->tokenStorage();
        $this->urlGenerator = $app->urlGenerator();
        $this->userProvider = $app->userProvider();
        $this->validator = $app->validator();
    }

    protected function getUser(): ?UserInterface
    {
        return $this->tokenStorage->getToken()?->getUser();
    }
}
