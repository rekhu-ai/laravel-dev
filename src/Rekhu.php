<?php

namespace Rekhu\LaravelDev;

use Illuminate\Support\Facades\Process;

/**
 * What the package knows about itself and the app it lives in: the key, how the platform reaches the app,
 * and what the agent may change here.
 */
class Rekhu
{
    public const VERSION = '1.1.0';

    /** The key's parts: agk_<customer>_<agent>_<secret>. Null when there is no usable key. */
    public static function key(): ?array
    {
        $key = (string) config('rekhu.key');
        if (! preg_match('/^agk_([a-z0-9-]+)_([a-z0-9-]+)_[A-Za-z0-9_-]+$/', $key, $m)) {
            return null;
        }

        return ['key' => $key, 'customer' => $m[1], 'agent' => $m[2]];
    }

    /** "relay" or "direct": how the platform reaches this app. */
    public static function mode(): string
    {
        $mode = (string) config('rekhu.mode', 'auto');
        if (in_array($mode, ['relay', 'direct'], true)) {
            return $mode;
        }
        if (app()->environment('local', 'testing')) {
            return 'relay';
        }
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: '';
        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: '';
        $private = $host === '' || $host === 'localhost' || str_ends_with($host, '.test') || str_ends_with($host, '.local')
            || str_ends_with($host, '.localhost') || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false && filter_var($host, FILTER_VALIDATE_IP) !== false;

        return $private || $scheme !== 'https' ? 'relay' : 'direct';
    }

    /** True when the app's root is a git working copy and git can be run. */
    public static function git(): bool
    {
        static $git = null;
        if ($git === null) {
            $git = is_dir(base_path('.git')) && Process::path(base_path())->run(['git', 'rev-parse', '--is-inside-work-tree'])->successful();
        }

        return $git;
    }

    /** "off", "branch" or "on": what the agent may change, after the production rule. */
    public static function writes(): string
    {
        if (app()->environment('production') && ! config('rekhu.production_writes')) {
            return 'off';
        }
        $writes = config('rekhu.writes');
        if (in_array($writes, ['off', 'branch', 'on'], true)) {
            return $writes === 'branch' && ! self::git() ? 'on' : $writes;
        }

        return self::git() ? 'branch' : 'on';
    }

    /** The environment as the agent should think of it. */
    public static function environment(): string
    {
        $env = (string) app()->environment();

        return in_array($env, ['local', 'testing', 'development', 'dev'], true) ? 'local' : ($env === 'production' ? 'production' : $env);
    }

    /** The app's public address and the executor's, as reported to the platform. */
    public static function urls(): array
    {
        $base = rtrim((string) config('app.url'), '/');

        return ['site' => $base, 'rest' => $base.'/'.trim((string) config('rekhu.path', 'rekhu'), '/'), 'route' => '/'.trim((string) config('rekhu.path', 'rekhu'), '/').'/run'];
    }
}
