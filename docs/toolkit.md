# Toolkit

AppKit includes four static utility classes for everyday operations on arrays, files, strings, and directories. They live in `Modufolio\Appkit\Toolkit`.

## Array utilities — `A`

`Modufolio\Appkit\Toolkit\A` provides static methods for working with arrays.

```php
use Modufolio\Appkit\Toolkit\A;
```

### Getting values

```php
A::get($array, 'key', 'default');          // nested key with dot notation: 'user.name'
A::first($array);                          // first element
A::last($array);                           // last element
A::pluck($array, 'email');                 // extract one column as flat array
A::only($array, ['id', 'name']);           // keep only specified keys
A::without($array, ['password', 'token']); // remove specified keys
```

### Transforming

```php
A::map($array, fn($item) => $item->getId());
A::filter($array, fn($item) => $item->isEnabled());
A::groupBy('status', $array);              // group items by a key value
A::keyBy($array, 'id');                    // re-index array by a field value
A::sort($array, 'name', 'asc');
A::unique($array);
A::flatten($array);                        // collapse nested arrays one level
```

### Merging

```php
A::merge($a, $b);                          // recursive merge with MERGE_OVERWRITE
A::merge($a, $b, A::MERGE_APPEND);        // append instead of overwrite
A::merge($a, $b, A::MERGE_REPLACE);       // replace top-level keys
A::extend($a, $b, $c);                    // shallow merge, last value wins
A::update($array, ['status' => 'active']); // update matching keys only
```

### Checking

```php
A::has($array, 'value');                   // check if value exists
A::missing($array, ['name', 'email']);     // return required keys that are absent
A::isList($array);                         // true if array has sequential integer keys
A::isAssociative($array);                  // true if array has string keys
A::some($array, fn($item) => $item > 0);  // true if any element passes callback
A::contains('needle', $array);             // find first element containing 'needle'
```

### Other

```php
A::join($array, ', ');                     // implode with separator
A::query($array);                          // build URL query string
A::random($array, 3);                      // pick 3 random elements
A::shuffle($array);
A::sum($array);
A::average($array, 2);                     // average, 2 decimal places
A::dot($array);                            // flatten to dot-notation keys
A::wrap($value);                           // ensure value is an array
```

## File utilities — `F`

`Modufolio\Appkit\Toolkit\F` provides static methods for working with files.

```php
use Modufolio\Appkit\Toolkit\F;
```

### Reading and writing

```php
F::read('/path/to/file.txt');                    // string|false
F::write('/path/to/file.txt', 'content');        // bool
F::append('/path/to/file.txt', 'more content'); // bool
F::load('/path/to/config.php', $fallback);       // require and return, or fallback
```

### File information

```php
F::exists('/path/to/file.txt');       // bool
F::size('/path/to/file.txt');         // int — bytes
F::niceSize('/path/to/file.txt');     // string — '2.4 MB'
F::mime('/path/to/image.jpg');        // string|null — 'image/jpeg'
F::extension('/path/to/file.txt');    // string — 'txt'
F::name('/path/to/file.txt');         // string — 'file' (no extension)
F::filename('/path/to/file.txt');     // string — 'file.txt'
F::dirname('/path/to/file.txt');      // string — '/path/to'
F::modified('/path/to/file.txt');     // int — Unix timestamp
F::type('/path/to/image.jpg');        // string|null — 'image'
```

### Copying, moving, renaming

```php
F::copy('/source.txt', '/dest.txt');           // bool
F::move('/old.txt', '/new.txt');               // bool
F::rename('/path/file.txt', 'newname');        // string|false — new path
F::remove('/path/to/file.txt');               // bool
```

### Safe names

```php
F::safeName('My File (1).txt');    // 'my-file-1.txt'
F::safeBasename('My Photo.JPG');  // 'My Photo.JPG' — preserves extension case
```

### MIME and type lookups

```php
F::mimeToExtension('image/jpeg');   // 'jpg'
F::extensionToMime('png');          // 'image/png'
F::mimeToType('image/jpeg');        // 'image'
F::extensionToType('mp4');          // 'video'
F::extensions('image');             // ['jpg', 'jpeg', 'png', 'gif', 'webp', ...]
```

## String utilities — `Str`

`Modufolio\Appkit\Toolkit\Str` provides static methods for string manipulation, including full Unicode support.

```php
use Modufolio\Appkit\Toolkit\Str;
```

### Case conversion

```php
Str::camel('hello_world');      // 'helloWorld'
Str::snake('helloWorld');        // 'hello_world'
Str::kebab('helloWorld');        // 'hello-world'
Str::studly('hello_world');      // 'HelloWorld'
Str::title('hello world');       // 'Hello World'
Str::upper('hello');             // 'HELLO'
Str::lower('HELLO');             // 'hello'
Str::ucfirst('hello world');     // 'Hello world'
```

