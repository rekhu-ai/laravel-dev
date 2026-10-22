<?php

namespace Rekhu\LaravelDev\Tools;

use Rekhu\LaravelDev\Rekhu;
use Rekhu\LaravelDev\Support\Guard;
use Rekhu\LaravelDev\Support\Workspace;
use Throwable;

/**
 * The tools this app gives its agent, in the shape the platform hands to the model: a slug, what it does, its
 * input as JSON Schema, and whether it only reads. A destructive tool on anything but a local app needs
 * `confirmed: true`, which the agent only sends after the developer said yes.
 */
class Registry
{
    private static function obj(array $properties = [], array $required = []): array
    {
        return array_filter(['type' => 'object', 'properties' => (object) $properties, 'required' => $required ?: null], fn ($v) => $v !== null);
    }

    /** slug => [label, description, input, readonly, destructive, handler] */
    public static function tools(): array
    {
        $s = ['type' => 'string'];
        $i = ['type' => 'integer'];
        $b = ['type' => 'boolean'];
        $confirmed = ['type' => 'boolean', 'description' => 'true only after the developer agreed to this exact step'];

        return [
            'context' => ['The app', 'The app as it is now: Laravel and PHP versions, environment, debug, URL, drivers (database, cache, queue, mail, session), installed packages, git branch and state, write mode, and the conventions found in the code.', self::obj(), true, false, [AppTools::class, 'context']],
            'routes' => ['Routes', 'Every route: methods, URI, name, controller action, middleware, and the form request that validates it. "filter" narrows by part of the URI, name or action.', self::obj(['filter' => $s, 'limit' => $i]), true, false, [AppTools::class, 'routes']],
            'models' => ['Models', 'The Eloquent models: table, fillable or guarded, casts, hidden, and relations with their related model.', self::obj(['filter' => $s]), true, false, [AppTools::class, 'models']],
            'db-schema' => ['Database schema', 'Tables with their columns (type, nullable, default), indexes and foreign keys, and the row count. "table" shows one table; empty lists all.', self::obj(['table' => $s]), true, false, [DataTools::class, 'schema']],
            'db-query' => ['Read query', 'Runs one read-only SQL query (SELECT, WITH, SHOW or EXPLAIN) and returns at most "limit" rows (default 50, max 200). Never use it to change data: data changes go through a migration or seeder.', self::obj(['sql' => $s, 'bindings' => ['type' => 'array', 'items' => $s], 'limit' => $i], ['sql']), true, false, [DataTools::class, 'query']],
            'config' => ['Config', 'A config value or file ("app", "database.default", "queue"), with secrets masked. Empty lists the config files.', self::obj(['key' => $s]), true, false, [AppTools::class, 'config']],
            'packages' => ['Packages', 'The Composer packages in composer.json and composer.lock with their versions. "audit" true also runs composer audit for known security advisories; "outdated" true lists newer versions.', self::obj(['audit' => $b, 'outdated' => $b]), true, false, [AppTools::class, 'packages']],
            'logs-tail' => ['Log tail', 'The last "lines" lines (default 200) of a log file in storage/logs ("file", default the newest).', self::obj(['file' => $s, 'lines' => $i]), true, false, [LogTools::class, 'tail']],
            'logs-search' => ['Log search', 'Log entries in storage/logs matching "pattern" (a regular expression), newest first, with their stack trace start. "level" narrows to error, warning, critical…', self::obj(['pattern' => $s, 'level' => $s, 'limit' => $i]), true, false, [LogTools::class, 'search']],
            'jobs-failed' => ['Failed jobs', 'Failed queue jobs, grouped by job and exception with a count and the newest example, and the queue sizes when the driver tells them.', self::obj(['limit' => $i]), true, false, [LogTools::class, 'failed']],
            'scheduler' => ['Scheduled tasks', 'The scheduled tasks (php artisan schedule:list) with their next run.', self::obj(), true, false, [LogTools::class, 'schedule']],
            'code-list' => ['Files', 'Files and folders under "path" (default the app root), "depth" levels deep (default 2). Private files are left out.', self::obj(['path' => $s, 'depth' => $i]), true, false, [CodeTools::class, 'list']],
            'code-read' => ['Read file', 'A file\'s text with line numbers. "from" and "to" pick lines.', self::obj(['path' => $s, 'from' => $i, 'to' => $i], ['path']), true, false, [CodeTools::class, 'read']],
            'code-search' => ['Search code', 'Lines matching "pattern" (a regular expression) in the app\'s code (app, routes, config, database, resources, tests by default; "path" narrows), with file and line.', self::obj(['pattern' => $s, 'path' => $s, 'limit' => $i], ['pattern']), true, false, [CodeTools::class, 'search']],
            'code-write' => ['Write file', 'Creates or replaces a whole file with "content". Only in the writable folders. Goes to the run\'s branch (branch mode) with a backup. Prefer code-patch for a change inside an existing file.', self::obj(['path' => $s, 'content' => $s, 'message' => $s], ['path', 'content']), false, false, [CodeTools::class, 'write']],
            'code-patch' => ['Change file', 'Replaces the exact text "find" with "replace" in a file (the text must occur once, or set "all"). Same branch and backup rules as code-write.', self::obj(['path' => $s, 'find' => $s, 'replace' => $s, 'all' => $b, 'message' => $s], ['path', 'find', 'replace']), false, false, [CodeTools::class, 'patch']],
            'code-delete' => ['Delete file', 'Deletes a file in the writable folders, with a backup.', self::obj(['path' => $s, 'confirmed' => $confirmed], ['path']), false, true, [CodeTools::class, 'delete']],
            'account-files' => ['Account files', 'The files in the Rekhu account for this app: "list" shows the library and this project\'s own files (templates, notes, images); "add" brings one into the app by id or name: a template is unpacked to storage/app/rekhu/templates/<name> to read from, and its css, js, images and fonts are copied to public/templates/<name>.', self::obj(['action' => ['type' => 'string', 'enum' => ['list', 'add']], 'file' => $s]), false, false, [FileTools::class, 'files']],
            'git' => ['Git', 'The working copy: "status" (branch and changed files), "diff" (of a path or everything, against HEAD), "log" (recent commits), "branches".', self::obj(['action' => ['type' => 'string', 'enum' => ['status', 'diff', 'log', 'branches']], 'path' => $s]), true, false, [RunTools::class, 'git']],
            'artisan' => ['Artisan', 'Runs an artisan command: the read-only ones (route:list, about, schedule:list, model:show, db:show, migrate:status…), make:* to scaffold, and the ones in config rekhu.artisan. Never migrate:fresh, db:wipe, key:generate or down.', self::obj(['command' => $s, 'confirmed' => $confirmed], ['command']), false, false, [RunTools::class, 'artisan']],
            'migrate' => ['Migrate', 'Runs the pending migrations. "pretend" true (the default) shows the SQL without running it; run for real with pretend false. On staging or production it needs confirmed.', self::obj(['pretend' => $b, 'confirmed' => $confirmed]), false, true, [RunTools::class, 'migrate']],
            'tests-run' => ['Run tests', 'Runs the test suite (php artisan test), or the tests matching "filter", and returns the summary and the failures.', self::obj(['filter' => $s]), true, false, [RunTools::class, 'tests']],
            'composer' => ['Composer', '"require" or "update" one named package ("package", optional "version"), or "remove" one. On anything but local it needs confirmed.', self::obj(['action' => ['type' => 'string', 'enum' => ['require', 'update', 'remove']], 'package' => $s, 'version' => $s, 'dev' => $b, 'confirmed' => $confirmed], ['action', 'package']), false, true, [RunTools::class, 'composer']],
            'rollback' => ['Undo a run', 'Undoes everything a run changed: files restored, created files removed, its migrations rolled back, back to the branch it started from. "run" is the run id (default the current run).', self::obj(['run' => $s]), false, false, [RunTools::class, 'rollback']],
        ];
    }

