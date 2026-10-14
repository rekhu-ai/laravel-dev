<?php

namespace Rekhu\LaravelDev\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Rekhu\LaravelDev\Rekhu;
use RuntimeException;

/**
 * Where the agent's changes go and how they are undone. Every run that writes gets a record in
 * storage/rekhu/runs/<run>.json and a backup of each file before its first change, so rollback can put
 * everything back. With write mode "branch" the changes also go to a git branch rekhu/<run> and are committed
 * there, only the files the agent changed, never the developer's own uncommitted work.
 */
class Workspace
{
    public static function runId(?string $run): string
    {
        $run = preg_replace('/[^A-Za-z0-9-]/', '', (string) $run);

        return $run !== '' ? substr($run, 0, 36) : 'manual-'.date('Ymd-His');
    }

    private static function dir(string $run): string
    {
        return storage_path('rekhu/runs/'.$run);
    }

    public static function record(string $run): array
    {
        $file = self::dir($run).'.json';

        return is_file($file) ? (json_decode(file_get_contents($file), true) ?: []) : ['run' => $run, 'files' => [], 'created' => [], 'migrations' => [], 'branch' => null, 'started' => now()->toIso8601String()];
    }

    private static function save(array $record): void
    {
        File::ensureDirectoryExists(storage_path('rekhu/runs'));
        // the run records and backups are the app's own working data, never part of its repository
        if (! is_file(storage_path('rekhu/.gitignore'))) {
            file_put_contents(storage_path('rekhu/.gitignore'), "*\n");
        }
        file_put_contents(self::dir($record['run']).'.json', json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /** Refuses when writing is off; switches to the run's branch in branch mode. Call before every write. */
    public static function begin(string $run): array
    {
        $mode = Rekhu::writes();
        if ($mode === 'off') {
            throw new RuntimeException('writing is off for this app ('.Rekhu::environment().'). Propose the change as a diff instead, or the developer sets REKHU_WRITES (and REKHU_PRODUCTION_WRITES on production).');
        }
        $record = self::record($run);
        if ($mode === 'branch' && ! $record['branch']) {
            $branch = 'rekhu/'.$run;
            $current = trim(Process::path(base_path())->run(['git', 'rev-parse', '--abbrev-ref', 'HEAD'])->output());
            $exists = Process::path(base_path())->run(['git', 'rev-parse', '--verify', '--quiet', 'refs/heads/'.$branch])->successful();
            $result = Process::path(base_path())->run($exists ? ['git', 'checkout', $branch] : ['git', 'checkout', '-b', $branch]);
            if (! $result->successful()) {
                throw new RuntimeException('could not switch to branch '.$branch.': '.trim($result->errorOutput()));
            }
            $record['branch'] = $branch;
            $record['from'] = $current;
            self::save($record);
        }

        return $record;
    }

    /** Keeps the file as it was before the run's first change to it (or notes that it did not exist). */
    public static function backup(string $run, string $relative): void
    {
        $record = self::record($run);
        if (in_array($relative, $record['files'], true) || in_array($relative, $record['created'], true)) {
            return;
        }
        $absolute = base_path($relative);
        if (is_file($absolute)) {
            $copy = self::dir($run).'/'.$relative;
            File::ensureDirectoryExists(dirname($copy));
            copy($absolute, $copy);
            $record['files'][] = $relative;
        } else {
            $record['created'][] = $relative;
        }
        self::save($record);
    }

    /** Commits the run's changed files on its branch (branch mode only). */
    public static function commit(string $run, array $paths, string $message): ?string
    {
        $record = self::record($run);
        if (! $record['branch'] || ! $paths) {
            return null;
        }
        Process::path(base_path())->run(array_merge(['git', 'add', '--'], $paths));
        $result = Process::path(base_path())->run(['git', '-c', 'user.name=Rekhu', '-c', 'user.email=agent@rekhu.com', 'commit', '-m', mb_substr($message, 0, 200), '--', ...$paths]);

        return $result->successful() ? trim(Process::path(base_path())->run(['git', 'rev-parse', '--short', 'HEAD'])->output()) : null;
    }

    /** A file that came into being during the run (a make: command): undo removes it. */
    public static function noteCreated(string $run, string $relative): void
    {
        $record = self::record($run);
        if (! in_array($relative, $record['created'], true) && ! in_array($relative, $record['files'], true)) {
            $record['created'][] = $relative;
            self::save($record);
        }
    }

    public static function noteMigration(string $run, string $name): void
    {
        $record = self::record($run);
        $record['migrations'][] = $name;
        self::save($record);
    }

    /** Puts everything the run changed back: files restored, created files removed, its migrations rolled back, the branch left. */
    public static function rollback(string $run): array
    {
        $record = self::record($run);
        if (! $record['files'] && ! $record['created'] && ! $record['branch']) {
            throw new RuntimeException("there is nothing to undo for run $run");
        }
        $done = ['restored' => [], 'removed' => [], 'migrations' => null, 'branch' => null];
        if ($record['migrations']) {
            $result = Process::path(base_path())->timeout(300)->env(Shell::env())->run([PHP_BINARY, 'artisan', 'migrate:rollback', '--step='.count($record['migrations']), '--force', '--no-interaction']);
            $done['migrations'] = trim($result->output()."\n".$result->errorOutput());
        }
        if ($record['branch'] && ! empty($record['from'])) {
            // the run's changes are commits on its own branch: going back to the branch it started from undoes them all
            $result = Process::path(base_path())->run(['git', 'checkout', $record['from']]);
            if (! $result->successful()) {
                throw new RuntimeException('could not go back to '.$record['from'].': '.trim($result->errorOutput()));
            }
            $done['branch'] = 'back on '.$record['from'].'; branch '.$record['branch'].' is kept for reference (git branch -D '.$record['branch'].' removes it)';
        } else {
            foreach ($record['files'] as $relative) {
                $copy = self::dir($run).'/'.$relative;
                if (is_file($copy)) {
                    copy($copy, base_path($relative));
                    $done['restored'][] = $relative;
                }
            }
            foreach ($record['created'] as $relative) {
                if (is_file(base_path($relative))) {
                    unlink(base_path($relative));
                    $done['removed'][] = $relative;
                }
            }
        }
        $record['rolled_back'] = now()->toIso8601String();
        self::save($record);

        return $done;
    }
}