### Searching and testing

```php
Str::contains('hello world', 'world');        // bool
Str::containsAll('hello world', ['hello', 'world']); // bool
Str::startsWith('hello world', 'hello');      // bool
Str::endsWith('hello world', 'world');        // bool
Str::length('hello');                         // int (multibyte-safe)
Str::position('hello world', 'world');        // int|false
```

### Extracting

```php
Str::before('user@example.com', '@');   // 'user'
Str::after('user@example.com', '@');    // 'example.com'
Str::between('<p>text</p>', '<p>', '</p>'); // 'text'
Str::from('user@example.com', '@');     // '@example.com'
Str::until('user@example.com', '@');    // 'user'
Str::substr('hello world', 6, 5);       // 'world'
```

### Generating and formatting

```php
Str::slug('Hello World! 2025');         // 'hello-world-2025'
Str::excerpt('Long text…', 100);        // truncate to 100 chars with ellipsis
Str::words('Long text…', 10);           // truncate to 10 words
Str::short('Long text', 20, '…');       // truncate with custom appendix
Str::random(32, 'alphaNum');            // random string
Str::uuid();                            // RFC 4122 UUID v4
Str::plural('post');                    // 'posts'
Str::singular('posts');                 // 'post'
Str::widont('one two three');           // 'one two&nbsp;three' — prevent widow words
```

### Templating

```php
Str::template('Hello, {name}!', ['name' => 'Alice']); // 'Hello, Alice!'
```

### ASCII and encoding

```php
Str::ascii('Ünïcödé');    // 'Unicode'
Str::encode('Hello');      // HTML entity encoding
Str::unhtml('&lt;p&gt;'); // '<p>'
```

## Directory utilities — `Dir`

`Modufolio\Appkit\Toolkit\Dir` provides static methods for working with directories.

```php
use Modufolio\Appkit\Toolkit\Dir;
```

### Creating and removing

```php
Dir::make('/path/to/new/dir');          // bool — creates recursively
Dir::remove('/path/to/dir');            // bool — removes recursively
Dir::copy('/source/dir', '/dest/dir');  // bool
Dir::move('/old/path', '/new/path');    // bool
```

### Reading

```php
Dir::read('/path/to/dir');              // string[] — filenames (no dot files)
Dir::files('/path/to/dir');             // string[] — file names only
Dir::dirs('/path/to/dir');              // string[] — subdirectory names only
Dir::index('/path/to/dir', true);       // recursive file listing
```

Pass `absolute: true` to get full paths instead of basenames:

```php
Dir::files('/path/to/dir', ignore: null, absolute: true);
```

### Checking

```php
Dir::exists('/path/to/dir');        // bool
Dir::isEmpty('/path/to/dir');       // bool
Dir::isReadable('/path/to/dir');    // bool
Dir::isWritable('/path/to/dir');    // bool
```

### Metadata

```php
Dir::size('/path/to/dir');          // int — total bytes, recursive
Dir::niceSize('/path/to/dir');      // string — '14.3 MB'
Dir::modified('/path/to/dir');      // int — Unix timestamp of most recent change
Dir::wasModifiedAfter('/path', $time); // bool
```

## Data serialization — `Data`

`Modufolio\Appkit\Data\Data` reads and writes structured data files.

```php
use Modufolio\Appkit\Data\Data;

Data::read('/path/to/config.json');              // array
Data::write('/path/to/config.json', $array);     // bool

Data::encode(['key' => 'value'], 'json');        // string
Data::decode('{"key":"value"}', 'json');         // array
```

Supported formats: `json`, `yaml`, `php`, `xml`, `txt`.

## Key-value storage — `Storage`

`Modufolio\Appkit\Data\Storage` provides a simple file-backed key-value store.

```php
use Modufolio\Appkit\Data\Storage;

$store = new Storage('/path/to/store.json');

$store->insert('theme', 'dark');
$store->insert(['lang' => 'en', 'timezone' => 'UTC']);
$store->save(); // write to disk

$store->get('theme');           // 'dark'
$store->get('missing', 'default'); // 'default'
$store->remove('theme');
$store->save();
```

## Query language — `Query`

`Modufolio\Appkit\Query\Query` extracts nested values from arrays and objects using a dot-notation path string.

```php
use Modufolio\Appkit\Query\Query;

$data = [
    'user' => ['name' => 'Alice', 'roles' => ['admin', 'editor']],
];

Query::factory('user.name')->resolve($data);      // 'Alice'
Query::factory('user.roles.0')->resolve($data);   // 'admin'
```

`intercept()` applies a transformation on the resolved value before returning it.
