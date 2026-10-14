<?php

namespace Rekhu\LaravelDev\Tools;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Route;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Rekhu\LaravelDev\Rekhu;
use Rekhu\LaravelDev\Support\Guard;
use RuntimeException;
use Throwable;

/** What the app is: versions, environment, routes, models, config and packages. All read only. */
class AppTools
{
    public static function context(array $input = [], array $meta = []): array
    {
        $lock = is_file(base_path('composer.lock')) ? json_decode(file_get_contents(base_path('composer.lock')), true) : [];
        $composer = is_file(base_path('composer.json')) ? json_decode(file_get_contents(base_path('composer.json')), true) : [];
        $direct = array_keys(array_merge($composer['require'] ?? [], $composer['require-dev'] ?? []));
        $git = null;
        if (Rekhu::git()) {
            $status = Process::path(base_path())->run(['git', 'status', '--porcelain'])->output();
            $git = [
                'branch' => trim(Process::path(base_path())->run(['git', 'rev-parse', '--abbrev-ref', 'HEAD'])->output()),
                'changed_files' => count(array_filter(explode("\n", trim($status)))),
                'last_commit' => trim(Process::path(base_path())->run(['git', 'log', '-1', '--format=%h %s (%cr)'])->output()),
            ];
        }

        return [
            'app' => [
                'name' => config('app.name'),
                'url' => config('app.url'),
                'environment' => Rekhu::environment(),
                'app_env' => app()->environment(),
                'debug' => (bool) config('app.debug'),
                'laravel' => app()->version(),
                'php' => PHP_VERSION,
                'drivers' => [
                    'database' => config('database.default'),
                    'cache' => config('cache.default'),
                    'queue' => config('queue.default'),
                    'session' => config('session.driver'),
                    'mail' => config('mail.default'),
                    'filesystem' => config('filesystems.default'),
                ],
                'config_cached' => app()->configurationIsCached(),
                'routes_cached' => app()->routesAreCached(),
                'packages' => array_values(array_filter($direct, fn ($p) => $p !== 'php' && ! str_starts_with($p, 'ext-'))),
                'locked_packages' => count($lock['packages'] ?? []) + count($lock['packages-dev'] ?? []),
                'tests' => is_dir(base_path('tests')) ? (is_file(base_path('vendor/bin/pest')) ? 'pest' : 'phpunit') : null,
                'git' => $git,
                'writes' => Rekhu::writes(),
                'connection' => Rekhu::mode(),
                'package' => 'rekhu/laravel-dev '.Rekhu::VERSION,
                'frontend' => self::frontend($direct),
            ],
            'conventions' => self::conventions(),
        ];
    }

    private static function frontend(array $packages): array
    {
        $js = is_file(base_path('package.json')) ? json_decode(file_get_contents(base_path('package.json')), true) : [];
        $deps = array_keys(array_merge($js['dependencies'] ?? [], $js['devDependencies'] ?? []));
        $has = fn ($p) => in_array($p, $packages, true) || in_array($p, $deps, true);

        return array_values(array_filter([
            $has('livewire/livewire') ? 'livewire' : null,
            $has('inertiajs/inertia-laravel') ? 'inertia' : null,
            $has('vue') ? 'vue' : null,
            $has('react') ? 'react' : null,
            $has('filament/filament') ? 'filament' : null,
            $has('tailwindcss') ? 'tailwind' : null,
            is_dir(resource_path('views')) ? 'blade' : null,
        ]));
    }

    /** A few facts about how this app is written, so the agent follows them. */
    private static function conventions(): string
    {
        $lines = [];
        $lines[] = 'Models in '.(is_dir(app_path('Models')) ? 'app/Models' : 'app').'.';
        if (is_dir(app_path('Actions'))) {
            $lines[] = 'Business logic in Action classes (app/Actions).';
        }
        if (is_dir(app_path('Services'))) {
            $lines[] = 'Services in app/Services.';
        }
        if (is_dir(app_path('Http/Requests'))) {
            $lines[] = 'Validation in Form Requests (app/Http/Requests).';
        }
        if (is_file(base_path('pint.json')) || is_file(base_path('vendor/bin/pint'))) {
            $lines[] = 'Code style: Laravel Pint.';
        }
        if (is_file(base_path('phpstan.neon')) || is_file(base_path('phpstan.neon.dist'))) {
            $lines[] = 'Static analysis: PHPStan/Larastan is set up.';
        }
        $lines[] = is_file(base_path('vendor/bin/pest')) ? 'Tests are written with Pest.' : (is_dir(base_path('tests')) ? 'Tests are written with PHPUnit.' : 'The app has no tests folder.');

        return implode(' ', $lines);
    }

