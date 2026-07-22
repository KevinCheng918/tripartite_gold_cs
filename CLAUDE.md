# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this project is

`tripartite_gold_cs` is the **technical customer-service system** for the `tripartite_gold`
three-party payment platform (a sibling project). It is currently a stock Laravel skeleton —
`app/`, `routes/`, and `database/migrations` still hold only the framework defaults (default
`User` model, default auth scaffolding, `users`/`password_resets`/`failed_jobs`/`personal_access_tokens`
migrations, one welcome route). No project-specific domain code, controllers, or seeders exist yet.

**Before implementing anything**, read `PROMPTS.md` at the repo root — it defines the mandatory
per-task workflow (style rules, self-check list, docker post-commands) and points to the project
knowledge base at `.claude/knowledge/INDEX.md`. That knowledge base tracks:
- `domain/` — background knowledge about the main platform (e.g. the L1–L4 deposit/withdrawal
  flow) that customer-service work depends on
- `features/` — specs/status for the six planned core features: account RBAC, shift scheduling
  with approval, a Telegram-integrated support chat window, attendance clock-in visible to
  managers, and an in-app changelog panel (bottom-left) that notifies CS staff of feature
  changes — all currently unimplemented, see each file's "待釐清" (open questions) section
- `bugfix/` — per-fix notes

**Update the knowledge base after every task** (new/changed file list in `features/` or
`bugfix/`, plus the `INDEX.md` index row) — this is enforced by `PROMPTS.md` step 6, not optional.

The root `README.md` describes the setup flow (in Chinese). Its documented setup sequence is:

```
composer install
npm install
npm run dev
cp .env.example .env
php artisan migrate:install
php artisan migrate
php artisan db:seed --class=CreateAdminSeeder
php artisan db:seed --class=SetPermissionSeeder
php artisan db:seed --class=CategorySeeder
```

Note: `CreateAdminSeeder`, `SetPermissionSeeder`, and `CategorySeeder` don't exist yet in
`database/seeders/` (only the default `DatabaseSeeder` is present) — they are expected to be
added as the admin/permission/category features are built out. When implementing those features,
follow this naming convention for the seeder classes.

The README's version header states PHP 7.4, Laravel v8.83.29 (matches `composer.lock`), and
Node 10.16.* — the install SOP's numbered steps have been aligned to match (PHP 7.3.*, Node 10.16.*).

## Common commands

```bash
# Install dependencies
composer install
npm install

# Environment setup (first time)
cp .env.example .env
php artisan key:generate

# Database
php artisan migrate
php artisan migrate:fresh --seed   # drop all tables, re-migrate, and seed
php artisan db:seed --class=SomeSeeder

# Run the app
php artisan serve
npm run dev          # compile frontend assets (dev)
npm run watch         # recompile on change
npm run prod          # compile frontend assets (production, minified)

# Tests (PHPUnit, via phpunit.xml — Unit and Feature suites under tests/)
php artisan test
php artisan test --testsuite=Unit
php artisan test --testsuite=Feature
php artisan test --filter=ExampleTest        # single test class
php artisan test tests/Feature/ExampleTest.php::testExample  # single test method
vendor/bin/phpunit                            # equivalent, invoked directly
```

### Docker (laradock)

`PROMPTS.md` documents this project as running inside the sibling `../laradock` Docker setup
(container path `/var/www/tripartite_gold_cs`). Certain changes require a post-command run
inside that container rather than locally:

```bash
# after changing anything under routes/
cd ../laradock && docker-compose exec workspace bash -c "cd /var/www/tripartite_gold_cs && php artisan optimize"

# after changing permissions
cd ../laradock && docker-compose exec workspace bash -c "cd /var/www/tripartite_gold_cs && php artisan db:seed --class=SetPermissionSeeder"
```

Test environment (`phpunit.xml`) overrides: `APP_ENV=testing`, `CACHE_DRIVER=array`,
`SESSION_DRIVER=array`, `QUEUE_CONNECTION=sync`, `MAIL_MAILER=array`, `BCRYPT_ROUNDS=4`. The
DB connection for tests is *not* overridden to sqlite in-memory (those lines are commented out
in `phpunit.xml`) — tests run against whatever `DB_CONNECTION`/`DB_DATABASE` is set in `.env`
unless you uncomment/override that.

## Code style

Code style follows the `laravel` preset via StyleCI (`.styleci.yml`), with the
`no_unused_imports` rule disabled, and excludes `index.php`/`server.php` (PHP) and
`webpack.mix.js` (JS) from linting.

## Stack notes

- Laravel 8 (`laravel/framework ^8.75`), PHP 7.3+/8.0.
- `laravel/sanctum` is installed for API token auth — `routes/api.php` already has a
  `auth:sanctum`-protected `/user` route as the framework default.
- `fruitcake/laravel-cors` is installed for CORS handling.
- Frontend assets are built with Laravel Mix (`webpack.mix.js`): compiles
  `resources/js/app.js` → `public/js` and `resources/css/app.css` → `public/css`.
- Standard Laravel config-driven service selection via `.env`: DB defaults to `mysql`,
  cache/session/queue drivers are file/sync by default, Redis is available and configured
  via `REDIS_HOST`/`REDIS_PORT`/`REDIS_PASSWORD`.
