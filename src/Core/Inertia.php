<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Core;


use Modufolio\Appkit\Template\Template;
use Modufolio\Psr7\Http\Header;
use Modufolio\Psr7\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
/**
 * Inertia implementation.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
class Inertia
{
    public function __construct(
        private string $component,
        private array $props = [],
        private string $version = ''
    ) {
    }

    public function snippet(string $data): string
    {
        return sprintf('<div id="app" data-page="%s"></div>', $data);
    }

    /**
     * @throws \JsonException
     * @throws \Throwable
     */
    public function render(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->hasHeader('X-Inertia')) {
            return Response::json($this->data($request), 200, true);
        }

        $jsonData = htmlspecialchars(json_encode($this->data($request)) ?: '', ENT_QUOTES, 'UTF-8');

        // Create self-contained template instance (RoadRunner-safe)
        $template = new Template(
            name: $this->template(),
            templatePaths: [BASE_DIR . '/site/templates'],
            layoutPaths: [BASE_DIR . '/site/layouts'],
            data: ['inertia' => $this->snippet($jsonData)],
            request: $request
        );

        $html = $template->render();
        return Response::html($html);
    }

    public function template(): string
    {
        return 'inertia';
    }

    public function with(string|array $key, mixed $value = null): self
    {
        if (is_array($key)) {
            $this->props = array_merge($this->props, $key);
        } else {
            $this->props[$key] = $value;
        }

        return $this;
    }
    public function data(ServerRequestInterface $request): array
    {
        return [
            'component' => $this->component,
            'props' => $this->resolveProps($request),
            'url' => (string)$request->getUri(),
            'version' => $this->version,
        ];
    }

    private function resolveProps(ServerRequestInterface $request): array
    {
        $props = $this->props;

        if ($request->hasHeader(Header::PARTIAL_COMPONENT) &&
            $request->getHeaderLine(Header::PARTIAL_COMPONENT) === $this->component) {
            $onlyHeader = $request->getHeaderLine(Header::PARTIAL_ONLY);
            $exceptHeader = $request->getHeaderLine(Header::PARTIAL_EXCEPT);

            $only = $onlyHeader !== '' ? explode(',', $onlyHeader) : [];
            $except = $exceptHeader !== '' ? explode(',', $exceptHeader) : [];

            if (count($only) > 0) {
                $props = array_intersect_key($props, array_flip($only));
            }

            if (count($except) > 0) {
                $props = array_diff_key($props, array_flip($except));
            }
        }

        return $props;
    }
}
