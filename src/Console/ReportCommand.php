<?php

namespace Rekhu\LaravelDev\Console;

use Illuminate\Console\Command;
use Rekhu\LaravelDev\Support\Platform;

/** php artisan rekhu:report — tells the platform again what this app can do (after an update or a config change). */
class ReportCommand extends Command
{
    protected $signature = 'rekhu:report';

    protected $description = 'Report this app\'s tools to Rekhu again';

    public function handle(): int
    {
        $stored = Platform::report();
        $this->info(($stored['stored'] ?? 0).' tools reported.');

        return self::SUCCESS;
    }
}
