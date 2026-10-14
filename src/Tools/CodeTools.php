<?php

namespace Rekhu\LaravelDev\Tools;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Rekhu\LaravelDev\Support\Guard;
use Rekhu\LaravelDev\Support\Workspace;
use RuntimeException;

/** The app's code: list, read and search anywhere but the private files; write only in the writable folders, with a backup. */
class CodeTools
{
    private const SKIP = ['vendor', 'node_modules', '.git', 'storage', 'public/build', 'bootstrap/cache'];

    public static function list(array $input = []): array
    {
        $path = trim((string) ($input['path'] ?? ''), '/');
        $depth = max(1, min(5, (int) ($input['depth'] ?? 2)));
        $root = $path === '' ? base_path() : Guard::path($path);
        if (! is_dir($root)) {
            throw new RuntimeException("$path is a file; use code-read");
        }
        $out = [];
        $walk = function (string $dir, int $level) use (&$walk, &$out, $depth) {
            foreach (scandir($dir) ?: [] as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $full = $dir.'/'.$name;
                $relative = ltrim(substr($full, strlen(base_path())), '/');
                if (Guard::hidden($relative) || in_array($relative, self::SKIP, true)) {
                    continue;
                }
                $out[] = is_dir($full) ? $relative.'/' : $relative.' ('.number_format(filesize($full)).' B)';
                if (is_dir($full) && $level < $depth && count($out) < 1500) {
                    $walk($full, $level + 1);
                }
            }
        };
        $walk($root, 1);

        return ['path' => $path ?: '.', 'entries' => array_slice($out, 0, 1500)];
    }

    public static function read(array $input = []): array
    {
        $relative = (string) ($input['path'] ?? '');
        $file = Guard::path($relative);
        if (! is_file($file)) {
            throw new RuntimeException("$relative is a folder; use code-list");
        }
        if (filesize($file) > 2_000_000) {
            throw new RuntimeException("$relative is larger than 2 MB; read part of it with from and to, or search in it");
        }
        $lines = explode("\n", (string) file_get_contents($file));
        $from = max(1, (int) ($input['from'] ?? 1));
        $to = min(count($lines), (int) ($input['to'] ?? min(count($lines), $from + 799)));
        $numbered = [];
        for ($n = $from; $n <= $to; $n++) {
            $numbered[] = str_pad((string) $n, 5, ' ', STR_PAD_LEFT).'  '.$lines[$n - 1];
        }

        return ['path' => ltrim($relative, '/'), 'lines' => count($lines), 'from' => $from, 'to' => $to, 'text' => Guard::clip(implode("\n", $numbered), 300000)];
    }

    public static function search(array $input = []): array
    {
        $pattern = (string) ($input['pattern'] ?? '');
        if ($pattern === '' || @preg_match('~'.str_replace('~', '\~', $pattern).'~', '') === false) {
            throw new RuntimeException('a valid regular expression is needed as pattern');
        }
        $limit = max(1, min(300, (int) ($input['limit'] ?? 100)));
        $roots = ! empty($input['path']) ? [trim((string) $input['path'], '/')] : array_filter(['app', 'routes', 'config', 'database', 'resources', 'tests', 'bootstrap/app.php'], fn ($r) => file_exists(base_path($r)));
        $hits = [];
        foreach ($roots as $root) {
            $start = Guard::path($root);
            $files = is_file($start) ? [new \SplFileInfo($start)] : File::allFiles($start);
            foreach ($files as $file) {
                $relative = ltrim(substr($file->getPathname(), strlen(base_path())), '/');
                if (Guard::hidden($relative) || $file->getSize() > 1_000_000) {
                    continue;
                }
                foreach (explode("\n", (string) file_get_contents($file->getPathname())) as $n => $line) {
                    if (preg_match('~'.str_replace('~', '\~', $pattern).'~', $line)) {
                        $hits[] = $relative.':'.($n + 1).': '.mb_substr(trim($line), 0, 300);
                        if (count($hits) >= $limit) {
                            return ['hits' => $hits, 'more' => true];
                        }
                    }
                }
            }
        }

        return ['hits' => $hits, 'more' => false];
    }

    public static function write(array $input, array $meta): array
    {
        $relative = ltrim((string) ($input['path'] ?? ''), '/');
        $file = Guard::writable($relative);
        $content = (string) ($input['content'] ?? '');
        $record = Workspace::begin($meta['run']);
        Workspace::backup($meta['run'], $relative);
        $existed = is_file($file);
        File::ensureDirectoryExists(dirname($file));
        file_put_contents($file, $content);
        $check = self::lint($file);
        $commit = Workspace::commit($meta['run'], [$relative], (string) ($input['message'] ?? (($existed ? 'Change ' : 'Add ').$relative)));

        return array_filter(['path' => $relative, 'written' => strlen($content).' bytes', 'new' => ! $existed, 'syntax' => $check, 'branch' => Workspace::record($meta['run'])['branch'], 'commit' => $commit, 'run' => $meta['run']], fn ($v) => $v !== null);
    }

    public static function patch(array $input, array $meta): array
    {
        $relative = ltrim((string) ($input['path'] ?? ''), '/');
        $file = Guard::writable($relative);
        if (! is_file($file)) {
            throw new RuntimeException("$relative does not exist; use code-write to create it");
        }
        $find = (string) ($input['find'] ?? '');
        $replace = (string) ($input['replace'] ?? '');
        $text = (string) file_get_contents($file);
        $count = $find === '' ? 0 : substr_count($text, $find);
        if ($count === 0) {
            throw new RuntimeException('the text to find does not occur in '.$relative.' (read the file again: it must match exactly, spaces included)');
        }
        if ($count > 1 && empty($input['all'])) {
            throw new RuntimeException("the text to find occurs $count times in $relative; add more surrounding lines so it is unique, or set all");
        }
        Workspace::begin($meta['run']);
        Workspace::backup($meta['run'], $relative);
        file_put_contents($file, str_replace($find, $replace, $text));
        $check = self::lint($file);
        $commit = Workspace::commit($meta['run'], [$relative], (string) ($input['message'] ?? 'Change '.$relative));

        return array_filter(['path' => $relative, 'replaced' => empty($input['all']) ? 1 : $count, 'syntax' => $check, 'branch' => Workspace::record($meta['run'])['branch'], 'commit' => $commit, 'run' => $meta['run']], fn ($v) => $v !== null);
    }

    public static function delete(array $input, array $meta): array
    {
        $relative = ltrim((string) ($input['path'] ?? ''), '/');
        $file = Guard::writable($relative);
        if (! is_file($file)) {
            throw new RuntimeException("$relative does not exist");
        }
        Workspace::begin($meta['run']);
        Workspace::backup($meta['run'], $relative);
        unlink($file);
        $commit = Workspace::commit($meta['run'], [$relative], 'Remove '.$relative);

        return array_filter(['deleted' => $relative, 'commit' => $commit, 'run' => $meta['run']], fn ($v) => $v !== null);
    }

    /** php -l on a PHP file: "ok" or the parse error, so a broken file is caught before anything loads it. */
    private static function lint(string $file): ?string
    {
        if (! str_ends_with($file, '.php')) {
            return null;
        }
        $result = Process::timeout(30)->run([PHP_BINARY, '-l', $file]);

        return $result->successful() ? 'ok' : trim(str_replace(base_path().'/', '', $result->output()."\n".$result->errorOutput()));
    }
}
