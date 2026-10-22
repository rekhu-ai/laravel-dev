<?php

namespace Rekhu\LaravelDev\Tools;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Rekhu\LaravelDev\Rekhu;
use Rekhu\LaravelDev\Support\Guard;
use Rekhu\LaravelDev\Support\Shell;
use Rekhu\LaravelDev\Support\Workspace;
use RuntimeException;

/** Commands in the app: git, artisan, migrations, tests and composer, each within its limits. */
class RunTools
{
    private const READ_ONLY_ARTISAN = ['about', 'route:list', 'schedule:list', 'migrate:status', 'model:show', 'db:show', 'db:table', 'event:list', 'config:show', 'list', 'env', 'queue:failed', 'queue:monitor', 'channel:list', 'package:discover --dry-run'];

    private const NEVER = ['migrate:fresh', 'migrate:reset', 'migrate:refresh', 'db:wipe', 'db:seed --force', 'key:generate', 'down', 'tinker', 'serve', 'rekhu:', 'env:decrypt', 'env:encrypt', 'schedule:work', 'queue:work', 'queue:listen', 'queue:flush', 'queue:clear', 'horizon', 'octane'];

    private static function artisanCall(array $args, int $timeout = 120, bool $flags = true)
    {
        // php artisan test hands every option to PHPUnit or Pest, which do not know --no-interaction
        return Process::path(base_path())->timeout($timeout)->env(Shell::env())->run(array_merge([PHP_BINARY, 'artisan'], $args, $flags ? ['--no-interaction', '--no-ansi'] : []));
    }

    public static function git(array $input = []): array
    {
        if (! Rekhu::git()) {
            return ['git' => 'this app is not a git working copy (or git is not installed here)'];
        }
        $action = $input['action'] ?? 'status';
        $path = isset($input['path']) && $input['path'] !== '' ? Guard::path((string) $input['path'], false) : null;
        $args = match ($action) {
            'diff' => array_filter(['git', 'diff', 'HEAD', '--stat', '--patch', '--', $path ? substr($path, strlen(base_path()) + 1) : null]),
            'log' => ['git', 'log', '-20', '--format=%h %ad %an: %s', '--date=short'],
            'branches' => ['git', 'branch', '--list', '--sort=-committerdate', '--format=%(refname:short) %(committerdate:relative)'],
            default => ['git', 'status', '--short', '--branch'],
        };
        $result = Process::path(base_path())->timeout(30)->run(array_values($args));

        return ['action' => $action, 'output' => Guard::clip(trim($result->output() ?: $result->errorOutput()), 200000)];
    }

    public static function artisan(array $input, array $meta): array
    {
        $command = trim((string) ($input['command'] ?? ''));
        $command = preg_replace('/^(php\s+)?artisan\s+/', '', $command);
        if ($command === '' || preg_match('/[;&|`$<>]/', $command)) {
            throw new RuntimeException('one artisan command, without shell characters');
        }
        foreach (self::NEVER as $no) {
            if (str_starts_with($command, $no)) {
                throw new RuntimeException("artisan $no is never run by the agent");
            }
        }
        $name = strtok($command, ' ');
        $readOnly = in_array($name, array_map(fn ($c) => strtok($c, ' '), self::READ_ONLY_ARTISAN), true);
        $make = str_starts_with($name, 'make:');
        $allowed = in_array($name, (array) config('rekhu.artisan', []), true);
        if (! $readOnly && ! $make && ! $allowed) {
            throw new RuntimeException("artisan $name is not on the list the agent may run; the developer can add it to config rekhu.artisan");
        }
        if (! $readOnly) {
            Workspace::begin($meta['run']);
            if (Rekhu::environment() !== 'local' && ($input['confirmed'] ?? false) !== true) {
                return ['confirmation_needed' => "artisan $command changes the ".Rekhu::environment().' app. Tell the developer, wait for a yes, then call again with confirmed true.'];
            }
        }
        $before = $make ? self::changedFiles() : [];
        $result = self::artisanCall(str_getcsv($command, ' ', '"', '\\'), 300);
        $out = ['command' => 'php artisan '.$command, 'exit' => $result->exitCode(), 'output' => Guard::clip(trim($result->output()."\n".$result->errorOutput()), 100000)];
        if ($make && $result->successful()) {
            // a make: command creates files: they become part of the run, so undo removes them and the branch carries them
            $created = array_values(array_diff(self::changedFiles(), $before));
            foreach ($created as $file) {
                Workspace::noteCreated($meta['run'], $file);
            }
            $out['created'] = $created;
            $out['commit'] = Workspace::commit($meta['run'], $created, 'php artisan '.$command);
        }

        return $out;
    }

