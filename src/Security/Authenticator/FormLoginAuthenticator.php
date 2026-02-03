<?php

namespace Modufolio\Appkit\Security\Authenticator;

use App\Entity\User;
use App\Logger\Log;
use Modufolio\Appkit\Security\BruteForce\BruteForceProtectionInterface;
use Modufolio\Appkit\Security\Csrf\CsrfTokenManagerInterface;
use Modufolio\Appkit\Security\Exception\AuthenticationException;
use Modufolio\Appkit\Security\Exception\InvalidCsrfTokenException;
use Modufolio\Appkit\Security\Token\TokenInterface;
use Modufolio\Appkit\Security\Token\TwoFactorToken;
use Modufolio\Appkit\Security\Token\UsernamePasswordToken;
use Modufolio\Appkit\Security\TwoFactor\TotpService;
use Modufolio\Appkit\Security\User\PasswordAuthenticatedUserInterface;
use Modufolio\Appkit\Security\User\UserCheckerInterface;
use Modufolio\Appkit\Security\User\UserInterface;
use Modufolio\Appkit\Security\User\UserProviderInterface;
use Modufolio\Psr7\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;

class FormLoginAuthenticator extends AbstractAuthenticator
{
    private array $options;

    public function __construct(
        private UserProviderInterface $userProvider,
        private BruteForceProtectionInterface $bruteForceProtection,
        private CsrfTokenManagerInterface $csrfTokenManager,
        private FlashBagAwareSessionInterface $session,
        private ?TotpService $totpService = null,
        private ?UserCheckerInterface $userChecker = null,
        array $options = []
    ) {
        $this->options = array_merge([
            'username_parameter' => 'email',
            'password_parameter' => 'password',
            'check_path' => '/login',
            'post_only' => true,
            'csrf_parameter' => '_csrf_token',
            'csrf_token_id' => 'authenticate',
            'totp_parameter' => 'totp_code',
            'backup_code_parameter' => 'backup_code',
        ], $options);
    }

    public function supports(ServerRequestInterface $request): bool
    {
        return $request->getMethod() === 'POST' && $request->getUri()->getPath() === $this->options['check_path'];
    }

    /**
     * @throws AuthenticationException
     */
    public function authenticate(ServerRequestInterface $request): UserInterface
    {
        [$identifier, $password] = $this->extractCredentials($request);
        $clientIp = $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown';

        // Validate CSRF token
        $this->validateCsrfToken($request);

        // Check if account is locked due to brute force protection
        if ($this->bruteForceProtection->isLocked($identifier, $clientIp)) {
            $remainingTime = $this->bruteForceProtection->getRemainingLockoutTime($identifier, $clientIp);
            $failureCount = $this->bruteForceProtection->getFailureCount($identifier, $clientIp);

            Log::warning('Authentication blocked: Account temporarily locked due to too many failed attempts', [
                'username' => $identifier,
                'ip' => $clientIp,
                'failure_count' => $failureCount,
                'remaining_lockout_seconds' => $remainingTime,
            ]);

            throw new AuthenticationException(
                sprintf('Too many failed login attempts. Account is locked for %d more seconds.', $remainingTime)
            );
        }

        try {
            $user = $this->userProvider->loadUserByIdentifier($identifier);

            // SOC 2 Phase 3: Check account lifecycle status before authentication
            $this->userChecker?->checkPreAuth($user);

            if (!$user instanceof PasswordAuthenticatedUserInterface) {
                $this->bruteForceProtection->recordFailure($identifier, $clientIp);

                Log::warning('Authentication failed: User does not support password authentication', [
                    'username' => $identifier,
                    'ip' => $clientIp,
                    'user_agent' => $request->getHeaderLine('User-Agent'),
                    'failure_count' => $this->bruteForceProtection->getFailureCount($identifier, $clientIp),
                ]);
                throw new AuthenticationException('User does not support password authentication.');
            }

            if (password_verify($password, $user->getPassword()) === false) {
                $this->bruteForceProtection->recordFailure($identifier, $clientIp);

                Log::warning('Authentication failed: Invalid credentials', [
                    'username' => $identifier,
                    'ip' => $clientIp,
                    'user_agent' => $request->getHeaderLine('User-Agent'),
                    'failure_count' => $this->bruteForceProtection->getFailureCount($identifier, $clientIp),
                ]);
                throw new AuthenticationException('Invalid credentials');
            }

            // SOC 2 Phase 3: Check post-authentication requirements
            $this->userChecker?->checkPostAuth($user);

            // Successful password authentication - reset failure counter
            $this->bruteForceProtection->recordSuccess($identifier, $clientIp);

            // Check if 2FA is enabled for this user
            if ($this->totpService !== null && $user instanceof User) {
                $totpSecret = $this->totpService->getTotpSecret($user);

                if ($totpSecret !== null && $totpSecret->isEnabled()) {
                    Log::info('Password verified, 2FA required', [
                        'username' => $identifier,
                        'ip' => $clientIp,
                    ]);

                    // Create partial token for 2FA flow
                    $twoFactorToken = new TwoFactorToken($user, 'main');

                    // Store the partial token in session
                    $this->session->set('_2fa_token', serialize($twoFactorToken));
                    $this->session->set('_2fa_user_id', $user->getId());

                    // Throw exception with 2FA flag for proper response handling
                    $exception = new AuthenticationException('Two-factor authentication required');
                    $exception->setRequires2FA(true);
                    $exception->setUser($user);
                    throw $exception;
                }
            }

            // No 2FA required - full authentication successful
            Log::info('Successful login (no 2FA)', [
                'username' => $identifier,
                'ip' => $clientIp,
                'user_agent' => $request->getHeaderLine('User-Agent'),
            ]);

            return $user;
        } catch (AuthenticationException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->bruteForceProtection->recordFailure($identifier, $clientIp);

            Log::error('Authentication error: ' . $e->getMessage(), [
                'username' => $identifier,
                'ip' => $clientIp,
                'exception' => get_class($e),
                'failure_count' => $this->bruteForceProtection->getFailureCount($identifier, $clientIp),
            ]);
            throw new AuthenticationException('Authentication failed');
        }
    }

