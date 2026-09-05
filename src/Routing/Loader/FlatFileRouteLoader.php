<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Routing\Loader;

use Symfony\Component\Config\FileLocatorInterface;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Config\Resource\DirectoryResource;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
class FlatFileRouteLoader extends Loader
{
    /** @var list<string> */
    private array $ignore = [
        '.',
        '..',
        '.DS_Store',
        '.gitignore',
        '.git',
        '.svn',
        '.htaccess',
        'Thumb.db',
        '@eaDir',
    ];

    /**
     * @param list<string> $viewlessTemplates Template names whose pages are
     *                                        editable content but get no
     *                                        public route — footer blocks,
     *                                        reusable snippets. Their
     *                                        subfolders still route normally.
     */
    public function __construct(
        private FileLocatorInterface $locator,
        private readonly string $controllerClass,
        private string $fileExtension = 'txt',
        private string $homeFolder = 'home',
        private array $viewlessTemplates = [],
    ) {
        parent::__construct();
    }

    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        $dir = $this->locator->locate($resource);
        $collection = new RouteCollection();
        $collection->addResource(new DirectoryResource($dir, '/\.txt$/'));
        $this->addRoutes($collection, $dir, '');

        return $collection;
    }

    private function addRoutes(RouteCollection $collection, string $dir, string $parentPath = ''): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = array_values(array_diff(scandir($dir), $this->ignore));
        natsort($items);

        foreach ($items as $item) {
            $root = $dir.'/'.$item;
            if (!is_dir($root)) {
                continue;
            }

            // Strip numeric prefix for slug
            $slug = preg_replace('/^\d+'.preg_quote('_', '/').'/', '', $item) ?? $item;
            $urlPath = $parentPath ? $parentPath.'/'.$slug : $slug;
            $urlPath = $urlPath === $this->homeFolder ? '' : $urlPath;

            // Find any .txt content file in the folder
            $contentFiles = glob($root.'/*.'.$this->fileExtension) ?: [];
            foreach ($contentFiles as $contentFilePath) {
                $contentFileName = basename($contentFilePath, '.'.$this->fileExtension);

                // Viewless page types are content without an address.
                if (in_array($contentFileName, $this->viewlessTemplates, true)) {
                    break;
                }

                $routePath = '/'.$urlPath;
                $routeName = str_replace('/', '_', $urlPath) ?: 'home';
                $defaults = [
                    '_controller' => [$this->controllerClass, 'handle'],
                    'contentFile' => $contentFilePath,
                    'templateName' => $contentFileName,
                    'parent' => '' !== $parentPath ? $parentPath : null,
                ];
                $collection->add(
                    $routeName,
                    new Route(
                        path: $routePath,
                        defaults: $defaults,
                        methods: ['GET']
                    )
                );
                break;
            }

            // Recursively process subfolders
            $this->addRoutes($collection, $root, $urlPath);
        }
    }

    public function supports($resource, ?string $type = null): bool
    {
        return 'flat_file' === $type;
    }
}
