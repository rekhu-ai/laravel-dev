<?php

namespace Rekhu\LaravelDev\Tools;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Rekhu\LaravelDev\Support\Guard;
use RuntimeException;
use Throwable;

/** What the running app says about itself: its logs, its failed jobs, its scheduler. All read only. */
class LogTools
{
    private const READ_BYTES = 4_000_000;   // the end of a log file that is read: recent enough, never the whole file

    private static function files(): array
    {
        $files = glob(storage_path('logs/*.log')) ?: [];
        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));

        return $files;
    }

    private static function file(?string $name): string
    {
        $files = self::files();
        if (! $files) {
            throw new RuntimeException('there are no log files in storage/logs');
        }
        if (! $name) {
            return $files[0];
        }
        foreach ($files as $f) {
            if (basename($f) === basename($name)) {
                return $f;
            }
        }
        throw new RuntimeException('no log file '.basename($name).'; there are: '.implode(', ', array_map('basename', $files)));
    }

    private static function end(string $file): string
    {
        $size = filesize($file);
        $handle = fopen($file, 'rb');
        if ($size > self::READ_BYTES) {
            fseek($handle, $size - self::READ_BYTES);
            fgets($handle);   // start at a whole line
        }
        $text = stream_get_contents($handle);
        fclose($handle);

        return (string) $text;
    }

    public static function tail(array $input = []): array
    {
        $file = self::file($input['file'] ?? null);
        $lines = max(10, min(2000, (int) ($input['lines'] ?? 200)));
        $all = explode("\n", rtrim(self::end($file)));

        return ['file' => 'storage/logs/'.basename($file), 'files' => array_map('basename', self::files()), 'text' => Guard::clip(implode("\n", array_slice($all, -$lines)), 150000)];
    }

    /** Laravel log entries start with "[date] env.LEVEL: message"; each entry carries its stack trace until the next one. */
    public static function search(array $input = []): array
    {
        $pattern = (string) ($input['pattern'] ?? '');
        $level = strtoupper(trim((string) ($input['level'] ?? '')));
        $limit = max(1, min(100, (int) ($input['limit'] ?? 20)));
        if ($pattern !== '' && @preg_match('~'.str_replace('~', '\~', $pattern).'~i', '') === false) {
            throw new RuntimeException('the pattern is not a valid regular expression');
        }
        $found = [];
        $groups = [];
        foreach (self::files() as $file) {
            $entries = preg_split('/\n(?=\[\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})/', self::end($file));
            foreach (array_reverse($entries) as $entry) {
                if (! preg_match('/^\[([^\]]+)\]\s+(\w+)\.(\w+):\s?(.*)/s', $entry, $m)) {
                    continue;
                }
                if ($level !== '' && strtoupper($m[3]) !== $level) {
                    continue;
                }
                if ($pattern !== '' && ! preg_match('~'.str_replace('~', '\~', $pattern).'~i', $entry)) {
                    continue;
                }
                $message = strtok($m[4], "\n");
                $key = mb_substr(preg_replace('/\d+/', '#', $message), 0, 160);
                $groups[$key] = ($groups[$key] ?? 0) + 1;
                if (count($found) < $limit) {
                    $found[] = ['time' => $m[1], 'level' => strtolower($m[3]), 'file' => basename($file), 'message' => mb_substr($message, 0, 600), 'trace' => mb_substr(implode("\n", array_slice(explode("\n", $m[4]), 1, 14)), 0, 2500)];
                }
            }
        }
        arsort($groups);

        return ['entries' => $found, 'most_frequent' => array_slice(array_map(fn ($k, $n) => ['message' => $k, 'count' => $n], array_keys($groups), $groups), 0, 10)];
    }

    public static function failed(array $input = []): array
    {
        $limit = max(1, min(200, (int) ($input['limit'] ?? 50)));
        $table = config('queue.failed.table', 'failed_jobs');
        $connection = config('queue.failed.database') ?: config('database.default');
        $out = ['queue_driver' => config('queue.default')];
        try {
            if (! Schema::connection($connection)->hasTable($table)) {
                $out['failed'] = 'there is no '.$table.' table (failed jobs are not stored, or the driver keeps them elsewhere)';

                return $out;
            }
            $rows = DB::connection($connection)->table($table)->orderByDesc('id')->limit(500)->get();
            $groups = [];
            foreach ($rows as $row) {
                $payload = json_decode($row->payload, true) ?: [];
                $job = $payload['displayName'] ?? ($payload['job'] ?? 'unknown');
                $exception = strtok((string) $row->exception, "\n");
                $key = $job.' | '.mb_substr(preg_replace('/\d+/', '#', $exception), 0, 200);
                if (! isset($groups[$key])) {
                    $groups[$key] = ['job' => $job, 'exception' => mb_substr($exception, 0, 500), 'count' => 0, 'newest' => $row->failed_at, 'queue' => $row->queue, 'uuid' => $row->uuid ?? null, 'trace' => mb_substr(implode("\n", array_slice(explode("\n", (string) $row->exception), 1, 10)), 0, 2000)];
                }
                $groups[$key]['count']++;
            }
            $out['failed_total'] = DB::connection($connection)->table($table)->count();
            $out['groups'] = array_slice(array_values($groups), 0, $limit);
        } catch (Throwable $e) {
            $out['failed'] = 'could not read failed jobs: '.$e->getMessage();
        }
        try {
            $out['queue_size'] = app('queue')->size();
        } catch (Throwable) {
            // not every driver knows its size
        }

        return $out;
    }

    public static function schedule(array $input = []): array
    {
        $result = Process::path(base_path())->timeout(60)->env(\Rekhu\LaravelDev\Support\Shell::env())->run([PHP_BINARY, 'artisan', 'schedule:list', '--no-ansi']);

        return ['output' => Guard::clip(trim($result->output() ?: $result->errorOutput()), 20000)];
    }
}
