# Getting started

AppKit Skeleton is the recommended way to start a new AppKit project. It gives you a working app with authentication, a User entity, Doctrine ORM, and a Tailwind CSS build pipeline — all wired up and ready to extend.

Everything on this page — the commands, the files, the directories — comes from the skeleton (`modufolio/appkit-skeleton`), not from the framework. Once created, it is application code and yours to change.

## Installing

Create a project from the skeleton. It is a template — `create-project` copies it once and from then on the code is yours, like the rest of your app.

```bash
composer create-project modufolio/appkit-skeleton my-app
cd my-app
npm install
```

Or clone the repository directly (useful for trying an unreleased state):

```bash
git clone https://github.com/modufolio/appkit-skeleton my-app
cd my-app
composer install
npm install
```

## Configuring your environment

Copy the example file to `.env`. The `.env` file is gitignored — never commit it.

```bash
cp .env.example .env
```

`.env.example` lists four variables:

| Variable | Description |
|----------|-------------|
| `APP_ENV` | `dev`, `test`, or `prod`. Controls caching, error verbosity, and debug mode. |
| `COOKIE_SECURE` | Set to `true` when running behind HTTPS to add the `Secure` flag to session cookies. Use `false` in development. |
| `REMEMBER_ME_SECRET` | Signing secret for remember-me cookies — a 64-character hex string (`php -r "echo bin2hex(random_bytes(32));"`). The skeleton's `AuthenticatorFactory` throws when the `remember_me` authenticator is used without it; remove `remember_me` from `config/security.php` if you do not want it. |
| `APP_URL` | Optional. The skeleton's own variable, available to your code as `env('APP_URL')`; nothing in the framework or in the skeleton's shipped code reads it. The framework derives base URLs from the request (`$this->url()` in templates). |

Use the `env()` helper to read environment variables anywhere in your config files:

```php
env('APP_ENV', 'prod')             // returns string
env('COOKIE_SECURE', false)        // returns bool when value is "true" or "false"
env()->getBool('COOKIE_SECURE')    // always a bool, or throws if unset
env()->getRequired('JWT_SECRET')   // throws when the secret is missing
```

See [Configuration](configuration.md) for the full set of typed accessors.

`env()` checks `$_ENV`, then `$_SERVER`, then whatever `.env` file was loaded into the reader — in that order, so a real environment variable always wins. Loading the file is one line in `bootstrap.php`: `(new Env())->fromFile(BASE_DIR . '/.env')->freeze();`. The framework's own `bootstrap.php` and the RoadRunner reference do this; the skeleton's `bootstrap.php` currently does not, so until you add that line the skeleton reads only real environment variables and `cp .env.example .env` has no effect. The file is read once at boot and the reader is then frozen. In production, set variables in your web server config or container environment and skip the `.env` file entirely; a missing file is not an error.

**Limitations.** The built-in `env()` helper reads a single `.env` file using `parse_ini_file()`. It does not support multiple layered files (`.env.local`, `.env.test`), variable interpolation, or multiline values. If you need any of those, replace it with [Symfony Dotenv](https://symfony.com/doc/current/components/dotenv.html) — see [Configuration](configuration.md) for the upgrade path.

## Building assets

Compile Tailwind CSS and your JavaScript bundle into `public/assets/`.

```bash
npm run build
```

Compiled files are gitignored — rebuild them in every deployment.

## Creating the database

For a new project, create the tables directly from your entity definitions.

```bash
php bin/console orm:schema-tool:create
```

For an existing project with data, run migrations instead.

```bash
php bin/console migrations:migrate
```

> Never run `orm:schema-tool:create` against a database that already has data. It will attempt to create tables that already exist.

## Writable directories

Two directories must exist and be writable before the app can run:

| Directory | What goes in it |
|-----------|-----------------|
| `storage/logs/` | Application logs (`app.log`, `error.log`), written by the skeleton's `FileLogger` |
| `var/` | Generated files: the compiled service container (`var/cache/<env>/`), Doctrine cache and proxies, sessions (`var/sessions/`), brute-force state (`var/brute-force/`) |

Both are already present in the skeleton with a `.gitkeep`. Their contents are gitignored — never commit files from these directories.

`storage/` is for app data that must persist across deploys — logs, uploaded files.

`var/` is for generated, re-buildable files. You can safely delete its contents — the app recreates them on the next request. It must be writable by the CLI user too: `bin/console` boots the same container.

## Starting the development server

```bash
composer start
# → http://localhost:8000
```

This runs the PHP built-in server with `router.php` as the router script. It serves files from `public/` and forwards everything else to `public/index.php`. It is for development only.

## Creating your first user

The app ships with a login page at `/login` but no users. Create one with the console command.

```bash
php bin/console app:add-user
```

Follow the prompts, or pass arguments directly.

```bash
php bin/console app:add-user you@example.com yourpassword --admin
```

Users with `--admin` get `ROLE_ADMIN`, which includes `ROLE_USER` via the role hierarchy.

## What you should see

Open `http://localhost:8000`. The welcome page says whether you are signed in; it does not link to the login form, so go to `/login` yourself. After authenticating, your email address and a sign-out button appear on the welcome page.

## Next steps

- [Project structure](configuration.md) — learn where everything lives
- [Routing](routing.md) — add new pages
- [Controllers](controllers.md) — handle requests and render templates
- [Security](security.md) — protect routes and manage roles

