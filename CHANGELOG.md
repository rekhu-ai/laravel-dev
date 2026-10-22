# Changelog

All notable changes to `rekhu/laravel-dev`. Versions follow [semantic versioning](https://semver.org).

## 1.1.0 · 22 October 2026

Laravel 10 support, and safety fixes from a review of 1.0.0. Update when you use 1.0.0.

- **Database reads stay reads.** Queries that could read or write files or reach other servers got past the read-only
  check: on MySQL `SELECT … INTO`, `INTO DUMPFILE` and `LOAD_FILE`; on PostgreSQL `pg_read_file`, `dblink`,
  `pg_terminate_backend` and `setval`; on SQLite `ATTACH` and `PRAGMA`. They are refused now, and the database itself
  refuses writes in the query's transaction (`SET TRANSACTION READ ONLY` on MySQL and MariaDB, `PRAGMA query_only` on
  SQLite; PostgreSQL already did). Words inside quoted text no longer count, so `WHERE note LIKE '%copy%'` passes.
- **No tests on production.** `tests-run` could reach `migrate:fresh` through `RefreshDatabase` on a live database; it
  is refused on production.
- **Undoing a run follows the write rules.** `rollback` is refused where writing is off and waits for the developer's
  yes outside local. A run's migrations are rolled back only while they are still the latest, so a later deploy's
  migrations are never undone.
- **Secrets.** `artisan config:show`, which prints secrets unmasked, is no longer offered; the `config` tool shows them
  masked. The builder never changes its own settings in `config/rekhu.php`.
- **Requirements.** Requires `laravel/framework` (the package uses more of Laravel than the parts it listed) and
  `guzzlehttp/guzzle`, which Laravel 10 does not install by itself. Laravel 10.48 to 13 on PHP 8.2 or newer, tested on
  PHP 8.2 to 8.5. Laravel 10 and 11 releases have open security advisories, so Composer may refuse them unless the app
  allows it.
- The README lists every `REKHU_*` setting and command.

## 1.0.0 · 22 October 2026

The first release of Rekhu Builder for Laravel: the package that connects a Laravel app to Rekhu Builder in a Rekhu
account, so the builder can read the running app, fix what breaks and keep an eye on the live site.

- **Connect.** `php artisan rekhu:install --key=…` checks the project's builder key with the platform, writes
  `REKHU_KEY` and a signing secret of its own to `.env`, and reports the app's tools. `php artisan rekhu:status` shows
  the connection, the environment and the write mode, and reports the tools again; `php artisan rekhu:report` only
  reports them.
- **Two ways in.** On a public HTTPS address the platform calls `POST /rekhu/run` directly. Every call is signed with
  the app's secret over the time, method, route and body, is refused outside a five-minute window, and is refused when
  the same signature comes twice. On a laptop, in Docker or behind a firewall, `php artisan rekhu:listen` keeps one
  outgoing connection to the platform and runs the calls through it; on Laravel 13 `composer run dev` starts it, and on
  Laravel 11 and 12 `rekhu:install` adds it to the `dev` script.
- **What the builder reads.** The app's versions, drivers and conventions; routes with their form requests; models
  with their relations; the database schema and read-only queries; config with secrets masked; logs, failed jobs and
  the scheduler; Composer packages with security advisories; git status, diffs and history; the code itself.
- **What it may change.** Files in the writable folders (`app`, `config`, `database`, `resources`, `routes`, `tests` and
  more, set in `config/rekhu.php`), artisan's `make:*` and the commands you allow, migrations after a preview, Composer
  packages, and the test suite. Files from the Rekhu account (templates, images, notes) can be brought into the app.
- **A branch per run.** Where the app is a git working copy, the builder's changes go to a branch `rekhu/<run>`, one
  commit per change and only the files it changed. Every file is backed up before its first change, and
  `php artisan rekhu:rollback` lists the runs and undoes one: files restored, new files removed, its migrations rolled
  back, back on the branch it started from.
- **Safe by default.** `.env`, keys and `.git` are never read; `vendor`, `storage` and `node_modules` are never written;
  `migrate:fresh`, `db:wipe`, `key:generate` and `down` never run. Production is read only unless
  `REKHU_PRODUCTION_WRITES=true`, and on staging and production every destructive step waits for the developer's yes
  in the chat.
- Laravel 11, 12 and 13 on PHP 8.2 or newer.
