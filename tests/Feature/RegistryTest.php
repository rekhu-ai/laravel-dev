<?php

declare(strict_types=1);

namespace Rekhu\LaravelDev\Tests\Feature;

use Rekhu\LaravelDev\Rekhu;
use Rekhu\LaravelDev\Tests\TestCase;
use Rekhu\LaravelDev\Tools\Registry;

class RegistryTest extends TestCase
{
    public function test_every_tool_is_described_for_the_platform(): void
    {
        $definitions = Registry::definitions();
        $slugs = array_column($definitions, 'slug');
        $this->assertCount(count(array_unique($slugs)), $slugs);
        foreach (['context', 'routes', 'models', 'db-schema', 'db-query', 'logs-search', 'code-read', 'code-patch', 'git', 'migrate', 'tests-run', 'rollback'] as $slug) {
            $this->assertContains($slug, $slugs);
        }
        foreach ($definitions as $tool) {
            $this->assertNotSame('', $tool['label']);
            $this->assertNotSame('', $tool['description']);
            $this->assertSame('object', $tool['input']['type']);
            $this->assertIsBool($tool['readonly']);
            $this->assertSame($tool['readonly'] ? 'read' : 'write', $tool['capability']);
        }
    }

    public function test_every_handler_exists(): void
    {
        foreach (Registry::tools() as $slug => $tool) {
            $this->assertTrue(is_callable($tool[5]), "$slug has no handler");
        }
    }

    public function test_an_unknown_tool_answers_with_an_error_and_never_throws(): void
    {
        $this->assertSame(['error' => 'this app has no tool "drop-everything" (rekhu/laravel-dev '.Rekhu::VERSION.')'], Registry::run('drop-everything', []));
    }

    public function test_a_destructive_step_outside_local_waits_for_the_developer(): void
    {
        $this->app['env'] = 'staging';
        $result = Registry::run('code-delete', ['path' => 'app/Models/User.php']);
        $this->assertArrayHasKey('confirmation_needed', $result);
        $this->assertStringContainsString('staging', $result['confirmation_needed']);
    }

    public function test_a_migration_preview_needs_no_confirmation(): void
    {
        $this->app['env'] = 'staging';
        $result = Registry::run('migrate', []);
        $this->assertArrayNotHasKey('confirmation_needed', $result);
    }
}
