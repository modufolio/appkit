# Inertia

AppKit speaks [Inertia.js](https://inertiajs.com) natively: a controller
returns a page, the kernel turns it into the response the client expects.
The client is the official `@inertiajs/vue3`, `@inertiajs/react` or
`@inertiajs/svelte`, version 3.

## A page

```php
use Modufolio\Appkit\Inertia\Inertia;

public function edit(User $user): Inertia
{
    return Inertia::render('Users/Edit', [
        'user'     => $user,
        'roles'    => fn () => $roles->all(),                       // computed only if sent
        'activity' => Inertia::defer(fn () => $log->for($user)),     // fetched after first paint
        'feed'     => Inertia::merge($feed->page($page)),            // appended on the client
        'flash'    => Inertia::always($messages),                    // on every partial reload too
    ]);
}
```

`Inertia` is a value: the component, the props as declared, and what this
response asks of the client (`->flash()`, `->encryptHistory()`,
`->clearHistory()`, `->preserveFragment()`). It knows nothing of the
request. When the controller returns it, the kernel hands it to the
`InertiaRenderer` — `$app->inertia()` — which builds the page object
against the request's partial-reload headers, merges the shared props
underneath, pulls the flash store, and answers as JSON to the client's XHR
or as the host's HTML document on a first visit.

## Wiring

```php
// config/modules.php
return [
    \Modufolio\Appkit\Inertia\InertiaModule::class => [
        'version_file' => __DIR__ . '/sri.php',   // or 'version' => '…'
    ],
];

// config/services.php
$services
    ->set(RootViewInterface::class, fn () => new CallableRootView(
        fn (Page $page, ServerRequestInterface $request) => $twig->render('app.html.twig', ['inertia' => Inertia::snippet($page)]),
    ))
    ->set(SharedPropsInterface::class, fn (App $app) => $app->props());   // optional
```

`RootViewInterface` is the document a first visit receives, with
`Inertia::snippet($page)` where the client boots. `CallableRootView` takes a
`\Closure(Page, ServerRequestInterface): string|ResponseInterface` — declare
both parameters even when you only use the page, or PHPStan will flag the
closure — and wraps a returned string in an HTML response. `SharedPropsInterface`
supplies the props every page carries — auth, navigation, CSRF — as values
or closures; a closure is computed only when its prop travels. Flash data
waits in the session's flash bag between requests; declare a
`FlashStoreInterface` to keep it somewhere else. A host that declares
`InertiaRenderer` itself keeps its own.

## What the protocol gets you

- **Partial reloads.** `X-Inertia-Partial-Data` and `-Except` narrow the
  props, matching dot paths at any depth; closures are resolved only after
  that. `Inertia::always()` props travel regardless.
- **Optional and deferred props.** `optional()` is sent only when asked for;
  `defer()` is left out of the first response and listed under
  `deferredProps`, by group; `defer($fn, rescue: true)` fails softly.
- **Merge props.** `merge()` and `deepMerge()`, with `prepend()`,
  `append('data', 'id')` and `matchOn('id')`; `X-Inertia-Reset` names the
  ones to replace this once.
- **Once props.** `once()` is sent on the first response and kept by the
  client across visits; `->as('prefs')`, `->until(3600)`, `->fresh()`.
- **Infinite scroll.** `scroll($page, ScrollMetadata::fromPage($n, $perPage,
  $total))` merges inside the wrapper, appended or prepended as the client's
  scroll component asks.
- **The version handshake.** An XHR GET whose `X-Inertia-Version` differs
  from yours gets a 409 with `X-Inertia-Location`, and the client reloads in
  full before rendering props its components no longer understand.
- **`Inertia::location($url)`** for leaving the app: a download, an external
  page.
- **Flash.** `->flash('saved', true)` on a page, or
  `$this->inertia->flash('saved', true)` before a redirect: the next page
  carries it as `usePage().flash`.
- **Prefetching.** A `<Link prefetch>` or `router.prefetch()` request carries
  `Purpose: prefetch`; the renderer answers it like any other but leaves the
  flash store for the visit that follows.
- **Error bags.** A shared `errors` prop is wrapped under the bag named in
  `X-Inertia-Error-Bag`.
- **`Vary: X-Inertia`** on every response, since one URL answers HTML or JSON.
- **Errors.** A request carrying `X-Inertia` gets a JSON:API error body
  whatever its `Accept` header says — the client sends `text/html` on its
  XHRs too — so a client-side handler can read `errors[0].title` and
  `detail`. A hard page load that errors gets the HTML page instead. See
  [Exception handling](exception-handling.md#content-negotiation).

## Seeing the page object

Install the **Inertia.js devtools** extension for Chrome: it adds an
*Inertia.js* panel to DevTools that shows the page object the client received
for the current visit — `component`, the resolved `props`, `url`, `version`
and `sharedProps` — so what the kernel sent is readable without reaching for
the Network tab or dumping on the server. Handy for checking that a partial
reload narrowed the props you expected, that a deferred prop arrived on its
follow-up request, or that the shared `auth`, `flash` and CSRF props are what
the page thinks they are.

## Testing

```php
use Modufolio\Appkit\Inertia\Testing\InertiaPage;

$page = InertiaPage::fromResponse($response);   // JSON or a rendered document
self::assertSame('Users/Edit', $page->component());
self::assertSame('Ada', $page->prop('user.name'));
self::assertSame(['default' => ['activity']], $page->deferredProps());
```
