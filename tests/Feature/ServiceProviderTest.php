<?php

declare(strict_types=1);

namespace Rekhu\LaravelDev\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Rekhu\LaravelDev\Rekhu;
use Rekhu\LaravelDev\Tests\TestCase;

class ServiceProviderTest extends TestCase
{
    public function test_the_config_is_merged_with_safe_defaults(): void
    {
        $this->assertSame('https://rekhu.test', config('rekhu.platform'));
        $this->assertSame('rekhu', config('rekhu.path'));
        $this->assertContains('.env', config('rekhu.hidden'));
        $this->assertContains('app', config('rekhu.writable'));
        $this->assertFalse(config('rekhu.production_writes'));
    }

    public function test_the_routes_and_commands_are_registered(): void
    {
        $this->assertTrue(Route::has('rekhu.run'));
        $this->assertTrue(Route::has('rekhu.ping'));
        $commands = array_keys(Artisan::all());
        foreach (['rekhu:install', 'rekhu:status', 'rekhu:listen', 'rekhu:report', 'rekhu:rollback'] as $command) {
            $this->assertContains($command, $commands);
        }
    }

    public function test_a_key_is_read_into_its_parts_and_anything_else_is_refused(): void
    {
        $this->assertNull(Rekhu::key());
        config(['rekhu.key' => 'sk-something-else']);
        $this->assertNull(Rekhu::key());
        config(['rekhu.key' => self::KEY]);
        $this->assertSame(['key' => self::KEY, 'customer' => 'acme', 'agent' => 'builder'], Rekhu::key());
    }

    public function test_the_connection_mode_follows_the_environment_unless_set(): void
    {
        $this->assertSame('relay', Rekhu::mode());   // the testing environment counts as local
        config(['rekhu.mode' => 'direct']);
        $this->assertSame('direct', Rekhu::mode());
    }

    public function test_production_is_read_only_unless_writes_are_allowed_there(): void
    {
        $this->app['env'] = 'production';
        config(['rekhu.writes' => 'on']);
        $this->assertSame('off', Rekhu::writes());
        $this->assertSame('production', Rekhu::environment());
        config(['rekhu.production_writes' => true]);
        $this->assertSame('on', Rekhu::writes());
    }

    public function test_the_addresses_follow_the_app_url_and_the_path(): void
    {
        config(['rekhu.path' => '/builder/']);
        $this->assertSame(['site' => 'https://shop.example.com', 'rest' => 'https://shop.example.com/builder', 'route' => '/builder/run'], Rekhu::urls());
    }
}
