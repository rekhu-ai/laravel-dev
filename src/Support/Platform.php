<?php

namespace Rekhu\LaravelDev\Support;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Rekhu\LaravelDev\Rekhu;
use Rekhu\LaravelDev\Tools\Registry;
use RuntimeException;

/**
 * The app's calls to the Rekhu platform: verifying the key, reporting the tools, and the relay's long poll.
 * Every call carries the key and says which app it comes from, the way the WordPress plugin does.
 */
class Platform
{
    public static function client(int $timeout = 30): PendingRequest
    {
        $key = Rekhu::key();
        if (! $key) {
            throw new RuntimeException('REKHU_KEY is missing or not a Rekhu site key. Run php artisan rekhu:install. No Rekhu account yet? Create one at https://rekhu.com/register?ref=laravel-dev: it starts with $10 credit, no card needed.');
        }
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost';

        return Http::baseUrl(rtrim((string) config('rekhu.platform'), '/'))
            ->timeout($timeout)
            ->acceptJson()
            ->withHeaders([
                'x-api-key' => $key['key'],
                'x-agent' => $key['agent'],
                'x-tenant' => $host,
                'x-tenant-name' => (string) config('app.name'),
                'x-session' => 'laravel-dev-'.Rekhu::VERSION,
                'user-agent' => 'rekhu-laravel-dev/'.Rekhu::VERSION,
            ]);
    }

    /** GET /v1/balance: the cheapest call that proves the key works. */
    public static function verify(): Response
    {
        return self::client(15)->get('/v1/balance');
    }

    /** GET /v1/me: the account, this key's switches and the project it belongs to. */
    public static function me(): array
    {
        return self::client(15)->get('/v1/me')->throw()->json() ?? [];
    }

    /** POST /v1/abilities: the tools of this app, how to reach it and the secret calls are signed with. */
    public static function report(): array
    {
        $urls = Rekhu::urls();
        $mode = Rekhu::mode();

        return self::client(20)->post('/v1/abilities', [
            'abilities' => Registry::definitions(),
            'secret' => (string) config('rekhu.secret'),
            'site_url' => $urls['site'],
            'rest_url' => $mode === 'direct' ? $urls['rest'] : null,
            'route' => $urls['route'],
            'mode' => $mode,
            'kind' => 'laravel',
            'environment' => Rekhu::environment(),
            'writes' => Rekhu::writes(),
            'framework' => 'Laravel '.app()->version(),
            'version' => Rekhu::VERSION,
            'name' => (string) config('app.name'),
        ])->throw()->json() ?? [];
    }

    /** GET /v1/relay/next: the next call for this app, or null when none came within the wait. */
    public static function next(int $wait = 25): ?array
    {
        $response = self::client($wait + 15)->get('/v1/relay/next', ['wait' => $wait]);
        if ($response->status() === 204) {
            return null;
        }

        return $response->throw()->json();
    }

    /** POST /v1/relay/{id}/result: the answer to a call this app took. */
    public static function answer(int $id, mixed $result): void
    {
        self::client(30)->post('/v1/relay/'.$id.'/result', ['result' => $result])->throw();
    }
}
