<?php

declare(strict_types=1);

namespace Rekhu\LaravelDev\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Rekhu\LaravelDev\RekhuServiceProvider;

abstract class TestCase extends Orchestra
{
    protected const KEY = 'agk_acme_builder_testsecret';

    protected const SECRET = 'the-shared-secret-of-this-app';

    protected function getPackageProviders($app): array
    {
        return [RekhuServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('app.url', 'https://shop.example.com');
        $app['config']->set('rekhu.platform', 'https://rekhu.test');
    }

    protected function connect(): void
    {
        config(['rekhu.key' => self::KEY, 'rekhu.secret' => self::SECRET]);
    }

    /** The headers the platform sends with a call: the time and the signature over time, method, route and body. */
    protected function signed(string $method, string $route, string $body = '', ?int $time = null, string $secret = self::SECRET): array
    {
        $time ??= time();

        return [
            'x-tb-time' => (string) $time,
            'x-tb-signature' => 'sha256='.hash_hmac('sha256', $time.'.'.$method.'.'.$route.'.'.$body, $secret),
        ];
    }
}