    /** New and changed files in the writable folders, for noticing what a make: command created. */
    private static function changedFiles(): array
    {
        if (Rekhu::git()) {
            $lines = explode("\n", trim(Process::path(base_path())->run(['git', 'status', '--porcelain', '--untracked-files=all'])->output()));

            return array_values(array_filter(array_map(fn ($l) => trim(substr($l, 3)), $lines)));
        }
        $files = [];
        foreach ((array) config('rekhu.writable', []) as $root) {
            if (is_dir(base_path($root))) {
                foreach (File::allFiles(base_path($root)) as $f) {
                    if ($f->getMTime() > time() - 600) {
                        $files[] = ltrim(substr($f->getPathname(), strlen(base_path())), '/');
                    }
                }
            }
        }

        return $files;
    }

    public static function migrate(array $input, array $meta): array
    {
        $pretend = $input['pretend'] ?? true;
        $status = self::artisanCall(['migrate:status'], 60);
        $pending = array_values(array_filter(array_map('trim', explode("\n", $status->output())), fn ($l) => str_contains($l, 'Pending')));
        if (! $pending) {
            return ['pending' => [], 'note' => 'there are no pending migrations'];
        }
        if ($pretend) {
            $result = self::artisanCall(['migrate', '--pretend', '--force'], 120);

            return ['pending' => $pending, 'sql' => Guard::clip(trim($result->output()), 60000), 'note' => 'nothing ran; call again with pretend false to run them'];
        }
        Workspace::begin($meta['run']);
        $result = self::artisanCall(['migrate', '--force'], 600);
        if ($result->successful()) {
            foreach ($pending as $line) {
                Workspace::noteMigration($meta['run'], preg_replace('/\s.*$/', '', $line));
            }
        }

        return ['ran' => $pending, 'exit' => $result->exitCode(), 'output' => Guard::clip(trim($result->output()."\n".$result->errorOutput()), 60000), 'run' => $meta['run']];
    }

    public static function tests(array $input = []): array
    {
        if (! is_dir(base_path('tests'))) {
            return ['tests' => 'this app has no tests folder'];
        }
        $args = ['test', '--compact'];
        if (! empty($input['filter'])) {
            $args[] = '--filter='.preg_replace('/[^\w\\\\:. -]/', '', (string) $input['filter']);
        }
        $result = self::artisanCall($args, 600, false);
        $output = trim($result->output()."\n".$result->errorOutput());

        return ['passed' => $result->successful(), 'exit' => $result->exitCode(), 'output' => Guard::clip($output, 80000)];
    }

    public static function composer(array $input, array $meta): array
    {
        $action = $input['action'] ?? '';
        $package = (string) ($input['package'] ?? '');
        if (! preg_match('#^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]?|-{0,2})[a-z0-9]+)*$#', $package)) {
            throw new RuntimeException('a package name like vendor/package is needed');
        }
        if (str_starts_with($package, 'rekhu/')) {
            throw new RuntimeException('the rekhu packages are updated by the developer, not by the agent');
        }
        $binary = trim(Process::run(['sh', '-c', 'command -v composer'])->output());
        if ($binary === '') {
            throw new RuntimeException('composer is not installed on this machine');
        }
        Workspace::begin($meta['run']);
        Workspace::backup($meta['run'], 'composer.json');
        Workspace::backup($meta['run'], 'composer.lock');
        $spec = $package.(! empty($input['version']) ? ':'.preg_replace('/[^\w.*^~|<>=@ -]/', '', (string) $input['version']) : '');
        $args = match ($action) {
            'require' => array_filter([$binary, 'require', $spec, ! empty($input['dev']) ? '--dev' : null, '--no-interaction', '--no-progress']),
            'update' => [$binary, 'update', $package, '--with-dependencies', '--no-interaction', '--no-progress'],
            'remove' => [$binary, 'remove', $package, '--no-interaction', '--no-progress'],
            default => throw new RuntimeException('action is require, update or remove'),
        };
        $result = Process::path(base_path())->timeout(600)->env(Shell::env())->run(array_values($args));
        $commit = $result->successful() ? Workspace::commit($meta['run'], ['composer.json', 'composer.lock'], "composer $action $spec") : null;

        return array_filter(['exit' => $result->exitCode(), 'output' => Guard::clip(trim($result->output()."\n".$result->errorOutput()), 40000), 'commit' => $commit, 'run' => $meta['run']], fn ($v) => $v !== null);
    }

    public static function rollback(array $input, array $meta): array
    {
        $run = Workspace::runId($input['run'] ?? $meta['run']);

        return ['run' => $run] + Workspace::rollback($run);
    }
}
