<?php

declare(strict_types=1);

namespace Rekhu\LaravelDev\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Rekhu\LaravelDev\Support\Workspace;
use Rekhu\LaravelDev\Tests\TestCase;
use Rekhu\LaravelDev\Tools\Registry;

class SafetyTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('orders', function ($table) {
            $table->id();
            $table->string('customer');
        });
        DB::table('orders')->insert(['customer' => 'A']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('rekhu/runs'));
        parent::tearDown();
    }

    public function test_a_read_query_runs_and_leaves_the_connection_writable_afterwards(): void
    {
        $result = Registry::run('db-query', ['sql' => 'select customer from orders']);
        $this->assertSame([['customer' => 'A']], $result['rows']);
        DB::table('orders')->insert(['customer' => 'B']);
        $this->assertSame(2, DB::table('orders')->count());
    }

    /** @return array<string, array{string}> */
    public static function writesAndServerReads(): array
    {
        return [
            'select into a new table' => ['select * into copy from orders'],
            'mysql dump file' => ["select customer from orders into dumpfile '/tmp/x'"],
            'mysql file read' => ["select load_file('/etc/passwd')"],
            'sqlite attach' => ["select 1 from orders where 1 = 0 union select 1; attach database '/tmp/x' as x"],
            'pragma' => ['select * from pragma_table_info(\'orders\') where 0 union select 1 from orders; pragma writable_schema = 1'],
            'postgres file read' => ["select pg_read_file('/etc/passwd')"],
            'postgres other servers' => ["select * from dblink('host=x', 'select 1') as t(a int)"],
            'postgres stopping work' => ['select pg_terminate_backend(1)'],
            'write inside a cte' => ['with gone as (delete from orders returning *) select * from gone'],
            'sequence change' => ["select setval('orders_id_seq', 1)"],
        ];
    }

    #[DataProvider('writesAndServerReads')]
    public function test_queries_that_write_or_reach_the_server_are_refused(string $sql): void
    {
        $result = Registry::run('db-query', ['sql' => $sql]);
        // refused by the tool itself, before the database sees it
        $this->assertMatchesRegularExpression('/only plain reads run here|one statement at a time/', $result['error'] ?? '');
        $this->assertSame(1, DB::table('orders')->count());
    }

    public function test_words_inside_quoted_text_and_quoted_names_are_not_taken_for_statements(): void
    {
        DB::table('orders')->insert(['customer' => 'copy into update']);
        $result = Registry::run('db-query', ['sql' => "select customer as \"update\" from orders where customer like '%copy%'"]);
        $this->assertSame([['update' => 'copy into update']], $result['rows']);
    }

    /** @return array<string, array{string}> */
    public static function quotesThatHideCode(): array
    {
        return [
            'a function after quoted text' => ["select 'copy', load_file('/etc/passwd')"],
            'a backslash that ends a string on one database and not on another' => ["select 'a\\' , pg_read_file('/x'), ''"],
            'a dollar-quoted string around a quote' => ["select \$\$'\$\$, pg_read_file('/x'), \$\$'\$\$"],
            'a comment around a quote' => ["select 1 /* ' */, pg_read_file('/x') /* ' */"],
            'a mysql comment around a quote' => ["select 1 # '\n, load_file('/x') -- '"],
        ];
    }

    #[DataProvider('quotesThatHideCode')]
    public function test_quotes_never_hide_a_statement(string $sql): void
    {
        $result = Registry::run('db-query', ['sql' => $sql]);
        $this->assertStringContainsString('only plain reads run here', $result['error'] ?? '');
    }

    public function test_the_agent_never_changes_its_own_settings(): void
    {
        config(['rekhu.writes' => 'on']);
        $result = Registry::run('code-write', ['path' => 'config/rekhu.php', 'content' => "<?php return ['production_writes' => true];"]);
        $this->assertStringContainsString('never written by the agent', $result['error']);
        $this->assertStringContainsString('private to the app', Registry::run('code-write', ['path' => 'config/.env.production', 'content' => 'X=1'])['error']);
    }

    public function test_tests_are_never_run_on_production(): void
    {
        $this->app['env'] = 'production';
        File::ensureDirectoryExists(base_path('tests'));
        $result = Registry::run('tests-run', []);
        $this->assertStringContainsString('not run on production', $result['error']);
    }

    public function test_config_show_is_not_a_read_only_command_because_it_prints_secrets(): void
    {
        $result = Registry::run('artisan', ['command' => 'config:show database']);
        $this->assertStringContainsString('not on the list', $result['error']);
    }

    public function test_undoing_a_run_is_refused_when_writing_is_off(): void
    {
        $this->app['env'] = 'production';
        Workspace::noteCreated('run-1', 'app/New.php');
        $result = Registry::run('rollback', ['run' => 'run-1']);
        $this->assertStringContainsString('writing is off', $result['error']);
    }

    public function test_undoing_a_run_outside_local_waits_for_the_developer(): void
    {
        $this->app['env'] = 'staging';
        config(['rekhu.writes' => 'on']);
        Workspace::noteCreated('run-1', 'app/New.php');
        $result = Registry::run('rollback', ['run' => 'run-1']);
        $this->assertArrayHasKey('confirmation_needed', $result);
    }

    public function test_a_runs_migrations_are_not_rolled_back_when_others_ran_after_them(): void
    {
        config(['rekhu.writes' => 'on']);
        Schema::create('migrations', function ($table) {
            $table->increments('id');
            $table->string('migration');
            $table->integer('batch');
        });
        DB::table('migrations')->insert([
            ['migration' => '2026_10_01_000000_add_notes_to_orders', 'batch' => 1],
            ['migration' => '2026_10_02_000000_deployed_later', 'batch' => 2],
        ]);
        Workspace::noteCreated('run-1', 'database/migrations/2026_10_01_000000_add_notes_to_orders.php');
        Workspace::noteMigration('run-1', '2026_10_01_000000_add_notes_to_orders');
        $result = Registry::run('rollback', ['run' => 'run-1']);
        $this->assertStringContainsString('other migrations ran after', $result['error']);
        $this->assertSame(2, DB::table('migrations')->count());
    }
}
