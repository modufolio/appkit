<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Core;

use Modufolio\Appkit\DependencyInjection\ReflectionControllerArgumentResolver;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

/**
 * Controller resolution: route matching, the controller dependency map,
 * dependency resolution and instantiation.
 *
 * Behavior only: every property this trait touches is declared on {@see Kernel},
 * which composes it. Method names, visibility and signatures are unchanged from
 * their previous home on the kernel.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
trait AppControllers
{
    /**
     * Resolve the controller for the current request and execute it.
     * This handles:
     * 1. Access control enforcement
     * 2. Route matching
     * 3. Controller instantiation
     * 4. Parameter resolution
     * 5. Controller method execution.
     *
     * @throws \ReflectionException
     */
    public function controllerResolver(ServerRequestInterface $request): ResponseInterface
    {
        $this->enforceAccessControl($request);

        $parameters = $this->router()->match($request);

        $controller = $parameters['_controller'] ?? null;

        if (null === $controller) {
            throw new ResourceNotFoundException('No controller found for request');
        }

        $this->enforceAttributeAccessControl($parameters, $request);

        if (!is_array($controller) || 2 !== count($controller)) {
            throw new \InvalidArgumentException('One of the routes does not have a valid controller definition. Expected format: [ClassName, methodName].');
        }

        foreach ($parameters as $key => $value) {
            if ('_' === $key[0]) {
                continue;
            }

            $request = $request->withAttribute($key, $value);
        }

        [$class, $method] = $controller;

        if (!is_string($class) || !is_string($method)) {
            throw new \InvalidArgumentException('One of the routes does not have a valid controller definition. Expected format: [ClassName, methodName].');
        }

        $classObject = $this->getController($class);

        if (!method_exists($classObject, $method)) {
            throw new \InvalidArgumentException("Method {$method} does not exist in {$class}");
        }

        $reflection = new \ReflectionMethod($class, $method);
        $arg = [] === $reflection->getParameters() ? [] : $this->parameterResolver()->getParameters($reflection, [
            ServerRequestInterface::class => $request,
            RequestHandlerInterface::class => $this,
            'firewall' => $this->getFirewallNameForRequest($request),
            ...$parameters,
        ], []);

        // Spread by name, not by position: the resolvers key their results by
        // parameter name and fill them in resolver order, which is not the
        // order of the method signature. array_values() would hand a route
        // parameter to the first argument whenever a resolver later in the
        // pipeline supplied an earlier parameter.
        return $classObject->{$method}(...$arg);
    }

    public function getController(string $id): object
    {
        if (!class_exists($id)) {
            throw new \InvalidArgumentException(sprintf('Controller class "%s" does not exist.', $id));
        }

        // Check request-scoped cache first
        if ($this->state()->hasRequestInstance($id)) {
            return $this->state()->getRequestInstance($id);
        }

        $namedDependencies = $this->getControllerDependencies($id);

        if ([] === $namedDependencies) {
            $controller = $this->instantiateController($id);
            $this->state()->setRequestInstance($id, $controller);

            return $controller;
        }

        $resolved = $this->resolveDependencies($namedDependencies);
        $controller = $this->instantiateController($id, $resolved);
        $this->state()->setRequestInstance($id, $controller);

        return $controller;
    }

    /**
     * @param class-string $id
     *
     * @return array<int|string, mixed>
     */
    protected function getControllerDependencies(string $id): array
    {
        if (!isset($this->controllers[$id])) {
            // Safety net, not a wiring strategy: log so the miss is visible
            // instead of silently resolving by reflection on every request.
            $this->logger()->warning(sprintf(
                'Controller "%s" is not wired in config/controllers.php — resolving its constructor by reflection. Wire it explicitly; a parameter with a default value silently receives that default instead of the wired service.',
                $id,
            ));

            $resolver = new ReflectionControllerArgumentResolver($this);

            return $resolver->resolveArguments($id);
        }

        return $this->controllers[$id];
    }

    /**
     * @param array<int|string, mixed> $namedDependencies
     *
     * @return array<int|string, mixed>
     */
    protected function resolveDependencies(array $namedDependencies): array
    {
        $resolved = [];

        foreach ($namedDependencies as $key => $dep) {
            if (is_string($dep)) {
                $resolved[$key] = $this->resolveDependency($dep);
            } else {
                $resolved[$key] = $dep;
            }
        }

        return $resolved;
    }

    protected function resolveDependency(string $dep): mixed
    {
        if (str_starts_with($dep, '%') && str_ends_with($dep, '%')) {
            return $this->getParameter(trim($dep, '%'));
        }

        if (str_starts_with($dep, '@')) {
            $method = substr($dep, 1);
            if (!method_exists($this, $method)) {
                throw new \InvalidArgumentException("Service method '{$method}' not found.");
            }

            return $this->$method();
        }

        if (str_contains($dep, '\\')) {
            return $this->get($dep);
        }

        return $dep;
    }

    /**
     * @param array<int|string, mixed> $resolved
     */
    protected function instantiateController(string $id, array $resolved = []): object
    {
        $controller = new $id(...$resolved);

        if ($controller instanceof AppAwareInterface) {
            $controller->setSubscribedServices($this);
        }

        return $controller;
    }
}
