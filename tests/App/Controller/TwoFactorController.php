<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\App\Controller;

use Modufolio\Appkit\Security\Csrf\CsrfTokenManagerInterface;
use Modufolio\Appkit\Security\Token\TokenStorageInterface;
use Modufolio\Appkit\Security\Token\TwoFactorToken;
use Modufolio\Appkit\Security\Token\UsernamePasswordToken;
use Modufolio\Appkit\Security\TwoFactor\TotpService;
use Modufolio\Appkit\Security\User\UserInterface;
use Modufolio\Psr7\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Two-Factor Authentication Controller (test stub)
 *
 * Reduced test-case controller for testing the 2FA flow through
 * TotpService and AppSecurity hardcoded routes (/2fa, /2fa/cancel).
 */
class TwoFactorController
{
    public function __construct(
        private TotpService $totpService,
        private TokenStorageInterface $tokenStorage,
        private SessionInterface $session,
        private CsrfTokenManagerInterface $csrfTokenManager,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * Get current 2FA status for the authenticated user
     */
    #[Route(path: '/api/2fa/status', name: 'api_2fa_status', methods: ['GET'])]
    public function status(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->getAuthenticatedUser();

        $totpSecret = $this->totpService->getTotpSecret($user);
        $isEnabled = $totpSecret !== null && $totpSecret->isEnabled() && $totpSecret->isConfirmed();

        return Response::json([
            'enabled' => $isEnabled,
            'confirmed' => $totpSecret?->isConfirmed() ?? false,
        ]);
    }

    /**
     * Generate a new TOTP secret and QR code
     */
    #[Route(path: '/api/2fa/setup', name: 'api_2fa_setup', methods: ['POST'])]
    public function setup(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->getAuthenticatedUser();

        try {
            $totpSecret = $this->totpService->generateSecret($user);
            $provisioningUri = $this->totpService->getProvisioningUri($totpSecret);

            return Response::json([
                'success' => true,
                'provisioning_uri' => $provisioningUri,
                'secret' => $totpSecret->getSecret(),
            ]);
        } catch (\RuntimeException $e) {
            return Response::json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Verify TOTP code and enable 2FA
     */
    #[Route(path: '/api/2fa/enable', name: 'api_2fa_enable', methods: ['POST'])]
    public function enable(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->getAuthenticatedUser();
        $body = json_decode($request->getBody()->getContents(), true);
        $code = $body['code'] ?? '';

        if (empty($code)) {
            return Response::json(['success' => false, 'error' => 'Verification code is required.'], 400);
        }

        $totpSecret = $this->totpService->getTotpSecret($user);

        if ($totpSecret === null) {
            return Response::json(['success' => false, 'error' => 'No TOTP secret found. Please setup 2FA first.'], 400);
        }

        try {
            $success = $this->totpService->enableTwoFactor($totpSecret, $code);

            if ($success) {
                $backupCodes = $totpSecret->plainBackupCodes ?? [];

                return Response::json([
                    'success' => true,
                    'backup_codes' => $backupCodes,
                ]);
            }

            return Response::json(['success' => false, 'error' => 'Invalid verification code.'], 400);
        } catch (\RuntimeException $e) {
            return Response::json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /**
     * Disable 2FA for the authenticated user
     */
    #[Route(path: '/api/2fa/disable', name: 'api_2fa_disable', methods: ['POST'])]
    public function disable(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->getAuthenticatedUser();

        $this->totpService->disableTwoFactor($user);

        return Response::json(['success' => true]);
    }

    /**
     * Render the 2FA verification form (GET /2fa)
     * Entry point page — allowed through AppSecurity::isEntryPointPage when session has _2fa_token.
     */
    #[Route(path: '/2fa', name: '2fa_form', options: ['expose' => true], methods: ['GET'])]
    public function twoFactorForm(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->session->has('_2fa_token')) {
            return Response::redirect($this->urlGenerator->generate('login'));
        }

        $tokenData = $this->session->get('_2fa_token');
        $token = unserialize($tokenData);

        if (!$token instanceof TwoFactorToken) {
            $this->session->remove('_2fa_token');
            return Response::redirect($this->urlGenerator->generate('login'));
        }

        if ($token->isExpired()) {
            $this->session->remove('_2fa_token');
            $this->session->getFlashBag()->add('error', 'Two-factor authentication session expired.');
            return Response::redirect($this->urlGenerator->generate('login'));
        }

        $csrfToken = $this->csrfTokenManager->getToken('2fa_verify');

        return Response::json([
            'csrf_token' => $csrfToken->getValue(),
            'email' => $token->getUser()->getUserIdentifier(),
        ]);
    }

    /**
     * Verify 2FA code (POST /2fa)
     * Entry point page — allowed through AppSecurity::isEntryPointPage when session has _2fa_token.
     */
    #[Route(path: '/2fa', name: '2fa_verify', options: ['expose' => true], methods: ['POST'])]
    public function verifyTwoFactor(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->session->has('_2fa_token')) {
            return Response::redirect($this->urlGenerator->generate('login'));
        }

        $tokenData = $this->session->get('_2fa_token');
        $token = unserialize($tokenData);

        if (!$token instanceof TwoFactorToken) {
            $this->session->remove('_2fa_token');
            return Response::redirect($this->urlGenerator->generate('login'));
        }

        if ($token->isExpired()) {
            $this->session->remove('_2fa_token');
            $this->session->getFlashBag()->add('error', 'Two-factor authentication session expired.');
            return Response::redirect($this->urlGenerator->generate('login'));
        }

        $parsedBody = $request->getParsedBody();
        $csrfToken = $parsedBody['_csrf_token'] ?? null;

        if (!$this->csrfTokenManager->validateToken('2fa_verify', $csrfToken)) {
            $this->session->getFlashBag()->add('2fa_error', 'Invalid CSRF token');
            return Response::redirect($this->urlGenerator->generate('2fa_form'));
        }

        $user = $token->getUser();
        if (!$user instanceof UserInterface) {
            $this->session->remove('_2fa_token');
            return Response::redirect($this->urlGenerator->generate('login'));
        }

        $totpSecret = $this->totpService->getTotpSecret($user);
        if ($totpSecret === null || !$totpSecret->isEnabled()) {
            $this->session->remove('_2fa_token');
            $this->session->getFlashBag()->add('error', 'Two-factor authentication is not configured.');
            return Response::redirect($this->urlGenerator->generate('login'));
        }

        $totpCode = $parsedBody['totp_code'] ?? '';
        $backupCode = $parsedBody['backup_code'] ?? '';
        $verified = false;

        if (!empty($totpCode)) {
            try {
                $verified = $this->totpService->verifyCode($totpSecret, $totpCode);
            } catch (\RuntimeException $e) {
                $this->session->getFlashBag()->add('2fa_error', $e->getMessage());
                return Response::redirect($this->urlGenerator->generate('2fa_form'));
            }
        }

        if (!$verified && !empty($backupCode)) {
            $verified = $this->totpService->verifyBackupCode($totpSecret, $backupCode);
        }

        if (!$verified) {
            $this->session->getFlashBag()->add('2fa_error', 'Invalid authentication code');
            return Response::redirect($this->urlGenerator->generate('2fa_form'));
        }

        // Promote to full authentication token
        $fullToken = new UsernamePasswordToken($user, 'main', $user->getRoles());
        $this->tokenStorage->setToken($fullToken);
        $this->session->set('_security_main', serialize($fullToken));

        $this->session->remove('_2fa_token');

        $targetUrl = $this->session->get('_security.main.target_path', $this->urlGenerator->generate('home'));
        $this->session->remove('_security.main.target_path');

        return Response::redirect($targetUrl);
    }

    /**
     * Cancel 2FA and return to login (GET|POST /2fa/cancel)
     * Allowed through AppSecurity::isEntryPointPage when session has _2fa_token.
     */
    #[Route(path: '/2fa/cancel', name: '2fa_cancel', options: ['expose' => true], methods: ['GET', 'POST'])]
    public function cancelTwoFactor(): ResponseInterface
    {
        $this->session->remove('_2fa_token');
        $this->session->getFlashBag()->add('info', 'Two-factor authentication cancelled');

        return Response::redirect($this->urlGenerator->generate('login'));
    }

    private function getAuthenticatedUser(): UserInterface
    {
        $token = $this->tokenStorage->getToken();

        if ($token === null) {
            throw new \RuntimeException('Not authenticated.');
        }

        $user = $token->getUser();

        if (!$user instanceof UserInterface) {
            throw new \RuntimeException('Invalid user type.');
        }

        return $user;
    }
}
