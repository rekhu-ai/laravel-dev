<?php

namespace Rekhu\LaravelDev\Console;

use Illuminate\Console\Command;
use Rekhu\LaravelDev\Rekhu;
use Rekhu\LaravelDev\Support\Platform;
use Throwable;

/** php artisan rekhu:status — the connection as the app sees it, and a fresh report of its tools. */
class StatusCommand extends Command
{
    protected $signature = 'rekhu:status';

    protected $description = 'Show how this app is connected to Rekhu';

    public function handle(): int
    {
        $key = Rekhu::key();
        $this->table(['', ''], [
            ['Package', 'rekhu/laravel-dev '.Rekhu::VERSION],
            ['Laravel / PHP', app()->version().' / '.PHP_VERSION],
            ['Key', $key ? $key['customer'].' · '.$key['agent'] : 'missing (php artisan rekhu:install)'],
            ['Platform', config('rekhu.platform')],
            ['Environment', Rekhu::environment()],
            ['Connection', Rekhu::mode().(Rekhu::mode() === 'relay' ? ' (php artisan rekhu:listen)' : ' ('.Rekhu::urls()['rest'].')')],
            ['Writes', Rekhu::writes()],
            ['Git', Rekhu::git() ? 'yes' : 'no'],
        ]);
        if (! $key) {
            return self::FAILURE;
        }
        try {
            $me = Platform::me();
            $stored = Platform::report();
            $this->info('Connected to '.($me['customer']['name'] ?? 'the account').'. '.($stored['stored'] ?? 0).' tools reported.'.(! empty($me['project']['name']) ? ' Project: '.$me['project']['name'].'.' : ''));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('The platform could not be reached or refused the key: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
