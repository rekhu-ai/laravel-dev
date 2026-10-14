<?php

namespace Rekhu\LaravelDev\Support;

/**
 * The environment for commands the package starts (artisan, tests, composer). PHP hands a child process the
 * values Laravel loaded from .env, and those would win over phpunit.xml: tests would run against the local
 * database with APP_ENV=local. Every value that came from .env is removed, so the child reads its own settings.
 */
class Shell
{
    public static function env(array $extra = []): array
    {
        $clean = ['NO_COLOR' => '1'];
        foreach (array_keys($_ENV) as $name) {
            if (is_string($name) && preg_match('/^[A-Z][A-Z0-9_]*$/', $name)) {
                $clean[$name] = false;
            }
        }

        return array_merge($clean, $extra);
    }
}
