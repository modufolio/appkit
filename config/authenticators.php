<?php

use Modufolio\Appkit\Security\Authenticator\ApiKeyAuthenticator;
use Modufolio\Appkit\Security\Authenticator\BasicAuthenticator;
use Modufolio\Appkit\Security\Authenticator\FormLoginAuthenticator;
use Modufolio\Appkit\Security\Authenticator\JwtAuthenticator;
use Modufolio\Appkit\Security\Authenticator\OAuthAuthenticator;
use Modufolio\Appkit\Security\Authenticator\RememberMeAuthenticator;
use Modufolio\Appkit\Security\Csrf\CsrfTokenManagerInterface;
use Modufolio\Appkit\Security\OAuth\OAuthServiceInterface;
use Modufolio\Appkit\Tests\App\Repository\UserRepository;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

return [
    'form_login' => function (ContainerInterface $container) {
        return new FormLoginAuthenticator(
            userProvider: $container->get(UserRepository::class),
            csrfTokenManager: $container->get(CsrfTokenManagerInterface::class),
            session: $container->get(SessionInterface::class),
            options: [
                'username_parameter' => 'email',
                'password_parameter' => 'password',
                'csrf_parameter' => '_csrf_token',
                'csrf_token_id' => 'authenticate',
                'totp_parameter' => 'totp_code',
                'backup_code_parameter' => 'backup_code',
            ]
        );
    },
    'basic_auth' => function (ContainerInterface $container) {
        return new BasicAuthenticator(
            userProvider: $container->get(UserRepository::class),
        );
    },
    'jwt' => function (ContainerInterface $container) {
        if (empty($_ENV['JWT_SECRET'])) {
            throw new RuntimeException('JWT_SECRET environment variable is required for JWT authentication. Please set it in your .env file.');
        }

        return new JwtAuthenticator(
            userProvider: $container->get(UserRepository::class),
            options: [
                'secret_key' => $_ENV['JWT_SECRET'],
                'algorithm' => 'HS256',
                'user_identifier_claim' => 'sub',
            ]
        );
    },
    'oauth' => function (ContainerInterface $container) {
        return new OAuthAuthenticator(
            oauthService: $container->get(OAuthServiceInterface::class),
            options: [
                'header_name' => 'Authorization',
                'token_prefix' => 'Bearer',
            ]
        );
    },
    'remember_me' => function (ContainerInterface $container) {
        if (empty($_ENV['REMEMBER_ME_SECRET'])) {
            throw new RuntimeException('REMEMBER_ME_SECRET environment variable is required for remember-me authentication. Please set it in your .env file.');
        }

        return new RememberMeAuthenticator(
            userProvider: $container->get(UserRepository::class),
            options: [
                'secret' => $_ENV['REMEMBER_ME_SECRET'],
                'cookie_name' => 'REMEMBERME',
                'cookie_lifetime' => 2592000, // 30 days
                'cookie_secure' => true,
                'cookie_httponly' => true,
            ]
        );
    },
    'api_key' => function (ContainerInterface $container) {
        return new ApiKeyAuthenticator(
            userProvider: $container->get(UserRepository::class),
            options: [
                'header_name' => 'X-API-KEY',
                'api_keys' => [
                    // Add your API keys here
                    // 'your-api-key-here' => 'user@example.com',
                ],
            ]
        );
    },
];
