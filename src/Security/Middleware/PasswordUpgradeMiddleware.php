<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\Middleware;

use App\Entity\User;
use Modufolio\Appkit\Security\Token\TokenStorageInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\HttpFoundation\Session\Session;

/**
 * Middleware to check if authenticated user needs password upgrade
 *
 * After successful authentication, this middleware checks if the user's
 * password version matches the current policy version. If not, it redirects
 * to the password upgrade page.
 */
class PasswordUpgradeMiddleware implements MiddlewareInterface
{
    /**
     * Paths that are exempt from password upgrade check
     */
    private const EXEMPT_PATHS = [
        '/login',
        '/logout',
        '/password/upgrade',
        '/__clockwork',
    ];

    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private Session $session,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        // Skip check for exempt paths
        if ($this->isExemptPath($path)) {
            return $handler->handle($request);
        }

        // Check if user is authenticated
        $token = $this->tokenStorage->getToken();
        if ($token === null || !$token->getUser()) {
            return $handler->handle($request);
        }

        $user = $token->getUser();

        // Only check for User entities (not other user types)
        if (!$user instanceof User) {
            return $handler->handle($request);
        }

        // Load current password policy version
        $passwordPolicy = require BASE_DIR . '/config/password_policy.php';
        $currentVersion = $passwordPolicy['current_version'];

        // Check if password needs upgrade
        if ($user->needsPasswordUpgrade($currentVersion)) {
            // Store intended URL to redirect after upgrade
            $this->session->set('password_upgrade_return_url', (string)$request->getUri());

            // Create redirect response
            $response = new \Nyholm\Psr7\Response(
                302,
                ['Location' => '/password/upgrade']
            );

            return $response;
        }

        return $handler->handle($request);
    }

    /**
     * Check if the given path is exempt from password upgrade check
     */
    private function isExemptPath(string $path): bool
    {
        foreach (self::EXEMPT_PATHS as $exemptPath) {
            if (str_starts_with($path, $exemptPath)) {
                return true;
            }
        }

        return false;
    }
}
