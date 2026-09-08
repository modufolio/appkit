<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Resolver;

use Modufolio\Appkit\Attributes\Template as TemplateAttribute;
use Modufolio\Appkit\Template\Template;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Resolves a controller parameter carrying {@see TemplateAttribute} into a
 * configured {@see Template}: the view and layout paths and the current
 * request are injected here, so controllers never repeat that wiring.
 *
 * Holds the request, so build it per request — alongside the other
 * request-bound resolvers in the pipeline the App rebuilds on reset().
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
final readonly class TemplateResolver implements AttributeResolverInterface
{
    /**
     * @param list<string> $templatePaths
     * @param list<string> $layoutPaths
     */
    public function __construct(
        private array $templatePaths,
        private array $layoutPaths,
        private ServerRequestInterface $request,
    ) {
    }

    public function supports(\ReflectionParameter $parameter): bool
    {
        return [] !== $parameter->getAttributes(TemplateAttribute::class);
    }

    /**
     * @param array<string, mixed> $providedParameters
     */
    public function resolve(\ReflectionParameter $parameter, array $providedParameters): Template
    {
        $attribute = $parameter->getAttributes(TemplateAttribute::class)[0]->newInstance();

        $template = new Template(
            name: $attribute->name,
            templatePaths: $this->templatePaths,
            layoutPaths: $this->layoutPaths,
            request: $this->request,
        );

        if (null !== $attribute->layout) {
            $template->layout($attribute->layout);
        }

        return $template;
    }
}
