# Rekhu Builder for Laravel

The agent that knows your running Laravel app. Install this package, add the key from your
[Rekhu](https://rekhu.com) account, and the agent there reads the app as it runs, fixes what breaks on a
git branch with a test, and keeps an eye on the live site.

- Reads: routes, models, the database schema, config (secrets masked), logs, failed jobs, the scheduler, packages and security advisories.
- Changes, when you allow it: code on a git branch of its own, migrations after a preview, tests, packages. Undo per run.
- Works on a laptop too: `php artisan rekhu:listen` connects over an outgoing connection, nothing to open inbound.

## Requirements

- Laravel 10.48 or newer (10, 11, 12 and 13) and PHP 8.2 or newer, tested on PHP 8.2 to 8.5.
- No migrations: the package keeps no tables of its own.
- Laravel 10 and 11 no longer get security fixes and every release of them has open advisories, so a current Composer
  refuses to install them unless the app allows it; upgrading the app is the real fix.

## Install

In your Rekhu account open the project of this app, go to the Connection tab and press **Make a key** in the
Rekhu Builder card. Then, in the app's folder:

```bash
composer require rekhu/laravel-dev
php artisan rekhu:install --key=…  # the builder key of the project; it is shown once
php artisan rekhu:status
```

The builder key is not the assistant's key: the assistant inside your app (`rekhu/assistant-laravel`) has keys of
its own, one per environment, in the Rekhu Assistant card.

On a laptop, in Docker or behind a firewall keep the listener running:

```bash
php artisan rekhu:listen           # on Laravel 13, composer run dev starts it for you
```

## Safe by default

- Local: writes go to a git branch `rekhu/<run>`, one commit per change.
- Production: read only until you set `REKHU_PRODUCTION_WRITES=true`.
- Staging and production: every destructive step waits for your yes in the chat, and so does undoing a run.
- Database queries are reads only: one statement, no writes, no server files or functions (words inside quoted text
  do not count); the database refuses writes
  in the query's transaction where it can (PostgreSQL, MySQL, MariaDB, SQLite).
- Never on production: the test suite (it can reset the database it reaches) and `artisan config:show` anywhere (it
  prints secrets; the agent reads config with secrets masked).
- Never reads `.env`, never changes its own settings (`config/rekhu.php`), never writes in `vendor/` or `storage/`, never runs `migrate:fresh`, `db:wipe` or `key:generate`.
- `php artisan rekhu:rollback` lists the runs and undoes one. A run's migrations are rolled back only while they are
  still the latest ones; when others ran after them, it says so and changes nothing.

## Configuration

`php artisan vendor:publish --tag=rekhu-config` publishes `config/rekhu.php`: the folders the agent may write in,
the files it never touches and the extra artisan commands it may run. These are set in `.env`:

| Variable | What it does |
|---|---|
| `REKHU_KEY` | The project's builder key; written by `rekhu:install`. |
| `REKHU_SECRET` | The secret the platform signs its calls with; made by `rekhu:install`. |
| `REKHU_MODE` | `auto` (default), `direct` (the platform calls `https://your-app/rekhu/run`) or `relay` (`rekhu:listen`). |
| `REKHU_WRITES` | `off`, `branch` (default where the app has git) or `on`. |
| `REKHU_PRODUCTION_WRITES` | `true` lets the agent write on production; off by default. |
| `REKHU_PATH` | The path of the direct route, `rekhu` by default. |
| `REKHU_PLATFORM` | The platform's address; only for a test platform. |

## Commands

| Command | What it does |
|---|---|
| `rekhu:install --key=…` | Saves the key, makes the secret and connects the app. |
| `rekhu:status` | The key, the connection, the write mode and whether the platform reaches the app. |
| `rekhu:listen` | Keeps the outgoing connection open on a laptop, in Docker or behind a firewall. |
| `rekhu:report` | Reports the app's tools to Rekhu again, after an update of the package. |
| `rekhu:rollback {run?}` | Lists the runs, or undoes one. |

## Changes

See [CHANGELOG.md](CHANGELOG.md).

## Licence

Licensed, not sold: see LICENSE.txt.
Questions: hello@rekhu.com