    public static function routes(array $input = []): array
    {
        $filter = mb_strtolower((string) ($input['filter'] ?? ''));
        $limit = max(1, min(500, (int) ($input['limit'] ?? 300)));
        $out = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $action = $route->getActionName();
            $row = [
                'methods' => array_values(array_diff($route->methods(), ['HEAD'])),
                'uri' => '/'.ltrim($route->uri(), '/'),
                'name' => $route->getName(),
                'action' => $action === 'Closure' ? 'closure' : $action,
                'middleware' => array_values(array_map(fn ($m) => is_string($m) ? $m : 'closure', $route->gatherMiddleware())),
            ];
            if ($filter !== '' && ! str_contains(mb_strtolower($row['uri'].' '.$row['name'].' '.$row['action']), $filter)) {
                continue;
            }
            if (str_contains($action, '@')) {
                $request = self::formRequest($action);
                if ($request) {
                    $row['form_request'] = $request;
                }
            }
            $out[] = $row;
            if (count($out) >= $limit) {
                break;
            }
        }

        return ['count' => count($out), 'routes' => $out];
    }

    private static function formRequest(string $action): ?string
    {
        [$class, $method] = explode('@', $action, 2);
        try {
            foreach ((new ReflectionMethod($class, $method))->getParameters() as $param) {
                $type = $param->getType();
                if ($type instanceof ReflectionNamedType && ! $type->isBuiltin() && is_subclass_of($type->getName(), FormRequest::class)) {
                    return $type->getName();
                }
            }
        } catch (Throwable) {
            // a controller that does not load is not this tool's problem
        }

        return null;
    }

    public static function models(array $input = []): array
    {
        $filter = mb_strtolower((string) ($input['filter'] ?? ''));
        $out = [];
        foreach (self::modelClasses() as $class) {
            if ($filter !== '' && ! str_contains(mb_strtolower($class), $filter)) {
                continue;
            }
            try {
                $reflection = new ReflectionClass($class);
                if ($reflection->isAbstract()) {
                    continue;
                }
                /** @var Model $model */
                $model = $reflection->newInstanceWithoutConstructor();
                $relations = [];
                foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                    $type = $method->getReturnType();
                    if ($method->getNumberOfParameters() === 0 && $method->class === $class && $type instanceof ReflectionNamedType && is_subclass_of($type->getName(), Relation::class)) {
                        $relations[$method->getName()] = class_basename($type->getName());
                    }
                }
                $model = new $class;
                $out[] = [
                    'model' => $class,
                    'table' => $model->getTable(),
                    'fillable' => $model->getFillable(),
                    'guarded' => $model->getGuarded(),
                    'casts' => $model->getCasts(),
                    'hidden' => $model->getHidden(),
                    'relations' => $relations,
                ];
            } catch (Throwable $e) {
                $out[] = ['model' => $class, 'error' => $e->getMessage()];
            }
        }

        return ['count' => count($out), 'models' => $out];
    }

    private static function modelClasses(): array
    {
        $classes = [];
        $root = is_dir(app_path('Models')) ? app_path('Models') : app_path();
        foreach (File::allFiles($root) as $file) {
            $code = file_get_contents($file->getPathname());
            if (! preg_match('/^namespace\s+([^;]+);/m', $code, $ns) || ! preg_match('/^(?:final\s+|abstract\s+)?class\s+(\w+)/m', $code, $cls)) {
                continue;
            }
            $class = $ns[1].'\\'.$cls[1];
            if (class_exists($class) && is_subclass_of($class, Model::class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    public static function config(array $input = []): array
    {
        $key = trim((string) ($input['key'] ?? ''));
        if ($key === '') {
            return ['files' => array_values(array_map(fn ($f) => pathinfo($f, PATHINFO_FILENAME), glob(config_path('*.php')) ?: []))];
        }
        if (! config()->has($key)) {
            throw new RuntimeException("config \"$key\" is not set");
        }
        $parts = explode('.', $key);

        return ['key' => $key, 'value' => Guard::redact(config($key), (string) end($parts))];
    }

    public static function packages(array $input = []): array
    {
        $lock = is_file(base_path('composer.lock')) ? json_decode(file_get_contents(base_path('composer.lock')), true) : [];
        $composer = is_file(base_path('composer.json')) ? json_decode(file_get_contents(base_path('composer.json')), true) : [];
        $versions = [];
        foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $p) {
            $versions[$p['name']] = $p['version'];
        }
        $direct = [];
        foreach (['require' => false, 'require-dev' => true] as $section => $dev) {
            foreach ($composer[$section] ?? [] as $name => $constraint) {
                $direct[] = ['package' => $name, 'constraint' => $constraint, 'installed' => $versions[$name] ?? null, 'dev' => $dev];
            }
        }
        $out = ['direct' => $direct, 'locked' => count($versions)];
        if (! empty($input['audit'])) {
            $out['audit'] = self::composer(['audit', '--format=json', '--no-interaction']);
        }
        if (! empty($input['outdated'])) {
            $out['outdated'] = self::composer(['outdated', '--direct', '--format=json', '--no-interaction']);
        }

        return $out;
    }

    /** Runs a read-only composer command when composer is on the machine; the answer says so when it is not. */
    private static function composer(array $args): mixed
    {
        $binary = trim(Process::run(['sh', '-c', 'command -v composer'])->output());
        if ($binary === '') {
            return ['error' => 'composer is not installed on this machine'];
        }
        $result = Process::path(base_path())->timeout(120)->run(array_merge([$binary], $args));
        $json = json_decode($result->output(), true);

        return $json ?? ['output' => Guard::clip(trim($result->output()."\n".$result->errorOutput()), 20000)];
    }
}
