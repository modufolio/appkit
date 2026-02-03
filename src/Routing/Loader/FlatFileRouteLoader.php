<?php

namespace Modufolio\Appkit\Routing\Loader;

use App\Controller\FlatFileController;
use Symfony\Component\Config\FileLocatorInterface;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Config\Resource\DirectoryResource;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

class FlatFileRouteLoader extends Loader
{
    private array $ignore = [
        '.',
        '..',
        '.DS_Store',
        '.gitignore',
        '.git',
        '.svn',
        '.htaccess',
        'Thumb.db',
        '@eaDir'
    ];

    public function __construct(
        private FileLocatorInterface $locator,
        private string $fileExtension = 'txt',
        private string $homeFolder = 'home',
    ) {
        parent::__construct();
    }

    public function load(mixed $resource, ?string $type = null): ?RouteCollection
    {
        $dir = $this->locator->locate($resource);
        $collection = new RouteCollection();
        $collection->addResource(new DirectoryResource($dir, '/\.txt$/'));

        // Scan published and draft folders
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
            $root = $dir . '/' . $item;
            if (!is_dir($root)) {
                continue;
            }


            // Strip numeric prefix for slug
            $slug = preg_replace('/^\d+' . preg_quote('_', '/') . '/', '', $item);
            $urlPath = $parentPath ? $parentPath . '/' . $slug : $slug;
            $urlPath = $urlPath === $this->homeFolder ? '' : $urlPath;

            // Find any .txt content file in the folder (Kirby-style)
            $contentFiles = glob($root . '/*.' . $this->fileExtension);
            foreach ($contentFiles as $contentFilePath) {
                $contentFileName = basename($contentFilePath, '.' . $this->fileExtension);

                $routePath = '/' . $urlPath;
                $routeName = str_replace('/', '_', $urlPath) ?: 'home';
                $defaults = [
                    '_controller' => [FlatFileController::class, 'render'],
                    'contentFile' => $contentFilePath,
                    'templateName' => $contentFileName,
                    'parent' => $parentPath !== '' ? $parentPath : null
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
        return $type === 'flat_file';
    }
}
