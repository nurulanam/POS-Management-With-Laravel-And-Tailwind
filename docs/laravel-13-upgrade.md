# Laravel 10 → 13 upgrade

## Status

The application code and Composer constraints target Laravel **13.38.0 or newer
13.x releases**, the latest stable release checked on October 23, 2026.
**Dependency resolution and runtime verification are still pending.**

The editing sandbox did not have PHP or Composer. Packagist and the hosts serving
PHP / Composer release downloads were inaccessible. The existing `composer.lock`
is intentionally unchanged: it still describes Laravel 10 and **does not match
the updated `composer.json`**. Do not deploy this change or run `composer install`
until the lockfile has been regenerated and the checks below have passed. Do not
hand-edit or delete the lockfile to hide this mismatch.

## Checks completed in the editing sandbox

- Static parsing of all 51 application, configuration, migration, route, and test
  PHP files passed. This is not a substitute for PHP linting or execution.
- Composer JSON and PHPUnit XML syntax checks passed; `git diff --check` passed.
- `npm ci --ignore-scripts` and `npm run build` passed.
- npm reported 19 vulnerabilities in the existing frontend dependency tree
  (7 moderate, 11 high, 1 critical). Frontend dependencies were not changed by
  this Laravel upgrade and require a separate security review.
- Composer dependency resolution, security auditing, Artisan commands, and the
  PHPUnit suite could not be run in this sandbox.

## Requirements

- PHP **8.3+** with Laravel's required extensions: Ctype, cURL, DOM, Fileinfo,
  Filter, Hash, Mbstring, OpenSSL, PCRE, PDO, Session, Tokenizer, and XML.
- The PDO driver for your deployment database (normally `pdo_mysql`).
- `pdo_sqlite`, SQLite 3.26+, and XMLWriter for the automated tests.
- Composer 2 with access to Packagist and package download hosts.
- Node.js / npm for the existing Vite / Tailwind asset build.

## Complete and verify the upgrade

Run these commands in a development checkout with PHP 8.3 (the minimum supported
version), not directly against a production installation:

```sh
php --version
composer update --with-all-dependencies
composer validate --strict
composer check-platform-reqs
composer audit
php artisan --version
php artisan config:clear
php artisan route:list
php artisan test
npm ci
npm run build
php artisan view:cache
php artisan view:clear
php artisan config:cache
php artisan config:clear
php artisan route:cache
php artisan route:clear
```

For a fresh checkout, copy `.env.example` to `.env` and run
`php artisan key:generate` after resolving dependencies. **Never replace an
existing installation's `APP_KEY`.** Configure a disposable database for any
manual migration checks. The automated database tests force SQLite `:memory:`
and use a test-only encryption key; they do not require a running MySQL server or
built Vite assets.

Review and commit the generated `composer.lock` alongside these changes before
merging. Repeat the tests on the PHP version used in production. Subsequent
installs and deployments should use `composer install`, not `composer update`.

## Compatibility changes

- PHP: `^8.1` → `^8.3`; Laravel: `^10.10` → `^13.38`.
- Sanctum: 3 → 4; Tinker: 2 → 3; Collision: 7 → 8; PHPUnit: 10 → 12.
- Ignition now requires a release with declared Laravel 13 support.
- The existing application structure, service providers, HTTP / console kernels,
  routes, and database / filesystem defaults are retained. A skeleton rewrite is
  not required for this upgrade.
- The application CSRF middleware now extends `PreventRequestForgery`, and the
  web middleware group references it. Sanctum uses its current
  `validate_csrf_token` configuration key and session-authentication middleware,
  while preserving the application's custom cookie and CSRF middleware.
- The personal access token migration already exists in `database/migrations`,
  including `expires_at`. Do **not** publish a duplicate Sanctum migration.
- Cache object deserialization is disabled with `serializable_classes => false`.
  If future code caches objects, explicitly allow only the necessary classes.
- Session serialization explicitly remains `php` to preserve existing sessions.
  Switching to `json` is a separate hardening option that invalidates active
  sessions; plan it separately. Existing cookie names and configured prefixes
  are preserved. Laravel 11 changed Redis / Memcached / DynamoDB cache prefix
  handling, so deployments using those stores should plan for cold caches.
- Regression tests cover the dashboard, real Sanctum bearer tokens (valid,
  invalid, expired, and absent), and Laravel 13 request-forgery protection.

## Deployment after verification

Back up the database, retain the existing `.env` and `APP_KEY`, and confirm that
the web runtime and queue workers use PHP 8.3+. Follow the normal deployment
maintenance procedure, install the committed lockfile with
`composer install --no-dev --optimize-autoloader`, build assets, clear stale
application caches, run any pending migrations with `php artisan migrate --force`,
rebuild configuration / route / view caches, and restart long-running workers.
No new application migration is introduced by this upgrade.

## References

- [Laravel 13.38.0 release](https://github.com/laravel/framework/releases/tag/v13.38.0)
- [Laravel 11 upgrade guide](https://github.com/laravel/docs/blob/11.x/upgrade.md)
- [Laravel 12 upgrade guide](https://github.com/laravel/docs/blob/12.x/upgrade.md)
- [Laravel 13 upgrade guide](https://github.com/laravel/docs/blob/13.x/upgrade.md)
