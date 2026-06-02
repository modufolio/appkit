# Getting started

AppKit Skeleton is the recommended way to start a new AppKit project. It gives you a working app with authentication, a User entity, Doctrine ORM, and a Tailwind CSS build pipeline — all wired up and ready to extend.

## Installing

AppKit Skeleton is a starting point, not a Composer package. Clone or download it, then install dependencies.

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

The three variables you need to set:

| Variable | Description |
|----------|-------------|
| `APP_ENV` | `dev`, `test`, or `prod`. Controls caching, error verbosity, and debug mode. |
| `APP_URL` | Base URL of your app, e.g. `http://localhost:8000`. No trailing slash. |
| `COOKIE_SECURE` | Set to `true` when running behind HTTPS to add the `Secure` flag to session cookies. Use `false` in development. |

Use the `env()` helper to read environment variables anywhere in your config files:

```php
env('APP_ENV', 'prod')         // returns string
env('COOKIE_SECURE', false)    // returns bool when value is "true" or "false"
```

`env()` checks `$_ENV`, then `$_SERVER`, then your `.env` file — in that order. The `.env` file is parsed once and cached for the request. In production, set variables in your web server config or container environment and skip the `.env` file entirely.

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
| `storage/logs/` | Application logs (`app.log`, `error.log`) |
| `var/` | Framework-managed files: Doctrine cache, ORM proxies, router cache |

Both are already present in the skeleton with a `.gitkeep`. Their contents are gitignored — never commit files from these directories.

`storage/` is for app data (logs, uploaded files, brute-force protection state). It should persist across deploys.

`var/` is for generated, re-buildable files. You can safely delete its contents — AppKit recreates them on the next request.

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

Open `http://localhost:8000`. You will see a welcome page. If you are not logged in, it shows a link to `/login`. After authenticating, your email address appears and a logout button is available.

## Next steps

- [Project structure](configuration.md) — learn where everything lives
- [Routing](routing.md) — add new pages
- [Controllers](controllers.md) — handle requests and render templates
- [Security](security.md) — protect routes and manage roles