    /** What the platform stores and gives the model. */
    public static function definitions(): array
    {
        $out = [];
        foreach (self::tools() as $slug => [$label, $description, $input, $readonly]) {
            $out[] = ['slug' => $slug, 'label' => $label, 'description' => $description, 'input' => $input, 'readonly' => $readonly, 'capability' => $readonly ? 'read' : 'write'];
        }

        return $out;
    }

    /** Runs one tool for the platform. Never throws: a refusal or failure comes back as ['error' => …]. */
    public static function run(string $tool, array $input, array $meta = []): array
    {
        $tools = self::tools();
        if (! isset($tools[$tool])) {
            return ['error' => "this app has no tool \"$tool\" (rekhu/laravel-dev ".Rekhu::VERSION.')'];
        }
        [, , , $readonly, $destructive, $handler] = $tools[$tool];
        try {
            if ($destructive && Rekhu::environment() !== 'local' && ($input['confirmed'] ?? false) !== true && ! ($tool === 'migrate' && ($input['pretend'] ?? true))) {
                return ['confirmation_needed' => 'This step changes the '.Rekhu::environment().' app. Tell the developer exactly what will happen, wait for a yes, then call again with confirmed true.'];
            }
            $result = call_user_func($handler, $input, ['run' => Workspace::runId($meta['run'] ?? null), 'user' => $meta['user'] ?? null]);
            $json = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            if ($json === false || strlen($json) > 900000) {
                return ['error' => 'the answer is too large to send; narrow it down (a filter, a path, fewer lines)'];
            }

            return is_array($result) ? $result : ['result' => $result];
        } catch (Throwable $e) {
            return ['error' => Guard::clip($e->getMessage(), 2000)];
        }
    }
}
