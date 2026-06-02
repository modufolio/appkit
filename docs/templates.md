# Templates

AppKit's template engine is built around plain PHP files. There is no new syntax to learn and no compilation step. Variables passed to `render()` become available directly in the template.

The engine is intentionally minimal. If your project needs a different template engine, add it as a Composer dependency and use it directly.

## Template basics

Create a PHP file in `resources/views/`. Construct a `Template` instance in your controller, then call `render()`.

```php
use Modufolio\Appkit\Template\Template;

$template = new Template(
    name:          'about',
    templatePaths: [dirname(__DIR__, 2) . '/resources/views'],
    layoutPaths:   [dirname(__DIR__, 2) . '/resources/views/layouts'],
    request:       $request,
);

$html = $template->render(['title' => 'About us']);
```

The template file at `resources/views/about.php` receives `$title`, `$template` (the current `Template` instance), and any other keys you passed.

## Declaring a layout

Add this line at the top of a template to wrap it in a layout:

```php
<?php $template->layout('default') ?>
```

AppKit looks for `resources/views/layouts/default.php`. The template's rendered output is available in the layout as `$content`.

Omit the layout declaration to render the template standalone.

## Creating a layout

A layout file wraps the template output and provides the HTML shell.

```php
<!-- resources/views/layouts/default.php -->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= $this->esc($title ?? 'App') ?></title>
    <?php $this->css('/assets/css/app.css') ?>
    <?= $this->renderCss() ?>
</head>
<body>
    <?= $content ?>
    <?php $this->js('/assets/js/app.js') ?>
    <?= $this->renderJs() ?>
</body>
</html>
```

`$this` inside both templates and layouts refers to the `Template` instance.

## Sections

Sections let a template define named regions that the layout can place anywhere.

In the template, wrap content in `start()` / `end()`:

```php
<?php $template->layout('default') ?>

<?php $template->start('sidebar') ?>
<nav>...</nav>
<?php $template->end() ?>

<main>Page content here.</main>
```

In the layout, output the section:

```php
<aside><?= $template->section('sidebar') ?></aside>
<main><?= $content ?></main>
```

Provide a default value for when the section is empty:

```php
<?= $template->section('sidebar', '<p>No sidebar.</p>') ?>
```

## Snippets

Snippets are reusable partial templates. They share the parent's asset collection, so any CSS or JS queued inside a snippet is available in the final `renderCss()` / `renderJs()` output.

```php
<?= $template->snippet('cards/post', ['post' => $post]) ?>
```

AppKit looks for `resources/views/cards/post.php`. The array passed as the second argument becomes available as variables inside the snippet.

## Template helper reference

| Helper | Description |
|--------|-------------|
| `$template->layout(string $name)` | Declare the parent layout (call once, at the top). |
| `$template->start(string $name)` | Begin capturing a named section. |
| `$template->end()` | Stop capturing the current section. |
| `$template->section(string $name, string $default = '')` | Output a named section in the layout. |
| `$template->snippet(string $name, array $data = [])` | Render a partial template. |
| `$this->esc(?string $value, string $context = 'html')` | Context-aware output escaping (`html`, `attr`, `js`, `css`, `url`). |
| `$this->url(string $path = '')` | Prepend `APP_URL` to a path. |
| `$this->css(string\|array $url)` | Queue a stylesheet for `renderCss()`. |
| `$this->js(string\|array $url)` | Queue a script for `renderJs()`. |
| `$this->renderCss()` | Emit all queued `<link>` tags. |
| `$this->renderJs()` | Emit all queued `<script>` tags. |
| `$template->exists()` | Check if the template file exists on disk. |
| `$template->name()` | Return the template's name string. |

## Queuing assets per template

Call `$this->css()` or `$this->js()` inside any template or snippet to queue an asset. Assets bubble up to the parent template's collection.

```php
<!-- resources/views/pages/dashboard.php -->
<?php $template->layout('default') ?>
<?php $this->js('/assets/js/charts.js') ?>

<h1>Dashboard</h1>
```

The `charts.js` script tag will appear in the layout's `renderJs()` output.

## Escaping output

AppKit does not escape output automatically. Use the context-aware `$this->esc()` helper on every untrusted value, and pick the context that matches *where* the value is printed. Escaping rules differ between HTML text, attributes, JavaScript, CSS, and URLs — using the wrong one (for example HTML-escaping a value placed inside a `<script>` block) leaves an XSS hole.

```php
<p><?= $this->esc($user->getName()) ?></p>                  <!-- html (default) -->
<input value="<?= $this->esc($query, 'attr') ?>">           <!-- attribute -->
<script>var t = "<?= $this->esc($title, 'js') ?>";</script> <!-- JS string -->
<div style="color: <?= $this->esc($color, 'css') ?>"></div> <!-- CSS value -->
<a href="/search?q=<?= $this->esc($query, 'url') ?>">…</a>   <!-- URL component -->
```

| Context | Use for |
|---------|---------|
| `html` (default) | Text inside an HTML element |
| `attr` | A value inside a quoted HTML attribute |
| `js` | A value inside a `<script>` block or JS string literal |
| `css` | A value inside a `<style>` block or CSS context |
| `url` | A value used as a URL query-string component |

`esc()` accepts `null` (it becomes an empty string). **An unrecognised context returns the value unescaped**, so always pass one of the five names above. The helper wraps [laminas/laminas-escaper](https://docs.laminas.dev/laminas-escaper/) — the same battle-tested escaper Kirby uses; `Str::esc($value, $context)` exposes the identical function outside templates.

For blocks of HTML you generate and trust yourself, output directly with `<?=`.

## Error templates

AppKit uses `resources/views/errors/default.php` for HTTP errors. The exception handler renders it and passes three variables:

| Variable | Type | Description |
|----------|------|-------------|
| `$status` | `int` | HTTP status code (404, 500, etc.) |
| `$title` | `string` | Short error title |
| `$detail` | `string\|null` | Longer description (only in `dev` and `test` environments) |

Customise this template to match your design.

## The `Template` constructor

```php
new Template(
    string $name,                          // Template name (maps to a .php file)
    array $templatePaths = [],             // Directories to search for templates
    array $layoutPaths = [],               // Directories to search for layouts
    array $data = [],                      // Initial data (merged with render() data)
    ?ServerRequestInterface $request = null, // Used for URL generation
    ?AssetCollection $assets = null,       // Shared asset collection (rarely set manually)
)
```

Add paths after construction:

```php
$template->addTemplatePath('/path/to/more/views');
$template->addLayoutPath('/path/to/more/layouts');
```

## Casting to string

`Template` implements `Stringable`. Casting to string returns the template's name, not its rendered output.

```php
(string) $template; // 'home'
```

To render, always call `render()`.

## RoadRunner safety

`Template` uses instance-based state only. No static properties means no bleed between requests in long-running workers. The asset collection is shared between a parent template and its cloned snippets, but never between separate requests.
