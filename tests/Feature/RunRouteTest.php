<?php

declare(strict_types=1);

namespace Rekhu\LaravelDev\Tests\Feature;

use Rekhu\LaravelDev\Tests\TestCase;

class RunRouteTest extends TestCase
{
    public function test_an_app_without_a_key_says_it_is_not_connected(): void
    {
        $this->postJson('/rekhu/run', ['tool' => 'context'])->assertStatus(503)->assertJson(['message' => 'this app is not connected to Rekhu']);
    }

    public function test_a_call_without_a_signature_is_refused(): void
    {
        $this->connect();
        $this->postJson('/rekhu/run', ['tool' => 'context'])->assertStatus(401)->assertJson(['message' => 'signature missing or expired']);
    }

    public function test_a_call_outside_the_five_minute_window_is_refused(): void
    {
        $this->connect();
        $body = ['tool' => 'context'];
        $this->withHeaders($this->signed('POST', '/rekhu/run', json_encode($body), time() - 301))
            ->postJson('/rekhu/run', $body)->assertStatus(401)->assertJson(['message' => 'signature missing or expired']);
    }

    public function test_a_call_signed_with_another_secret_is_refused(): void
    {
        $this->connect();
        $body = ['tool' => 'context'];
        $this->withHeaders($this->signed('POST', '/rekhu/run', json_encode($body), null, 'not-the-secret'))
            ->postJson('/rekhu/run', $body)->assertStatus(401)->assertJson(['message' => 'signature does not match']);
    }

    public function test_a_signature_over_another_body_is_refused(): void
    {
        $this->connect();
        $this->withHeaders($this->signed('POST', '/rekhu/run', json_encode(['tool' => 'context'])))
            ->postJson('/rekhu/run', ['tool' => 'code-delete', 'input' => ['path' => 'app/Models/User.php']])
            ->assertStatus(401)->assertJson(['message' => 'signature does not match']);
    }

    public function test_a_signed_call_runs_and_the_same_call_twice_is_refused(): void
    {
        $this->connect();
        $body = ['tool' => 'no-such-tool', 'input' => []];
        $headers = $this->signed('POST', '/rekhu/run', json_encode($body));

        $this->withHeaders($headers)->postJson('/rekhu/run', $body)
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('result.error', 'this app has no tool "no-such-tool" (rekhu/laravel-dev 1.0.0)');

        $this->withHeaders($headers)->postJson('/rekhu/run', $body)->assertStatus(409)->assertJson(['message' => 'this call was already made once']);
    }

    public function test_the_ping_is_signed_over_its_own_route(): void
    {
        $this->connect();
        $this->withHeaders($this->signed('GET', '/rekhu/ping'))->get('/rekhu/ping')
            ->assertOk()->assertJson(['ok' => true, 'package' => '1.0.0']);
        $this->withHeaders($this->signed('GET', '/rekhu/run'))->get('/rekhu/ping')->assertStatus(401);
    }
}
