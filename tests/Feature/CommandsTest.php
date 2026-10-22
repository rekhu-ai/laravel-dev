<?php

declare(strict_types=1);

namespace Rekhu\LaravelDev\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Rekhu\LaravelDev\Rekhu;
use Rekhu\LaravelDev\Tests\TestCase;

class CommandsTest extends TestCase
{
    private string $root;

    private string $original;

    protected function setUp(): void
    {
        parent::setUp();
        // a throwaway app root, so the commands write their .env there and not in the test skeleton
        $this->original = $this->app->basePath();
        $this->root = sys_get_temp_dir().'/rekhu-laravel-dev-'.bin2hex(random_bytes(4));
        File::makeDirectory($this->root);
        File::put($this->root.'/.env', "APP_NAME=Shop\n");
        File::put($this->root.'/composer.json', json_encode(['name' => 'acme/shop', 'scripts' => []]));
        $this->app->setBasePath($this->root);
    }

    protected function tearDown(): void
    {
        $this->app->setBasePath($this->original);
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_install_refuses_something_that_is_not_a_site_key(): void
    {
        $this->artisan('rekhu:install', ['--key' => 'sk-not-a-rekhu-key'])
            ->expectsOutputToContain('That is not a Rekhu site key')
            ->assertFailed();
        $this->assertSame("APP_NAME=Shop\n", File::get($this->root.'/.env'));
    }

    public function test_install_writes_the_key_and_a_secret_and_reports_the_tools(): void
    {
        Http::fake([
            'rekhu.test/v1/balance' => Http::response(['balance' => 10]),
            'rekhu.test/v1/abilities' => Http::response(['stored' => 24]),
        ]);

        $this->artisan('rekhu:install', ['--key' => self::KEY])
            ->expectsOutputToContain('Connected. The agent has 24 tools for this app.')
            ->assertSuccessful();

        $env = File::get($this->root.'/.env');
        $this->assertStringContainsString('APP_NAME=Shop', $env);
        $this->assertStringContainsString('REKHU_KEY='.self::KEY, $env);
        $this->assertMatchesRegularExpression('/^REKHU_SECRET=[A-Za-z0-9]{48}$/m', $env);

        Http::assertSent(function (Request $request) use ($env) {
            if (! str_ends_with($request->url(), '/v1/abilities')) {
                return false;
            }
            preg_match('/^REKHU_SECRET=(.+)$/m', $env, $m);

            return $request->hasHeader('x-api-key', self::KEY)
                && $request['kind'] === 'laravel'
                && $request['secret'] === $m[1]
                && $request['mode'] === 'relay'
                && $request['rest_url'] === null
                && count($request['abilities']) === 24;
        });
    }

    public function test_install_replaces_an_earlier_key_and_keeps_the_secret(): void
    {
        File::put($this->root.'/.env', "APP_NAME=Shop\nREKHU_KEY=agk_old_builder_x\n");
        config(['rekhu.secret' => 'kept-secret']);
        Http::fake(['rekhu.test/*' => Http::response(['stored' => 24])]);

        $this->artisan('rekhu:install', ['--key' => self::KEY])->assertSuccessful();

        $env = File::get($this->root.'/.env');
        $this->assertSame(1, substr_count($env, 'REKHU_KEY='));
        $this->assertStringContainsString('REKHU_KEY='.self::KEY, $env);
        $this->assertStringNotContainsString('REKHU_SECRET=', $env);
    }

    public function test_install_stops_when_the_platform_refuses_the_key(): void
    {
        Http::fake(['rekhu.test/v1/balance' => Http::response(['error' => ['message' => 'invalid api key']], 401)]);

        $this->artisan('rekhu:install', ['--key' => self::KEY])
            ->expectsOutputToContain('The platform refused the key (401): invalid api key')
            ->assertFailed();
    }

    public function test_status_without_a_key_says_how_to_connect(): void
    {
        $this->artisan('rekhu:status')
            ->expectsOutputToContain('missing (php artisan rekhu:install)')
            ->assertFailed();
    }

    public function test_status_with_a_key_shows_the_account_and_the_project(): void
    {
        $this->connect();
        Http::fake([
            'rekhu.test/v1/me' => Http::response(['customer' => ['name' => 'Acme'], 'project' => ['name' => 'Shop']]),
            'rekhu.test/v1/abilities' => Http::response(['stored' => 24]),
        ]);

        $this->artisan('rekhu:status')
            ->expectsOutputToContain('Connected to Acme. 24 tools reported. Project: Shop.')
            ->assertSuccessful();
    }

    public function test_the_listener_takes_a_call_runs_it_and_sends_the_answer_back(): void
    {
        $this->connect();
        Http::fake([
            'rekhu.test/v1/abilities' => Http::response(['stored' => 24]),
            'rekhu.test/v1/relay/next*' => Http::response(['id' => 41, 'tool' => 'no-such-tool', 'input' => []]),
            'rekhu.test/v1/relay/41/result' => Http::response(['ok' => true]),
        ]);

        $this->artisan('rekhu:listen', ['--once' => true])
            ->expectsOutputToContain('connected · 24 tools reported')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v1/relay/41/result')
            && $request['result'] === ['error' => 'this app has no tool "no-such-tool" (rekhu/laravel-dev '.Rekhu::VERSION.')']);
    }
}
