# Image processing

AppKit includes tools for reading image dimensions and metadata, resizing and cropping images, organising files across named storage disks, and describing image variants that are generated on demand. Everything lives under `Modufolio\Appkit\Image`.

## `Dimensions`

`Modufolio\Appkit\Image\Dimensions` reads and manipulates image dimensions. It does not modify the actual file — it calculates what the new dimensions would be.

```php
use Modufolio\Appkit\Image\Dimensions;

$dims = Dimensions::forImage('/path/to/photo.jpg');

$dims->width();       // int
$dims->height();      // int
$dims->ratio();       // float — width / height, 0.0 when either side is 0
$dims->landscape();   // bool
$dims->portrait();    // bool
$dims->square();      // bool
$dims->orientation(); // 'landscape', 'portrait' or 'square'; false when ratio() is 0
```

`forImage()` returns a `0 × 0` instance for a path that does not exist. You can also construct one directly: `new Dimensions(1200, 768)`.

### Resizing and cropping

These methods **mutate the instance** and return `$this`. Calling several in a row compounds — each call starts from the result of the previous one. Clone first, or construct a fresh instance per calculation, when you need more than one result from the same source.

```php
$source = Dimensions::forImage('/path/to/photo.jpg'); // e.g. 1920 × 1080

// Fit within 800×600, preserving aspect ratio
$resized = (clone $source)->resize(800, 600);        // 800 × 450

// Scale to exactly 400 wide, height follows
$resized = (clone $source)->fitWidth(400);           // 400 × 225

// Scale to exactly 300 tall, width follows
$resized = (clone $source)->fitHeight(300);          // 533 × 300

// Fit within a square box
$resized = (clone $source)->fit(512);                // 512 × 288

// Crop to exact dimensions (no aspect calculation — sets both sides)
$cropped = (clone $source)->crop(400, 300);          // 400 × 300

// Same as resize()/crop(), driven by an options array
$thumb = (clone $source)->thumb(['width' => 200, 'height' => 200, 'crop' => true]);
```

Without the clone, `$source` itself would be 400 × 300 after the last call.

`fitWidth()`, `fitHeight()`, `fit()` and `resize()` never upscale: if the source already fits, the instance is left as it is. Pass `force: true` to scale up:

```php
$resized = (clone $source)->fitWidth(2000, force: true);
```

Convert to array:

```php
$dims->toArray();
// ['width' => 1920, 'height' => 1080, 'ratio' => 1.7777777777777777, 'orientation' => 'landscape']
```

For SVG files:

```php
$dims = Dimensions::forSvg('/path/to/icon.svg');
```

`forSvg()` reads the root element's `width`/`height` attributes and falls back to the `viewBox` when they are missing or percentages. It parses with `LIBXML_NONET` and reads at most 1 MB, so it is safe on uploads.

## `File` and `Image`

