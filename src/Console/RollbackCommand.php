<?php

namespace Rekhu\LaravelDev\Console;

use Illuminate\Console\Command;
use Rekhu\LaravelDev\Support\Workspace;

/** php artisan rekhu:rollback {run} — undoes everything one agent run changed in this app. */
class RollbackCommand extends Command
{
    protected $signature = 'rekhu:rollback {run? : The run id; empty lists the runs}';

    protected $description = 'Undo what a Rekhu agent run changed in this app';

    public function handle(): int
    {
        $run = $this->argument('run');
        if (! $run) {
            $rows = [];
            foreach (glob(storage_path('rekhu/runs/*.json')) ?: [] as $file) {
                $r = json_decode(file_get_contents($file), true) ?: [];
                $rows[] = [$r['run'] ?? basename($file, '.json'), $r['started'] ?? '', count($r['files'] ?? []) + count($r['created'] ?? []), $r['branch'] ?? '', isset($r['rolled_back']) ? 'undone' : ''];
            }
            $this->table(['Run', 'Started', 'Files', 'Branch', ''], $rows);

            return self::SUCCESS;
        }
        $done = Workspace::rollback(Workspace::runId($run));
        $this->info('Undone: '.json_encode($done, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
