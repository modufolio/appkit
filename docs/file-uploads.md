# File uploads

`UploadedFileErrorHandler` gives you a fluent chain for validating and storing uploaded files.

## Basic usage

Get the uploaded file from the request, pass it to `UploadedFileErrorHandler::from()`, add your validation rules, then save it.

```php
use Modufolio\Appkit\Http\UploadedFileErrorHandler;
use Modufolio\Appkit\Toolkit\Str;
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
| `isImage(?string $message)` | Shorthand for common raster image MIME types (jpeg, png, gif, webp). SVG is excluded — it can carry scripts. Allowing it takes two steps: validate with `hasMimeType('image/svg+xml')`, and pass `allowUnsafeExtension: true` to `saveTo()`, because `svg` is on the extension denylist and is refused at save time regardless of the MIME check. Do both only after sanitising the markup. |
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
saveTo(string $path, ?string $filename = null, bool $allowUnsafeExtension = false): self
```

```php
$upload->saveTo('/absolute/path/to/directory');   // keeps the client filename

// With a custom filename — used verbatim, so INCLUDE the extension yourself
$upload->saveTo('/absolute/path/to/directory', Str::uuid() . '.jpg');
```

The filename is passed through `F::safeName()` and used as given; no extension is
appended. Omitting it writes an extensionless file, which then breaks anything
downstream that infers type from the path — image processing in particular.

`saveTo()` never overwrites: if the target already exists it throws. So the
filename must be unique per upload — a UUID, a database id, a timestamp plus
random suffix — not a fixed name derived from the user, which collides the second
time that user uploads.

By default it also refuses server-executable and config extensions (`php`,
`phtml`, `phar`, `sh`, `htaccess`, `ini`, `exe`, `svg`, …) even when no validator
was chained, so a forgotten `isImage()` cannot drop a `.php` file into a served
directory. `allowUnsafeExtension: true` switches that check off for the one call;
use it only for a directory you fully control that is not served as code.

SVG is the usual reason to reach for it. An SVG can carry scripts, so sanitise
the markup before it is ever served inline, and keep the MIME check:

```php
$upload->hasMimeType('image/svg+xml')->maxSize(512 * 1024);

if (!$upload->hasErrors()) {
    $upload->saveTo($dir, Str::uuid() . '.svg', allowUnsafeExtension: true);
}
```

`safeName()` slugs the name (lowercase, strict character set), which is the right
choice when you are minting a fresh storage name. It is the wrong choice when the
name must round-trip — e.g. a TUS staging token you look up on disk afterward, or
a client filename you echo back verbatim — because slugging changes it. For those,
sanitise with `F::safeFilename()` instead: it strips the directory component and
control characters (closing path traversal) but preserves the token exactly. See
[toolkit.md](toolkit.md) for the distinction.

To keep the original extension:

```php
$ext = pathinfo($upload->getFile()->getClientFilename(), PATHINFO_EXTENSION);
$upload->saveTo($dir, Str::uuid() . '.' . $ext);
```

`saveTo()` throws `\InvalidArgumentException` in four cases: validation errors are
present, the resolved filename is empty, the extension is on the denylist and
`allowUnsafeExtension` is false, or the target file already exists. It throws
`\RuntimeException` when the directory cannot be created. Always check
`hasErrors()` *before* calling `saveTo()`, and pick a filename that cannot exist
yet; wrap the call in a try/catch for the rest.

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

    // Two files in one batch can share a client filename, and saveTo()
    // refuses to overwrite — mint a unique name per file.
    $ext = pathinfo($uploaded->getClientFilename(), PATHINFO_EXTENSION);
    $handler->saveTo('/storage/gallery', Str::uuid() . '.' . $ext);
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

    // Not 'user-{id}.{ext}': saveTo() refuses to overwrite, so a fixed name
    // throws the second time this user changes their avatar.
    $ext = pathinfo($upload->getFile()->getClientFilename(), PATHINFO_EXTENSION);
    $upload->saveTo($this->baseDir . '/storage/avatars', Str::uuid() . '.' . $ext);

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

[`modufolio/tus-psr7`](https://github.com/modufolio/tus-psr7) is a separate package, not part of AppKit — install it with `composer require modufolio/tus-psr7`. For large files — videos, high-resolution images, bulk imports — it implements the [TUS resumable upload protocol](https://tus.io/) and is PSR-7 native, so it slots directly into an AppKit controller.

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
