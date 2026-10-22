<?php

declare(strict_types=1);

namespace Rekhu\LaravelDev\Tests\Feature;

use Rekhu\LaravelDev\Support\Guard;
use Rekhu\LaravelDev\Tests\TestCase;
use RuntimeException;

class GuardTest extends TestCase
{
    public function test_paths_outside_the_app_are_refused(): void
    {
        foreach (['../.env', 'app/../../secrets.txt', '', "app/x\0.php"] as $path) {
            try {
                Guard::path($path, false);
                $this->fail("$path was allowed");
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_secrets_are_never_read_whatever_the_folder(): void
    {
        foreach (['.env', '.env.production', 'config/../.env', 'storage/oauth-private.key', 'certs/server.pem', '.git/config', 'auth.json'] as $path) {
            $this->assertTrue(Guard::hidden($path), "$path should be hidden");
        }
        $this->assertFalse(Guard::hidden('app/Models/User.php'));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('private to the app');
        Guard::path('.env', false);
    }

    public function test_writes_stay_in_the_writable_folders(): void
    {
        $this->assertSame(base_path('app/Models/Order.php'), Guard::writable('app/Models/Order.php'));
        $this->assertSame(base_path('routes/web.php'), Guard::writable('/routes/web.php'));
        foreach (['vendor/laravel/framework/x.php', 'storage/app/x', 'public/index.php', 'composer.json', '.git/HEAD'] as $path) {
            try {
                Guard::writable($path);
                $this->fail("$path was writable");
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_secret_config_values_are_masked(): void
    {
        $masked = Guard::redact([
            'name' => 'Shop',
            'password' => 'hunter2',
            'stripe' => ['secret' => 'sk_live_x', 'currency' => 'eur'],
            'url' => 'redis://user:pa55@cache.internal:6379',
        ]);
        $this->assertSame('Shop', $masked['name']);
        $this->assertSame('•••• (set)', $masked['password']);
        $this->assertSame('•••• (set)', $masked['stripe']['secret']);
        $this->assertSame('eur', $masked['stripe']['currency']);
        $this->assertSame('redis://••••@cache.internal:6379', $masked['url']);
    }

    public function test_long_answers_are_clipped_and_colour_codes_removed(): void
    {
        $this->assertSame('red', Guard::clip("\e[31mred\e[0m"));
        $this->assertStringEndsWith('(cut at 10 bytes)', Guard::clip(str_repeat('a', 50), 10));
    }
}
