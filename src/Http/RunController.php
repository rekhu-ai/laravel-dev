<?php

namespace Rekhu\LaravelDev\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Rekhu\LaravelDev\Rekhu;
use Rekhu\LaravelDev\Tools\Registry;

/**
 * The platform's door in direct mode. Every call is signed with the app's own secret over the time, the method,
 * the route and the exact body (the same scheme as the WordPress plugin), is refused outside a five-minute window,
 * and is refused when the same signature comes twice.
 */
class RunController
{
    public function run(Request $request): JsonResponse
    {
        if ($refused = $this->verify($request)) {
            return $refused;
        }
        $started = microtime(true);
        $body = $request->json()->all();
        @set_time_limit(115);
        $result = Registry::run((string) ($body['tool'] ?? ''), (array) ($body['input'] ?? []), ['run' => $body['run'] ?? null, 'user' => $body['user'] ?? null]);

        return response()->json(['result' => $result, 'ms' => (int) round((microtime(true) - $started) * 1000)])->header('Cache-Control', 'no-store');
    }

    public function ping(Request $request): JsonResponse
    {
        if ($refused = $this->verify($request)) {
            return $refused;
        }

        return response()->json(['ok' => true, 'package' => Rekhu::VERSION, 'laravel' => app()->version()]);
    }

    private function verify(Request $request): ?JsonResponse
    {
        $secret = (string) config('rekhu.secret');
        $time = (int) $request->header('x-tb-time');
        $signature = (string) $request->header('x-tb-signature');
        if ($secret === '' || ! Rekhu::key()) {
            return response()->json(['message' => 'this app is not connected to Rekhu'], 503);
        }
        if (abs(time() - $time) > 300 || ! preg_match('/^sha256=[a-f0-9]{64}$/', $signature)) {
            return response()->json(['message' => 'signature missing or expired'], 401);
        }
        $route = Rekhu::urls()['route'];
        if ($request->isMethod('GET')) {
            $route = substr($route, 0, -4).'/ping';
        }
        $expected = 'sha256='.hash_hmac('sha256', $time.'.'.$request->method().'.'.$route.'.'.$request->getContent(), $secret);
        if (! hash_equals($expected, $signature)) {
            return response()->json(['message' => 'signature does not match'], 401);
        }
        if (! Cache::add('rekhu-sig-'.substr($signature, 7, 40), 1, 600)) {
            return response()->json(['message' => 'this call was already made once'], 409);
        }

        return null;
    }
}