`Modufolio\Appkit\Image\File` wraps a file on disk together with the disk it belongs to and the [`Storage`](#storage-and-storageinterface) that decides where its generated variants live. `Modufolio\Appkit\Image\Image` extends it with everything that only makes sense for images. Both implement `FileInterface`.

```php
use Modufolio\Appkit\Image\Image;

$image = new Image('/var/www/app/uploads/photo.jpg', 'default', $storage, $diskManager);

$image->root();          // '/var/www/app/uploads/photo.jpg'
$image->filename();      // 'photo.jpg'
$image->name();          // 'photo'
$image->extension();     // 'jpg'
$image->mime();          // 'image/jpeg' or null
$image->disk();          // DiskInterface
$image->width();         // int
$image->height();        // int
$image->dimensions();    // Dimensions (cached)
$image->orientation();   // as Dimensions::orientation()
$image->isResizable();   // jpg, jpeg, gif, png, webp — and the bytes must match the extension
$image->isViewable();    // the resizable types plus avif and svg
$image->exif();          // Exif (see below)
$image->toArray();       // ['dimensions' => [...], 'exif' => [...]]
```

The constructor throws `ImageException` when the path does not exist, is not a regular file, or is not readable. The disk argument is either a `DiskInterface` or a disk name; a name is resolved through the `DiskManager` you pass (or a fresh one, which only knows `default`). Without a `Storage` the file gets `new Storage()` with its `/media` and `/uploads` defaults.

`Image::isResizable()` checks the extension and then verifies the real MIME type against it. A `.jpg` whose bytes are not a JPEG throws `ImageException::mimeTypeMismatch()` rather than returning `false`, so a renamed upload cannot slip through. The plain `File::isResizable()` only looks at the extension.

## `Exif`, `Camera` and `Location`

`Image::exif()` returns a `Modufolio\Appkit\Image\Exif` built from `exif_read_data()`. It needs `ext-exif`, which is in `composer.json` `suggest`. Without it `exif_read_data()` is skipped: `exposure()`, `aperture()`, `iso()`, `focalLength()`, `isColor()` and the GPS values are `null`, `camera()` and `location()` return empty objects, and `timestamp()` falls back to the file's modification time.

```php
$exif = $image->exif();

$exif->camera();        // Camera — make(), model(), (string) $camera → 'Make Model'
$exif->location();      // Location — lat(), lng() as floats or null; (string) → 'lat, lng'
$exif->timestamp();     // string — DateTimeOriginal, else FileDateTime, else mtime
$exif->exposure();      // ?string — the raw ExposureTime tag
$exif->aperture();      // ?string — COMPUTED ApertureFNumber
$exif->iso();           // ?int
$exif->focalLength();   // ?string
$exif->isColor();       // ?bool
$exif->data();          // the raw array
```

`toArray()` leaves GPS out unless you ask for it:

```php
$exif->toArray();                        // camera, timestamp, exposure, aperture, iso, focalLength, isColor
$exif->toArray(includeLocation: true);   // adds 'location' => ['lat' => …, 'lng' => …]
```

`Image::toArray()` takes the same `$includeLocation` flag and passes it through. Treat location as personal data: do not surface it by default.

`Camera` and `Location` can also be constructed from any raw EXIF array — `new Camera($data)`, `new Location($data)` — which is how their tests exercise them. `Location` reads `GPSLatitude`/`GPSLatitudeRef`/`GPSLongitude`/`GPSLongitudeRef` and converts the degree/minute/second rationals to signed decimals.

## `Darkroom`

`Modufolio\Appkit\Image\Darkroom` processes image files. It is abstract — choose a driver based on what is available on your server. Both drivers implement `DarkroomInterface`, which is what you should type-hint against so the driver can be swapped in configuration.

| Driver | Class | Requires |
|--------|-------|---------|
| GD | `Modufolio\Appkit\Image\Darkroom\GdLib` | `ext-gd` and the `claviska/simpleimage` package. Both are `suggest` entries in `composer.json`, not hard dependencies — run `composer require claviska/simpleimage`. `process()` throws a `\RuntimeException` naming the missing piece. |
| ImageMagick | `Modufolio\Appkit\Image\Darkroom\ImageMagick` | The `magick` CLI on the `PATH` (override with the `bin` option). |

**`process()` writes the result over the file you pass in** and returns the normalised option array it acted on — not a path. Copy the original somewhere first when you need to keep it:

```php
use Modufolio\Appkit\Image\Darkroom\GdLib;
use Modufolio\Appkit\Toolkit\F;

$darkroom = new GdLib();

F::copy('/path/to/original.jpg', '/path/to/thumb.jpg');

$options = $darkroom->process('/path/to/thumb.jpg', [
    'width'   => 800,
    'height'  => 600,
    'crop'    => true,
    'quality' => 85,
]);

$options['width'];   // 800 — the dimensions actually produced
$options['crop'];    // 'center' — `true` normalised to the anchor
```

The one exception to "same path": `ImageMagick` with a `format` option writes to a sibling file with the new extension (`thumb.jpg` → `thumb.webp`). `GdLib` re-encodes in the new format but keeps the path you gave it.

Options you pass override the driver's defaults. You can also set defaults once in the constructor — `new GdLib(['quality' => 80, 'autoOrient' => false])` — and every `process()` call starts from them.

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `width` | `?int` | `null` | Target width in pixels |
| `height` | `?int` | `null` | Target height in pixels |
| `crop` | `bool\|string` | `false` | `false` fits inside the box; `true` is `'center'`; an anchor — `'top left'`, `'top'`, `'top right'`, `'left'`, `'center'`, `'right'`, `'bottom left'`, `'bottom'`, `'bottom right'`; or a focal point such as `'30%,60%'` (see [`Focus`](#focus)) |
| `quality` | `int` | `90` | JPEG/WebP quality (1–100) |
| `format` | `?string` | `null` | Re-encode as `jpg`, `png`, `webp`, … |
| `grayscale` | `bool` | `false` | Convert to greyscale. `greyscale` and `bw` are accepted as aliases |
| `blur` | `bool\|int` | `false` | Gaussian blur radius; `true` means `10` |
| `sharpen` | `bool\|int` | `null` | Sharpen amount; `true` means `50` |
| `autoOrient` | `bool` | `true` | Rotate according to the EXIF orientation tag |

`preprocess()` is the first half of `process()` on its own: it merges the options with the defaults, normalises the shorthands above, reads the source dimensions and works out the final `width`/`height` (plus `sourceWidth`, `sourceHeight`, `scaleWidth`, `scaleHeight`). It does not touch the file. It is on `DarkroomInterface` because anything that replays a stored [job](#job-storage) needs it before deciding on an output filename:

```php
$options = $darkroom->preprocess('/path/to/image.jpg', ['width' => 400]);
// ['width' => 400, 'height' => 267, 'sourceWidth' => 1200, 'crop' => false, …]
```

## `Focus`

`Modufolio\Appkit\Image\Focus` is the static helper behind focal-point crops. A focal point is a string with two percentages — `'30%,60%'`, `'30%, 60%'` or the legacy JSON `'{"x":0.3,"y":0.6}'` — marking the spot that must stay in frame.

```php
use Modufolio\Appkit\Image\Focus;

Focus::isFocalPoint('30%,60%');   // true — anything containing '%'
Focus::parse('30%,60%');          // [0.3, 0.6]
Focus::ratio(1200, 700);          // 1.7142857142857142; 0.0 when height is 0

// Crop box in source pixels for a 400×350 thumb of a 1200×700 source,
// keeping the point at (0%, 0%) — the top-left — in frame:
Focus::coords('0%, 0%', 1200, 700, 400, 350);
// ['x1' => 0, 'y1' => 0, 'x2' => 800, 'y2' => 700, 'width' => 800, 'height' => 700]
```

`coords()` returns `null` when the source and target already have the same ratio, so no crop is needed. The `Darkroom` drivers call it whenever the `crop` option looks like a focal point.

## `DiskManager`

`Modufolio\Appkit\Image\DiskManager` organises file storage into named disks. Each disk has a root directory and an optional public URL. The concept is inspired by [Laravel Filesystem](https://laravel.com/docs/filesystem) and [Flysystem](https://flysystem.thephpleague.com/).

A new manager already contains one disk, `default`, rooted at `/uploads` with no URL, and it is the default disk until you say otherwise. Register yours on top of it:

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

Registering a disk named `default` replaces the built-in one. The playground application does exactly this from a config file:

```php
// config/services.php
->set(DiskManager::class, function (App $app) {
    $manager = new DiskManager();
    $disksConfig = require $app->baseDir . '/config/disks.php';

    if (is_array($disksConfig) && !empty($disksConfig)) {
        $manager->registerMultiple($disksConfig);
    }

    return $manager;
})
```

Access a disk by name — an unknown name throws `\InvalidArgumentException`:

```php
$disk = $manager->disk('avatars');

$disk->root();   // '/var/www/app/storage/avatars' — trailing slash trimmed
$disk->url();    // 'https://example.com/storage/avatars'
$disk->name();   // 'avatars'
$disk->config(); // ['name' => …, 'root' => …, 'url' => …] plus any extra keys you registered
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
$manager->all();             // DiskInterface[] keyed by name, 'default' included
$manager->getDefault();      // DiskInterface
```

`DiskManager::createDisk($name, $config)` builds a `Disk` from the same array shape without registering it.

## `Storage` and `StorageInterface`

Disks say where the originals are. `Modufolio\Appkit\Image\StorageInterface` says where the **generated variants** go and what URL they are served from. `Storage` is the default implementation:

```php
use Modufolio\Appkit\Image\Storage;

$storage = new Storage(
    baseMediaRoot: '/var/www/app/public/media',   // default '/media'
    baseMediaUrl:  '/media',                       // default '/media'
    uploadsDir:    '/var/www/app/uploads',         // default '/uploads'
);
```

Trailing slashes are trimmed. For a file it resolves:

```php
$storage->mediaRoot($file);
// '/var/www/app/public/media/images/{disk}/{hash}/photo.jpg'
$storage->mediaUrl($file);
// '/media/images/{disk}/{hash}/photo.jpg'
```

`{hash}` is the first ten characters of `md5()` of the path **relative to `uploadsDir`**, followed by `-` and the original's modification time. Relative, so a deploy to a different directory does not change every URL; with the mtime, so rewriting an original in place changes its URL and a CDN cannot keep serving the old bytes. Variants can therefore be cached for a year. `uploadsDir()` is what `File::relativePathFromUploads()` strips off; a file outside it falls back to its bare filename.

The playground wires it from a config file and injects the interface, never the class:

```php
// config/services.php
->set(StorageInterface::class, function (App $app) {
    $config = require $app->baseDir . '/config/storage.php';

    return new Storage(
        baseMediaRoot: $config['baseMediaRoot'] ?? '/media',
        baseMediaUrl:  $config['baseMediaUrl'] ?? '/media',
        uploadsDir:    $config['uploadsDir'] ?? '/uploads',
    );
})
```

Implement `StorageInterface` yourself when variants live on another host or under a different layout; `PhotoLab`, `File` and the transformations only ever talk to the interface.

## Variants: `PhotoLab`, `ImageProcessor` and transformations

Resizing on upload is fine for one avatar. For a gallery that needs six sizes per image it is not, so AppKit separates *describing* a variant from *generating* it:

1. `PhotoLab` / `ImageProcessor` work out the variant's filename and URL from the requested transformations. They never touch pixels.
2. If that file does not exist yet, they record a **job** — the original's path and the options — through a [`JobStorageInterface`](#job-storage).
3. A route serving the media URL finds no file, loads the job, runs a `Darkroom` and saves the result. Every later request is a static file.

`Modufolio\Appkit\Image\PhotoLab` is the entry point. It needs the absolute path of an original, its disk name, a `StorageInterface`, a `JobStorageInterface` and optionally the `DiskManager` that resolves the name:

```php
use Modufolio\Appkit\Image\JsonJobStorage;
use Modufolio\Appkit\Image\PhotoLab;

$lab = new PhotoLab(
    absolutePath: '/var/www/app/uploads/photo.png',
    disk:         'default',
    storage:      $storage,
    jobStorage:   new JsonJobStorage(),
    diskManager:  $diskManager,
);

$variant = $lab->resize(300, 200, 80);   // ImageVariant
$variant->filename();                    // 'photo-300x200-q80.png'
$variant->url();                         // '/media/images/default/{hash}/photo-300x200-q80.png'
$variant->root();                        // where the generated file will be
$variant->exists();                      // false until something generates it
$variant->modifications();               // ['width' => 300, 'height' => 200, 'quality' => 80]
$variant->original();                    // the FileInterface it was made from
```

A missing path throws `\InvalidArgumentException` from the constructor. The playground hides the wiring behind a small factory service so controllers only pass the path:

```php
// src/Service/ImageService.php (playground)
public function make(string $absolutePath): PhotoLab
{
    return new PhotoLab($absolutePath, $this->disk, $this->storage, $this->jobStorage, $this->diskManager);
}
```

Shorthands, each returning `ImageVariant` for a resizable image:

```php
$lab->resize(?int $width, ?int $height, ?int $quality);
$lab->crop(int $width, ?int $height, string $mode = 'center');   // 'photo-100x100-crop.png'
$lab->quality(int $level);                                       // 'photo-q70.png'
$lab->blur(int|bool $intensity = true);                          // true → 10
$lab->sharpen(int $amount = 50);
$lab->grayscale();   // also bw() and greyscale()
```

For a file that is not a resizable image — a PDF, a text file — every shorthand returns the `FileInterface` itself instead of a variant, so callers can always ask for `->url()`.

`srcset()` builds a responsive `srcset` string from widths:

```php
$lab->srcset([300, 600]);
// 'photo-300x.png 300w, photo-600x.png 600w' (with the full media URLs)

$lab->srcset([320 => '320w', 640 => '2x']);                 // width => descriptor
$lab->srcset([['width' => 480, 'condition' => '480w']]);   // explicit
$lab->srcset();                                             // null — nothing to build
```

### Building a pipeline

`build()` returns a `Modufolio\Appkit\Image\ImageProcessor` you can stack transformations on. Each transformation implements `Transformation` — `name()`, `config()` and `apply()` — and lives under `Modufolio\Appkit\Image\Transformations`:

```php
use Modufolio\Appkit\Image\Transformations\CropTransformation;
use Modufolio\Appkit\Image\Transformations\QualityTransformation;

$variant = $lab->build()
    ->add(new CropTransformation(300, 200, 'top left'))
    ->add(new QualityTransformation(80))
    ->process();
```

| Class | Constructor | `config()` keys |
|-------|-------------|-----------------|
| `ResizeTransformation` | `(?int $width, ?int $height, ?int $quality)` | `width`, `height`, `quality` — only those set |
| `CropTransformation` | `(int $width, ?int $height = null, string $mode = 'center')` — a null height becomes `$width` | `width`, `height`, `crop` |
| `QualityTransformation` | `(int $quality = 90)` | `quality` |
| `BlurTransformation` | `(int $intensity = 10)` | `intensity` |
| `SharpenTransformation` | `(int $amount = 50)` | `amount` |
| `GrayscaleTransformation` | `()` | none |

`process()` merges every `config()` into one options array, derives the filename from it, saves a job if the file is missing and returns the `ImageVariant`. It throws `ImageException` if the original has vanished or is unreadable since the `PhotoLab` was built. With no transformations added it describes the original served from the media path. `getTransformationNames()`, `getConfigurations()` and `clear()` let you inspect or reset the stack; the tests cover all three.

### Variant filenames — `CustomFilename`

`Modufolio\Appkit\Image\CustomFilename` turns an original name, a template and an options array into the variant name. The tokens, in order, each omitted when unset:

| Option | Token | Example |
|--------|-------|---------|
| `width` / `height` | `{w}x{h}` — either side may be empty | `300x200`, `100x` |
| `crop` | `crop`, or `crop-{position}` when not centred | `crop`, `crop-top-left` |
| `blur` | `blur{n}` | `blur10` |
| `grayscale` / `greyscale` / `bw` | `bw` | `bw` |
| `quality` | `q{n}` | `q80` |

```php
use Modufolio\Appkit\Image\CustomFilename;

(string) new CustomFilename('Some File.jpg', '{{ name }}{{ attributes }}.{{ extension }}', [
    'width' => 300, 'height' => 200, 'crop' => 'center', 'blur' => 10, 'grayscale' => true, 'quality' => 80,
]);
// 'some-file-300x200-crop-blur10-bw-q80.jpg'
```

The name is slugged, the extension lowercased with `jpeg` folded to `jpg`, and a `format` option replaces the extension. Any `..` (plain or URL-encoded) in the filename or template throws `ImageException::pathTraversalAttempt()`.

## Job storage

A job is the recipe for one variant: which original, which options. `Modufolio\Appkit\Image\JobStorageInterface` has four methods, all keyed by the variant's media directory and filename:

```php
saveJob(string $mediaRoot, string $thumbName, array $options): void
loadJob(string $mediaRoot, string $thumbName): ?array
deleteJob(string $mediaRoot, string $thumbName): bool
jobExists(string $mediaRoot, string $thumbName): bool
```

`ImageProcessor` stores the options plus `filename` (the original, relative to `uploadsDir`) and `transformations` (the names in order).

**`JsonJobStorage`** writes one JSON file per job under a subdirectory of the media root — `.jobs` by default, `<thumbName>.json` inside it. Path components in the thumb name are stripped. Write failures are swallowed on purpose: a job that cannot be recorded just means the variant is generated on the next request instead.

```php
use Modufolio\Appkit\Image\JsonJobStorage;

$jobs = new JsonJobStorage();            // or new JsonJobStorage('queue')

$jobs->saveJob('/var/www/app/public/media/images/default/abc123-1700000000', 'photo-300x200.png', [
    'width' => 300, 'height' => 200,
]);
// writes …/abc123-1700000000/.jobs/photo-300x200.png.json

$jobs->jobExists($mediaRoot, 'photo-300x200.png');   // true
$jobs->loadJob($mediaRoot, 'photo-300x200.png');     // ['width' => 300, 'height' => 200]; null when missing or not JSON
$jobs->deleteJob($mediaRoot, 'photo-300x200.png');   // true; false when there was nothing to delete
```

**`ImageJobService`** is the database-backed alternative. It adapts an `ImageJobRepositoryInterface` — the same four methods, implemented by your application's ORM repository — to `JobStorageInterface`, and swallows every repository exception the same way (`saveJob()` returns silently, the others return `null`/`false`):

```php
use Modufolio\Appkit\Image\ImageJobService;

$jobs = new ImageJobService($imageJobRepository);   // your ImageJobRepositoryInterface
```

Which one to use is a service definition. The playground binds a Doctrine implementation directly:

```php
// config/services.php
->set(JobStorageInterface::class, fn (App $app) => new DoctrineJobStorage(
    $app->get(ImageJobRepository::class),
))
```

### Serving and generating variants

The route behind `baseMediaUrl()` is application code; the framework only provides the pieces. The playground's `MediaController` is the reference shape: serve the file if it exists, otherwise load the job, `preprocess()` the original to get the final options, generate under a temporary name and `rename()` it into place so two concurrent requests cannot expose a half-written file, then answer with a year-long `Cache-Control`. The job is kept so a purged variant can be rebuilt from the same URL.

```php
$options = $this->jobStorage->loadJob($mediaRoot, $filename);
$original = $this->storage->uploadsDir() . '/' . $options['filename'];

$options = $this->darkroom->preprocess($original, $options);
$root = (new CustomFilename($original, $mediaRoot . '/' . $filename, $options))->toString();

if (!file_exists($root)) {
    $tmp = $root . '.' . bin2hex(random_bytes(8)) . '.tmp';
    F::copy($original, $tmp, true);
    $this->darkroom->process($tmp, $options);
    rename($tmp, $root);
}
```

## `ImageException`

`Modufolio\Appkit\Image\ImageException` extends `\RuntimeException` and is what the image classes throw. Named constructors give each failure a stable message:

```php
ImageException::fileNotFound($path);
ImageException::fileNotReadable($path);
ImageException::invalidImageType($path, $type);
ImageException::mimeTypeMismatch($path, $extension, $mime);
ImageException::transformationFailed($message, $previous);
ImageException::pathTraversalAttempt($value);
```

`PhotoLab`'s constructor and `DiskManager::disk()` throw plain `\InvalidArgumentException` instead; the `Darkroom` drivers throw `\RuntimeException` for a missing extension, package or binary.

## Full upload and resize workflow

Store the original under a name that cannot collide — `saveTo()` refuses to overwrite — then make the thumbnail from a copy, because `process()` writes in place.

```php
use Modufolio\Appkit\Http\UploadedFileErrorHandler;
use Modufolio\Appkit\Image\Darkroom\GdLib;
use Modufolio\Appkit\Toolkit\F;
use Modufolio\Appkit\Toolkit\Str;

#[Route(path: '/profile/avatar', name: 'profile.avatar.update', methods: ['POST'])]
public function updateAvatar(ServerRequestInterface $request, #[CurrentUser] User $user): ResponseInterface
{
    $upload = UploadedFileErrorHandler::from($request->getUploadedFiles()['avatar'])
        ->isImage()
        ->maxSize(5 * 1024 * 1024);

    if ($upload->hasErrors()) {
        return Response::json(['errors' => $upload->getErrors()], 422);
    }

    // Keep the extension — saveTo() uses the filename verbatim, and GdLib
    // needs it to detect the image type. A UUID means no two uploads collide.
    $ext = strtolower(pathinfo($upload->getFile()->getClientFilename(), PATHINFO_EXTENSION));
    $upload->saveTo($this->storageDir . '/avatars', Str::uuid() . '.' . $ext);

    $original = $upload->getStoredFilePath();
    $thumb = dirname($original) . '/' . pathinfo($original, PATHINFO_FILENAME) . '-256.' . $ext;

    // process() overwrites its input, so work on a copy
    F::copy($original, $thumb);
    (new GdLib())->process($thumb, [
        'width'  => 256,
        'height' => 256,
        'crop'   => true,
    ]);

    $user->setAvatarPath($thumb);
    $this->entityManager->flush();

    return Response::redirect($this->urlGenerator->generate('profile'));
}
```

For anything beyond a single fixed size, hand the stored original to `PhotoLab` instead and let the media route generate variants on demand.

## Reading dimensions before processing

Check dimensions before committing to a full resize:

```php
$dims = Dimensions::forImage($upload->getStoredFilePath());

if ($dims->width() < 100 || $dims->height() < 100) {
    return Response::json(['error' => 'Image must be at least 100×100 pixels.'], 422);
}
```
