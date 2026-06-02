<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\App\FlatFile;

use Modufolio\Appkit\Core\AbstractController;
use Modufolio\Appkit\Data\Txt;
use Modufolio\Appkit\Template\Template;
use Modufolio\Psr7\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class FlatFileController extends AbstractController
{
    public function render(ServerRequestInterface $request, string $contentFile, string $templateName, ?string $parent = null): ResponseInterface
    {
        // Parse the flat file content using Kirby Txt parser
        $data = $this->parseContentFile($contentFile);

        // Get the microcontroller for this page
        $controller = $this->getMicrocontroller($contentFile, $data);

        // Execute the microcontroller closure with parameters
        $context = $controller($request, $data, [
            'template' => $templateName,
            'parent' => $parent,
            'contentFile' => $contentFile,
        ]);

        // Merge returned context with base data
        $viewData = array_merge($data, $context, [
            '_request' => $request,
            '_route' => 'test',
        ]);

        // Create self-contained template instance (RoadRunner-safe)
        $template = new Template(
            name: $templateName,
            templatePaths: [BASE_DIR.'/site/templates'],
            layoutPaths: [BASE_DIR.'/site/layouts'],
            data: $viewData,
            request: $request
        );

        if (!$template->exists()) {
            throw new \RuntimeException(sprintf('Template "%s" not found at %s', $templateName, $template->file()));
        }

        try {
            $html = $template->render();

            return Response::html($html);
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf('Error rendering template "%s": %s', $templateName, $e->getMessage()), 0, $e);
        }
    }

    /**
     * Get the microcontroller closure for this page
     * Looks for a PHP file with controllers alongside the content file.
     */
    private function getMicrocontroller(string $contentFile, array $data): \Closure
    {
        $dir = dirname($contentFile);
        $controllerFile = $dir.'/controller.php';

        // If a controller file exists, load it
        if (file_exists($controllerFile)) {
            $closure = require $controllerFile;

            if ($closure instanceof \Closure) {
                return $closure;
            }
        }

        // Check for template-specific controller
        $template = basename($contentFile, '.txt');
        $templateControllerFile = $dir.'/'.$template.'.controller.php';

        if (file_exists($templateControllerFile)) {
            $closure = require $templateControllerFile;

            if ($closure instanceof \Closure) {
                return $closure;
            }
        }

        // Return default no-op controller
        return $this->getDefaultController();
    }

    /**
     * Default controller that does nothing.
     */
    private function getDefaultController(): \Closure
    {
        return function (ServerRequestInterface $request, array $data, array $meta): array {
            return [];
        };
    }

    /**
     * Parse a Kirby-style flat file using the Txt data handler.
     */
    private function parseContentFile(string $filepath): array
    {
        $content = file_get_contents($filepath);

        return Txt::decode($content);
    }
}
