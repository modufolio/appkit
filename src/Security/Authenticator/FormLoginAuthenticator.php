<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\Authenticator;

use Modufolio\Appkit\Security\BruteForce\BruteForceProtectionInterface;
use Modufolio\Appkit\Security\Csrf\CsrfTokenManagerInterface;
use Modufolio\Appkit\Security\Exception\AuthenticationException;
use Modufolio\Appkit\Security\Exception\BadCredentialsException;
use Modufolio\Appkit\Security\Exception\InvalidCsrfTokenException;
use Modufolio\Appkit\Security\Exception\TooManyLoginAttemptsException;
use Modufolio\Appkit\Security\Exception\TwoFactorRequiredException;
use Modufolio\Appkit\Security\Exception\UserNotFoundException;
use Modufolio\Appkit\Security\Token\TokenInterface;
use Modufolio\Appkit\Security\Token\UsernamePasswordToken;
use Modufolio\Appkit\Security\TwoFactor\TwoFactorServiceInterface;
use Modufolio\Appkit\Security\User\PasswordAuthenticatedUserInterface;
use Modufolio\Appkit\Security\User\PasswordUpgraderInterface;
use Modufolio\Appkit\Security\User\UserInterface;
use Modufolio\Appkit\Security\User\UserPasswordHasherInterface;
use Modufolio\Appkit\Security\User\UserProviderInterface;
use Modufolio\Psr7\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;

class FormLoginAuthenticator extends AbstractAuthenticator
{
    /**
     * Fallback dummy hash used only when no UserPasswordHasher is injected.
     * If your real users are hashed with argon2id, this bcrypt dummy will
     * NOT match real-verify timing — inject a UserPasswordHasher for correct
     * timing-side-channel protection.
     */
    private const DUMMY_HASH = '$2y$12$abcdefghijklmnopqrstuuGfQ7w0rqXjK0LhV0XjY6wWyJ4Z7lYqe';

    /**
     * Cap the submitted username length. Symfony caps at 4096 to keep an
     * attacker from driving bcrypt/argon2 work with an oversized identifier.
     */
    private const MAX_USERNAME_LENGTH = 4096;

    private array $options;

    public function __construct(
        private UserProviderInterface $userProvider,
        private CsrfTokenManagerInterface $csrfTokenManager,
        private FlashBagAwareSessionInterface $session,
        private ?TwoFactorServiceInterface $totpService = null,
        private ?UserPasswordHasherInterface $passwordHasher = null,
        private ?BruteForceProtectionInterface $bruteForce = null,
        array $options = [],
    ) {
        $this->options = array_merge([
            'username_parameter' => 'email',
            'password_parameter' => 'password',
            'check_path' => '/login',
            'login_path' => '/login',
            'two_factor_path' => '/2fa',
            'post_only' => true,
            'csrf_parameter' => '_csrf_token',
            'csrf_token_id' => 'authenticate',
        ], $options);
    }

    public function supports(ServerRequestInterface $request): bool
    {
        if ($request->getUri()->getPath() !== $this->options['check_path']) {
            return false;
        }

        return !$this->options['post_only'] || 'POST' === $request->getMethod();
    }

