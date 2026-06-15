# Image processing

AppKit includes tools for reading image dimensions, resizing and cropping images, and organising files across named storage disks.

## `Dimensions`

`Modufolio\Appkit\Image\Dimensions` reads and manipulates image dimensions. It does not modify the actual file — it calculates what the new dimensions would be.

```php
use Modufolio\Appkit\Image\Dimensions;

$dims = Dimensions::forImage('/path/to/photo.jpg');

$dims->width();       // int
$dims->height();      // int
$dims->ratio();       // float — width / height
$dims->landscape();   // bool
$dims->portrait();    // bool
$dims->square();      // bool
$dims->orientation(); // 'landscape', 'portrait', or false for square
```

### Resizing and cropping

These methods return a new `Dimensions` instance with the calculated values.

```php
// Resize to fit within 800×600, preserving aspect ratio
$resized = $dims->resize(800, 600);

// Resize to exactly 400 wide, scale height proportionally
$resized = $dims->fitWidth(400);

// Resize to exactly 300 tall
$resized = $dims->fitHeight(300);

// Fit within a square box
$resized = $dims->fit(512);

// Crop to exact dimensions
$cropped = $dims->crop(400, 300);

// Generate thumbnail dimensions from an options array
$thumb = $dims->thumb(['width' => 200, 'height' => 200, 'crop' => true]);
```

Pass `force: true` to allow upscaling beyond the original dimensions:

```php
$resized = $dims->fitWidth(2000, force: true);
```

Convert to array:

```php
$dims->toArray(); // ['width' => 1920, 'height' => 1080]
```

For SVG files:

```php
$dims = Dimensions::forSvg('/path/to/icon.svg');
```

## `Darkroom`

`Modufolio\Appkit\Image\Darkroom` processes image files. It is abstract — choose a driver based on what is available on your server.

| Driver | Class | Requires |
|--------|-------|---------|
| GD | `Modufolio\Appkit\Image\Darkroom\GdLib` | `ext-gd` (bundled with PHP) |
| ImageMagick | `Modufolio\Appkit\Image\Darkroom\ImageMagick` | ImageMagick CLI installed |

```php
use Modufolio\Appkit\Image\Darkroom\GdLib;

$darkroom = new GdLib();

$result = $darkroom->process('/path/to/original.jpg', [
    'width'   => 800,
    'height'  => 600,
    'crop'    => true,
    'quality' => 85,
]);
```

`process()` returns an array with the processed file's dimensions and path. The original file is not modified — the output is written to a new location based on the options you pass.

Common options:

| Option | Type | Description |
|--------|------|-------------|
| `width` | `int` | Target width in pixels |
| `height` | `int` | Target height in pixels |
| `crop` | `bool` | Crop to exact dimensions rather than fitting |
| `quality` | `int` | JPEG quality (1–100), default 90 |
| `format` | `string` | Output format: `jpg`, `png`, `webp` |
| `grayscale` | `bool` | Convert to greyscale |
| `blur` | `int` | Apply Gaussian blur |

`preprocess()` runs before the actual processing to normalise and merge options with defaults:

```php
$options = $darkroom->preprocess('/path/to/image.jpg', ['width' => 400]);
```

## `DiskManager`

`Modufolio\Appkit\Image\DiskManager` organises file storage into named disks. Each disk has a root directory and an optional public URL. The concept is inspired by [Laravel Filesystem](https://laravel.com/docs/filesystem) and [Flysystem](https://flysystem.thephpleague.com/).

```php
use Modufolio\Appkit\Image\Disk;
use Modufolio\Appkit\Image\DiskManager;

$manager = new DiskManager();

$manager->registerMultiple([
    'avatars'   => ['root' => '/var/www/app/storage/avatars',   'url' => 'https://example.com/storage/avatars'],
    'documents' => ['root' => '/var/www/app/storage/documents'],
]);

$manager->setDefault('avatars');
```

Access a disk by name:

```php
$disk = $manager->disk('avatars');

$disk->root();   // '/var/www/app/storage/avatars'
$disk->url();    // 'https://example.com/storage/avatars'
$disk->name();   // 'avatars'
$disk->config(); // full config array
```

Register a single custom `Disk`:

```php
$manager->register(new Disk(
    name: 'thumbnails',
    root: '/var/www/app/storage/thumbnails',
    url:  'https://example.com/storage/thumbnails',
));
```

Check and list disks:

```php
$manager->has('avatars');    // bool
$manager->all();             // DiskInterface[]
$manager->getDefault();      // DiskInterface
```

## Full upload and resize workflow

```php
use Modufolio\Appkit\Http\UploadedFileErrorHandler;
use Modufolio\Appkit\Image\Darkroom\GdLib;
use Modufolio\Appkit\Image\DiskManager;

#[Route(path: '/profile/avatar', name: 'profile.avatar.update', methods: ['POST'])]
public function updateAvatar(ServerRequestInterface $request, #[CurrentUser] User $user): ResponseInterface
{
    $upload = UploadedFileErrorHandler::from($request->getUploadedFiles()['avatar'])
        ->isImage()
        ->maxSize(5 * 1024 * 1024);

    if ($upload->hasErrors()) {
        return Response::json(['errors' => $upload->getErrors()], 422);
    }

    $upload->saveTo($this->storageDir . '/tmp', 'original-' . $user->getId());

    // Resize to a 256×256 thumbnail
    $darkroom = new GdLib();
    $darkroom->process($upload->getStoredFilePath(), [
        'width'  => 256,
        'height' => 256,
        'crop'   => true,
    ]);

    $user->setAvatarPath($upload->getStoredFilePath());
    $this->entityManager->flush();

    return Response::redirect($this->urlGenerator->generate('profile'));
}
```

## Reading dimensions before processing

Check dimensions before committing to a full resize:

```php
$dims = Dimensions::forImage($upload->getStoredFilePath());

if ($dims->width() < 100 || $dims->height() < 100) {
    return Response::json(['error' => 'Image must be at least 100×100 pixels.'], 422);
}
```
