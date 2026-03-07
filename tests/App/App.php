<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Tests\App;

use Modufolio\Appkit\Core\Kernel;
use Modufolio\Appkit\Core\NativeApplicationState;
use Modufolio\Appkit\Core\ResetInterface;
use Modufolio\Appkit\Resolver\AssociativeArrayResolver;
use Modufolio\Appkit\Resolver\AttributeParameterResolver;
use Modufolio\Appkit\Resolver\DataGridResolver;
use Modufolio\Appkit\Resolver\FindEntityResolver;
use Modufolio\Appkit\Resolver\MapRequestPayloadResolver;
use Modufolio\Appkit\Resolver\ParameterResolverInterface;
use Modufolio\Appkit\Resolver\ResolverPipeline;
use Modufolio\Appkit\Resolver\TypeHintContainerResolver;
use Modufolio\Appkit\Resolver\TypeHintResolver;
use Modufolio\Appkit\Resolver\UserResolver;
use Modufolio\Appkit\Security\BruteForce\BruteForceProtectionInterface;
use Modufolio\Appkit\Security\Csrf\CsrfTokenManager;
use Modufolio\Appkit\Security\Csrf\CsrfTokenManagerInterface;
use Modufolio\Appkit\Security\TwoFactor\TotpService;
use Modufolio\Appkit\Security\User\UserProviderInterface;
use Modufolio\Appkit\Tests\App\Entity\UserTotpSecret;
use Modufolio\Appkit\Tests\App\Repository\UserTotpSecretRepository;
use Doctrine\DBAL\Exception;
use Modufolio\Psr7\Http\ServerRequest;
use Modufolio\Psr7\Http\Stream;
use Modufolio\Psr7\Http\Uri;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Application container with hardwired application-specific services.
 *
 * This class extends the abstract Kernel and provides concrete service implementations
 * specific to this application, such as user providers, TOTP services, and default props.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
class App extends Kernel
{
    private ?CsrfTokenManagerInterface $csrfTokenManager = null;
    private ?UserProviderInterface $userProvider = null;
    private ?BruteForceProtectionInterface $bruteForceProtection = null;
    private ?TotpService $totpServiceInstance = null;

    public function __construct(
        string $baseDir,
        LoaderInterface $routeLoader,
        LoggerInterface $logger,
        private string $userProviderClass,
        array $authenticators = [],
        array $controllers = [],
        array $factories = [],
        array $fileMap = [],
        array $instances = [],
        array $repositories = []
    ) {
        $this->baseDir = $baseDir;
        $this->routeLoader = $routeLoader;
        $this->logger = $logger;
        $this->authenticators = $authenticators;
        $this->controllers = $controllers;
        $this->factories = $factories;
        $this->fileMap = $fileMap;
        $this->instances = $instances;
        $this->repositories = $repositories;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->state?->reset();

        unset($this->state);

        // Create fresh application state for this request
        $this->state = new NativeApplicationState($request, $this->firewallConfig);

        try {
            $response = $this->handleAuthentication($request);
        } catch (\Throwable $e) {
            $response = $this->exceptionHandler()->handle($e, $request);
        }

        return $this->prepareResponse()->prepare($request, $response);
    }

    // ============================================================================
    // APPLICATION-SPECIFIC SERVICES (Hardwired)
    // ============================================================================

    /**
     * Get user provider (repository-based).
     */
    public function userProvider(): UserProviderInterface
    {
        return $this->userProvider ??= $this->getRepository($this->userProviderClass);
    }

    /**
     * Get CSRF token manager.
     */
    public function csrfTokenManager(): CsrfTokenManagerInterface
    {
        return $this->csrfTokenManager ??= new CsrfTokenManager($this->session());
    }

    /**
     * Get brute force protection service (stub for testing).
     */
    public function bruteForceProtection(): BruteForceProtectionInterface
    {
        return $this->bruteForceProtection ??= new StubBruteForceProtection();
    }

    /**
     *
     * @return self
     * @throws \RuntimeException if called outside test environment
     */
    public function initializeTestState(): self
    {
        if (!$this->environment()->isTest()) {
            throw new \RuntimeException('initializeTestState() can only be called in test environment');
        }

        if ($this->state === null) {
            // Create minimal test request
            $request = new ServerRequest(
                method: 'GET',
                uri: new Uri('http://127.0.0.1'),
                headers: [],
                body: Stream::create(''),
                version: '1.1',
                serverParams: [
                    'HTTP_HOST' => '127.0.0.1',
                    'REQUEST_METHOD' => 'GET',
                    'REQUEST_URI' => '/',
                    'SERVER_PROTOCOL' => 'HTTP/1.1',
                ]
            );


            $this->state = new NativeApplicationState($request, $this->firewallConfig);
        }

        return $this;
    }

    /**
     * Get TOTP service for two-factor authentication.
     */
    public function totpService(): TotpService
    {
        return $this->totpServiceInstance ??= new TotpService(
            $this->entityManager(),
            $this->getRepository(UserTotpSecretRepository::class),
            UserTotpSecret::class,
            'Appkit Test',
        );
    }

    /**
     * Register an additional authenticator at runtime (test helper).
     */
    public function registerAuthenticator(string $name, \Closure $factory): static
    {
        $this->authenticators[$name] = $factory;
        return $this;
    }

    /**
     * Reset application state, including entity-manager-dependent service caches.
     */
    public function reset(): void
    {
        $this->state?->reset();
        $this->state = null;

        $this->debugStack->resetQueries();
        $this->entityManagerFactory?->reset();
        $this->emitter = null;
        $this->environment = null;

        if ($this->router) {
            if ($this->router instanceof ResetInterface) {
                $this->router->reset();
            }
            $this->router = null;
        }

        $this->instances = [];
        $this->userProvider = null;
        $this->csrfTokenManager = null;
        $this->totpServiceInstance = null;
        $this->parameterResolver = null;
    }

    // ============================================================================
    // ABSTRACT KERNEL METHOD IMPLEMENTATIONS
    // ============================================================================

    public function serializer(): SerializerInterface
    {
        return $this->serializer ??= new Serializer(
            [new ObjectNormalizer(), new ArrayDenormalizer()],
            [new JsonEncoder()]
        );
    }

    /**
     * @throws Exception
     */
    public function parameterResolver(): ParameterResolverInterface
    {
        $serializer = $this->serializer();
        assert($serializer instanceof DenormalizerInterface);

        return $this->parameterResolver ??= (new ResolverPipeline())
            ->addResolver(new AssociativeArrayResolver())
            ->addResolver(new TypeHintResolver())
            ->addResolver(new AttributeParameterResolver([
                new UserResolver($this->tokenStorage()),
                new FindEntityResolver($this->entityManager()),
                new DataGridResolver($this->entityManager(), $this->request()),
                new MapRequestPayloadResolver(
                    $serializer,
                    $this->request(),
                    $this->validator()
                )
            ]))
            ->addResolver(new TypeHintContainerResolver($this));
    }

    public function validator(): ValidatorInterface
    {
        return $this->validator ??= Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }
}
