# File uploads

`UploadedFileErrorHandler` gives you a fluent chain for validating and storing uploaded files.

## Basic usage

Get the uploaded file from the request, pass it to `UploadedFileErrorHandler::from()`, add your validation rules, then save it.

```php
use Modufolio\Appkit\Http\UploadedFileErrorHandler;
use Psr\Http\Message\ServerRequestInterface;

#[Route(path: '/upload', name: 'upload', methods: ['POST'])]
public function upload(ServerRequestInterface $request): ResponseInterface
{
    $upload = UploadedFileErrorHandler::from(
        $request->getUploadedFiles()['avatar']
    );

    $upload
        ->isImage()
        ->maxSize(2 * 1024 * 1024);  // 2 MB

    // Check validation before saving — saveTo() throws if there are errors.
    if ($upload->hasErrors()) {
        return Response::json(['errors' => $upload->getErrors()], 422);
    }

    $upload->saveTo(__DIR__ . '/../../storage/avatars');

    $path = $upload->getStoredFilePath();
    // store $path in your entity, return a response, etc.
}
```

## Validation methods

All validation methods return `$this`, so they chain.

| Method | Description |
|--------|-------------|
| `hasExtension(string\|array $ext, ?string $message)` | Require a specific file extension or list of extensions. |
| `hasMimeType(string\|array $mime, ?string $message)` | Require a specific MIME type or list. |
| `isImage(?string $message)` | Shorthand for common raster image MIME types (jpeg, png, gif, webp). SVG is excluded — it can carry scripts; allow it with `hasMimeType('image/svg+xml')` only after sanitising. |
| `maxSize(int $bytes, ?string $message)` | Reject files larger than `$bytes`. |
| `minSize(int $bytes, ?string $message)` | Reject files smaller than `$bytes`. |
| `matchesFilenamePattern(string $pattern, ?string $message)` | Validate the original filename against a regex. |
| `assert(callable $validator, string $message)` | Add a custom validation callback. |

Provide a custom message to any method to override the default error text:

```php
$upload->maxSize(5 * 1024 * 1024, 'The file must be smaller than 5 MB.');
```

## Custom validation

Use `assert()` for any check that the built-in methods do not cover.

```php
$upload->assert(function (\Psr\Http\Message\UploadedFileInterface $file): bool {
    return $file->getSize() % 2 === 0; // contrived example
}, 'File size must be even.');
```

## Saving files

```php
$upload->saveTo('/absolute/path/to/directory');

// With a custom filename — used verbatim, so INCLUDE the extension yourself
$upload->saveTo('/absolute/path/to/directory', 'profile-123.jpg');
```

The filename is passed through `F::safeName()` and used as given; no extension is
appended. Omitting it writes an extensionless file, which then breaks anything
downstream that infers type from the path — image processing in particular.

To keep the original extension:

```php
$ext = pathinfo($upload->getFile()->getClientFilename(), PATHINFO_EXTENSION);
$upload->saveTo($dir, 'profile-123.' . $ext);
```

If validation fails, `saveTo()` throws `\InvalidArgumentException`. Always check `hasErrors()` *before* calling `saveTo()` (or wrap it in a try/catch).

## Reading errors and the stored path

```php
$upload->hasErrors();         // bool
$upload->getErrors();         // string[] — list of error messages
$upload->getStoredFilePath(); // ?string — full path once saved, null before saveTo()
$upload->getFile();           // UploadedFileInterface — the original PSR-7 file
```

## Handling multiple files

Process each file individually:

```php
$files = $request->getUploadedFiles();

foreach ($files['gallery'] as $uploaded) {
    $handler = UploadedFileErrorHandler::from($uploaded)
        ->isImage()
        ->maxSize(10 * 1024 * 1024);

    if ($handler->hasErrors()) {
        // collect errors per file
        continue;
    }

    $handler->saveTo('/storage/gallery');
}
```

## Full example with entity update

```php
#[Route(path: '/profile/avatar', name: 'profile.avatar', methods: ['POST'])]
public function updateAvatar(
    ServerRequestInterface $request,
    #[CurrentUser] User $user,
): ResponseInterface {
    $upload = UploadedFileErrorHandler::from($request->getUploadedFiles()['avatar'])
        ->isImage()
        ->maxSize(2 * 1024 * 1024);

    if ($upload->hasErrors()) {
        $this->flashBag->add('error', implode(', ', $upload->getErrors()));
        return Response::redirect($this->urlGenerator->generate('profile'));
    }

    $ext = pathinfo($upload->getFile()->getClientFilename(), PATHINFO_EXTENSION);
    $upload->saveTo($this->baseDir . '/storage/avatars', 'user-' . $user->getId() . '.' . $ext);

    $user->setAvatarPath($upload->getStoredFilePath());
    $this->entityManager->flush();

    return Response::redirect($this->urlGenerator->generate('profile'));
}
```

## PHP upload limits

`UploadedFileErrorHandler` validates the uploaded file after PHP has received it. PHP's own limits apply first:

- `upload_max_filesize` — maximum size of a single uploaded file
- `post_max_size` — maximum size of the entire POST body
- `max_file_uploads` — maximum number of files per request

Set these in `php.ini` or a `.user.ini` file in `public/`. AppKit cannot override PHP-level upload limits.

## Large and resumable uploads

For large files — videos, high-resolution images, bulk imports — use [`modufolio/tus-psr7`](https://github.com/modufolio/tus-psr7). It implements the [TUS resumable upload protocol](https://tus.io/) and is PSR-7 native, so it slots directly into an AppKit controller.

```bash
composer require modufolio/tus-psr7
```

Wire it as a controller action and forward the request:

```php
use Modufolio\Tus\TusServer;

#[Route(path: '/upload/tus', name: 'upload.tus', methods: ['GET', 'HEAD', 'POST', 'PATCH', 'DELETE'])]
public function tus(ServerRequestInterface $request): ResponseInterface
{
    $server = new TusServer(
        uploadDir: $this->baseDir . '/storage/uploads/tmp',
        maxSize:   TusServer::calculateMaxSize(), // reads from php.ini
    );

    $server->setAllowedMimeTypes(['image/jpeg', 'image/png', 'video/mp4']);

    return $server->handleRequest($request);
}
```

Once the upload finishes, move it to its permanent location:

```php
$server->completeAndFetch($filename, $this->baseDir . '/storage/uploads');
```

On the frontend, use any TUS client — [`tus-js-client`](https://github.com/tus/tus-js-client) works well with Vue.js and Inertia.js. The protocol handles interrupted uploads, network failures, and progress reporting automatically.
