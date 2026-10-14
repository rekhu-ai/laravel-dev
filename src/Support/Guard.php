<?php

namespace Rekhu\LaravelDev\Support;

use RuntimeException;

/**
 * The limits every tool works within: which files may be read or written, which config values are secrets,
 * and how big an answer may be. A refused request throws with a sentence the agent can pass on.
 */
class Guard
{
    private const ALWAYS_HIDDEN = ['.env', '.env.*', 'auth.json', '*.pem', '*.key', 'storage/oauth-*.key', '.git/*', '.git'];

    private const NEVER_WRITTEN = ['vendor', 'storage', 'node_modules', '.git', 'public/build', 'packages/rekhu'];

    /** The absolute path for a path inside the app, refusing anything outside it or hidden. */
    public static function path(string $relative, bool $mustExist = true): string
    {
        $relative = ltrim(str_replace('\\', '/', trim($relative)), '/');
        if ($relative === '' || str_contains($relative, "\0")) {
            throw new RuntimeException('a path inside the app is needed');
        }
        foreach (explode('/', $relative) as $part) {
            if ($part === '..') {
                throw new RuntimeException('paths outside the app are not available');
            }
        }
        if (self::hidden($relative)) {
            throw new RuntimeException("$relative is private to the app (secrets); the agent never reads or writes it");
        }
        $absolute = base_path($relative);
        if ($mustExist) {
            $real = realpath($absolute);
            if ($real === false) {
                throw new RuntimeException("$relative does not exist");
            }
            if (! str_starts_with($real, realpath(base_path()).DIRECTORY_SEPARATOR) && $real !== realpath(base_path())) {
                throw new RuntimeException('paths outside the app are not available');
            }
        }

        return $absolute;
    }

    public static function hidden(string $relative): bool
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        $base = basename($relative);
        foreach (array_merge(self::ALWAYS_HIDDEN, (array) config('rekhu.hidden', [])) as $pattern) {
            if (fnmatch($pattern, $relative) || fnmatch($pattern, $base)) {
                return true;
            }
        }

        return false;
    }

    /** Refuses a write outside the configured writable folders or into a folder that is never written. */
    public static function writable(string $relative): string
    {
        $relative = ltrim(str_replace('\\', '/', trim($relative)), '/');
        foreach (self::NEVER_WRITTEN as $no) {
            if ($relative === $no || str_starts_with($relative, $no.'/')) {
                throw new RuntimeException("$relative is never written by the agent ($no)");
            }
        }
        $allowed = false;
        foreach ((array) config('rekhu.writable', []) as $root) {
            $root = trim($root, '/');
            if ($relative === $root || str_starts_with($relative, $root.'/')) {
                $allowed = true;
                break;
            }
        }
        if (! $allowed) {
            throw new RuntimeException("$relative is outside the folders the agent may write (config rekhu.writable: ".implode(', ', (array) config('rekhu.writable', [])).')');
        }

        return self::path($relative, false);
    }

    /** Masks secret-looking config values, recursively. */
    public static function redact(mixed $value, string $key = ''): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = self::redact($v, (string) $k);
            }

            return $out;
        }
        if (is_object($value)) {
            return '['.get_class($value).']';
        }
        if (is_string($value) && $value !== '') {
            if (preg_match('/pass|secret|key|token|salt|dsn|private|credential|signature|auth/i', $key)) {
                return '•••• (set)';
            }
            // a URL with credentials in it: keep the host, mask the rest
            if (preg_match('#^([a-z][a-z0-9+.-]*://)[^/@\s]+:[^/@\s]+@#i', $value)) {
                return preg_replace('#^([a-z][a-z0-9+.-]*://)[^/@\s]+:[^/@\s]+@#i', '$1••••@', $value);
            }
        }

        return $value;
    }

    /** Cuts a text to a size the platform takes in one answer, without the terminal's colour codes. */
    public static function clip(string $text, int $bytes = 200000): string
    {
        $text = preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $text) ?? $text;

        return strlen($text) > $bytes ? substr($text, 0, $bytes)."\n… (cut at ".number_format($bytes).' bytes)' : $text;
    }
}