    /**
     * Handle unauthorized response for Inertia.js
     *
     * Inertia expects:
     * - Redirects (302/303) for navigation
     * - Session-based error handling for validation errors
     * - NOT JSON responses for form submissions
     */
    public function unauthorizedResponse(ServerRequestInterface $request, AuthenticationException $exception): ResponseInterface
    {
        // Check if this is a 2FA required response
        if ($exception->isRequires2FA()) {
            // For Inertia, always use redirect responses
            // Inertia will handle this as a client-side redirect
            return Response::redirect('/2fa', 303);
        }

        // Regular authentication failure
        // Store error in session for Inertia to pick up
        $this->session->getFlashBag()->add('error', $exception->getMessage());

        // Check if this is an Inertia request
        if ($this->isInertiaRequest($request)) {
            // Inertia requests expect a 303 redirect after form submission
            return Response::redirect('/login', 303);
        }

        // Non-Inertia request (shouldn't happen in your app, but good to handle)
        return Response::redirect('/login');
    }

    /**
     * Check if this is an Inertia request
     *
     * Inertia requests always include the X-Inertia header
     */
    private function isInertiaRequest(ServerRequestInterface $request): bool
    {
        return $request->hasHeader('X-Inertia');
    }

    public function createToken(UserInterface $user, string $firewallName): TokenInterface
    {
        return new UsernamePasswordToken($user, $firewallName, $user->getRoles());
    }

    /**
     * @throws AuthenticationException
     */
    private function extractCredentials(ServerRequestInterface $request): array
    {
        $parsedBody = $request->getParsedBody();
        $username = trim($parsedBody[$this->options['username_parameter']] ?? '');
        $password = $parsedBody[$this->options['password_parameter']] ?? '';

        if (empty($username) || empty($password)) {
            throw new AuthenticationException('Username and password cannot be empty.');
        }

        return [$username, $password];
    }

    /**
     * Validate CSRF token from request
     *
     * @throws InvalidCsrfTokenException
     */
    private function validateCsrfToken(ServerRequestInterface $request): void
    {
        $parsedBody = $request->getParsedBody();
        $csrfToken = $parsedBody[$this->options['csrf_parameter']] ?? null;

        if ($csrfToken === null) {
            Log::warning('Login attempt without CSRF token', [
                'ip' => $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown',
                'user_agent' => $request->getHeaderLine('User-Agent'),
                'is_inertia' => $this->isInertiaRequest($request),
            ]);
            throw new InvalidCsrfTokenException('CSRF token is missing');
        }

        if (!$this->csrfTokenManager->validateToken($this->options['csrf_token_id'], $csrfToken)) {
            Log::warning('Login attempt with invalid CSRF token', [
                'ip' => $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown',
                'user_agent' => $request->getHeaderLine('User-Agent'),
                'is_inertia' => $this->isInertiaRequest($request),
            ]);
            throw new InvalidCsrfTokenException('CSRF token is invalid');
        }
    }
}