    /**
     * @throws AuthenticationException
     */
    public function authenticate(ServerRequestInterface $request): UserInterface
    {
        [$identifier, $password] = $this->extractCredentials($request);
        $ipAddress = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        $this->validateCsrfToken($request);

        if ($this->bruteForce?->isLocked($identifier, $ipAddress)) {
            throw new TooManyLoginAttemptsException('Too many failed login attempts. Try again later.');
        }

        // Every credential failure throws BadCredentialsException so consumers
        // of getMessageKey() surface 'Invalid credentials.'. A user that does
        // not exist is deliberately indistinguishable from a wrong password.
        try {
            $user = $this->userProvider->loadUserByIdentifier($identifier);
        } catch (UserNotFoundException) {
            $this->verifyDummyPassword($password);
            $this->bruteForce?->recordFailure($identifier, $ipAddress);
            throw new BadCredentialsException('User not found');
        }

        if (!$user instanceof PasswordAuthenticatedUserInterface) {
            $this->verifyDummyPassword($password);
            $this->bruteForce?->recordFailure($identifier, $ipAddress);
            throw new BadCredentialsException('User is not password-authenticatable');
        }

        $valid = null !== $this->passwordHasher
            ? $this->passwordHasher->isPasswordValid($user, $password)
            : password_verify($password, (string) $user->getPassword());

        if (!$valid) {
            $this->bruteForce?->recordFailure($identifier, $ipAddress);
            throw new BadCredentialsException('Invalid password');
        }

        $this->upgradePasswordIfNeeded($user, $password);

        if (null !== $this->totpService) {
            $totpSecret = $this->totpService->getTwoFactorSecret($user);

            if (null !== $totpSecret && $totpSecret->isEnabled()) {
                // 2FA is required but not yet provided — do not reset the
                // brute-force counter here; only a fully successful login should.
                throw new TwoFactorRequiredException($user);
            }
        }

        $this->bruteForce?->recordSuccess($identifier, $ipAddress);

        return $user;
    }

    private function verifyDummyPassword(#[\SensitiveParameter] string $password): void
    {
        if (null !== $this->passwordHasher) {
            $this->passwordHasher->verifyDummy($password);

            return;
        }
        password_verify($password, self::DUMMY_HASH);
    }

    /**
     * Transparently rehash the stored password after a successful login when
     * the hash is outdated and the provider supports upgrades.
     */
    private function upgradePasswordIfNeeded(PasswordAuthenticatedUserInterface $user, #[\SensitiveParameter] string $password): void
    {
        if (null === $this->passwordHasher
            || !$this->userProvider instanceof PasswordUpgraderInterface
            || !$this->passwordHasher->needsRehash($user)) {
            return;
        }

        $this->userProvider->upgradePassword($user, $this->passwordHasher->hashPassword($user, $password));
    }

    /**
     * Handle unauthorized response for Inertia.js.
     *
     * Inertia expects redirects (303) for navigation and reads errors from
     * session-flashed messages. getMessageKey() is the user-safe message —
     * a user that does not exist reads as 'Invalid credentials.', never as
     * anything that leaks whether the username exists.
     */
    public function unauthorizedResponse(ServerRequestInterface $request, AuthenticationException $exception): ResponseInterface
    {
        if ($exception instanceof TwoFactorRequiredException) {
            return Response::redirect($this->options['two_factor_path'], 303);
        }

        $this->session->getFlashBag()->add('error', $exception->getMessageKey());

        if ($this->isInertiaRequest($request)) {
            return Response::redirect($this->options['login_path'], 303);
        }

        return Response::redirect($this->options['login_path']);
    }

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
        if (!is_array($parsedBody)) {
            throw new BadCredentialsException('Username and password cannot be empty.');
        }

        $username = $parsedBody[$this->options['username_parameter']] ?? '';
        $password = $parsedBody[$this->options['password_parameter']] ?? '';

        $username = is_string($username) ? trim($username) : '';
        $password = is_string($password) ? $password : '';

        if ('' === $username || '' === $password) {
            throw new BadCredentialsException('Username and password cannot be empty.');
        }

        if (strlen($username) > self::MAX_USERNAME_LENGTH) {
            throw new BadCredentialsException('Invalid username.');
        }

        return [$username, $password];
    }

    /**
     * @throws InvalidCsrfTokenException
     */
    private function validateCsrfToken(ServerRequestInterface $request): void
    {
        $parsedBody = $request->getParsedBody();
        $csrfToken = is_array($parsedBody) ? ($parsedBody[$this->options['csrf_parameter']] ?? null) : null;

        if (!is_string($csrfToken) || '' === $csrfToken) {
            throw new InvalidCsrfTokenException('CSRF token is missing');
        }

        if (!$this->csrfTokenManager->validateToken($this->options['csrf_token_id'], $csrfToken)) {
            throw new InvalidCsrfTokenException('CSRF token is invalid');
        }
    }
}
