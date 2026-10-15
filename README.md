# Rekhu Builder for Laravel

The agent that knows your running Laravel app. Install this package, add the key from your
[Rekhu](https://rekhu.com) account, and the agent there reads the app as it runs, fixes what breaks on a
git branch with a test, and keeps an eye on the live site.

- Reads: routes, models, the database schema, config (secrets masked), logs, failed jobs, the scheduler, packages and security advisories.
- Changes, when you allow it: code on a git branch of its own, migrations after a preview, tests, packages. Undo per run.
- Works on a laptop too: `php artisan rekhu:listen` connects over an outgoing connection, nothing to open inbound.

Laravel 11, 12 or 13, PHP 8.2 or newer.

## Install

```bash
composer require rekhu/laravel-dev
php artisan rekhu:install          # asks for the key from Projects → New project → Laravel app (Connection tab)
php artisan rekhu:status
```

On a laptop, in Docker or behind a firewall keep the listener running:

```bash
php artisan rekhu:listen           # on Laravel 13, composer run dev starts it for you
```

## Safe by default

- Local: writes go to a git branch `rekhu/<run>`, one commit per change.
- Production: read only until you set `REKHU_PRODUCTION_WRITES=true`.
- Staging and production: every destructive step waits for your yes in the chat.
- Never reads `.env`, never writes in `vendor/` or `storage/`, never runs `migrate:fresh`, `db:wipe` or `key:generate`.
- `php artisan rekhu:rollback` lists the runs and undoes one.

## Configuration

`php artisan vendor:publish --tag=rekhu-config` publishes `config/rekhu.php`: the folders the agent may write in,
extra artisan commands it may run, the connection mode and the write mode. Every value can also be set in `.env`.

## Licence

Licensed, not sold: see LICENSE.txt. Rekhu is a product and trade name of Charisma Design, the Netherlands.
Questions: hello@rekhu.com
