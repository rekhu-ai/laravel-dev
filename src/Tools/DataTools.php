<?php

namespace Rekhu\LaravelDev\Tools;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/** The database, read only: its schema and single read queries. Data changes go through migrations and seeders. */
class DataTools
{
    public static function schema(array $input = []): array
    {
        $table = trim((string) ($input['table'] ?? ''));
        if ($table === '') {
            $tables = [];
            foreach (Schema::getTables() as $t) {
                $tables[] = ['table' => $t['name'], 'rows' => self::count($t['name']), 'size' => $t['size'] ?? null];
            }

            return ['connection' => DB::getDefaultConnection(), 'tables' => $tables];
        }
        if (! Schema::hasTable($table)) {
            throw new RuntimeException("there is no table \"$table\"");
        }

        return [
            'table' => $table,
            'rows' => self::count($table),
            'columns' => array_map(fn ($c) => array_filter([
                'name' => $c['name'], 'type' => $c['type'], 'nullable' => $c['nullable'], 'default' => $c['default'], 'auto_increment' => $c['auto_increment'] ?: null, 'comment' => $c['comment'] ?? null,
            ], fn ($v) => $v !== null), Schema::getColumns($table)),
            'indexes' => array_map(fn ($i) => ['name' => $i['name'], 'columns' => $i['columns'], 'unique' => $i['unique'], 'primary' => $i['primary']], Schema::getIndexes($table)),
            'foreign_keys' => array_map(fn ($f) => ['columns' => $f['columns'], 'references' => $f['foreign_table'].'('.implode(',', $f['foreign_columns']).')', 'on_delete' => $f['on_delete'] ?? null], Schema::getForeignKeys($table)),
        ];
    }

    private static function count(string $table): ?int
    {
        try {
            return DB::table($table)->count();
        } catch (Throwable) {
            return null;
        }
    }

    public static function query(array $input = []): array
    {
        $sql = trim((string) ($input['sql'] ?? ''));
        $sql = rtrim($sql, "; \n\t");
        if (! preg_match('/^\s*(select|with|show|explain|describe|desc)\b/i', $sql)) {
            throw new RuntimeException('only read queries (SELECT, WITH, SHOW, EXPLAIN) run here; data changes go through a migration or a seeder');
        }
        if (str_contains($sql, ';')) {
            throw new RuntimeException('one statement at a time');
        }
        // statements that write, and functions that write, read server files, reach other servers or stop other work.
        // Words inside quoted text and quoted names are not code, so they are left out of the check; that is only safe
        // where every database reads the quotes the same way, so a query with a backslash, a $ or a comment is checked
        // whole.
        $code = preg_match('/[\\\\$#]|--|\/\*/', $sql) ? $sql : preg_replace('/\'[^\']*\'|"[^"]*"|`[^`]*`/', "''", $sql);
        if (preg_match('/\b(insert|update|delete|drop|alter|truncate|create|grant|revoke|replace|merge|call|lock|into|copy|attach|detach|pragma|vacuum|reindex|handler|load_file|dumpfile|benchmark|get_lock|dblink\w*|lo_\w+|pg_read\w*|pg_ls_dir|pg_stat_file|pg_terminate_backend|pg_cancel_backend|pg_sleep\w*|pg_advisory\w*|set_config|setval|nextval|sleep)\b/i', $code)) {
            throw new RuntimeException('this query contains a statement that changes data or the server; only plain reads run here');
        }
        $limit = max(1, min(200, (int) ($input['limit'] ?? 50)));
        $bindings = array_values(array_map('strval', (array) ($input['bindings'] ?? [])));
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        $started = microtime(true);
        // the database itself refuses writes where it can: MySQL and MariaDB for the next transaction, SQLite for the connection
        if ($driver === 'mysql' || $driver === 'mariadb') {
            $connection->statement('SET TRANSACTION READ ONLY');
        } elseif ($driver === 'sqlite') {
            $connection->statement('PRAGMA query_only = ON');
        }
        try {
            $rows = self::read($connection, $driver, $sql, $bindings, $limit);
        } finally {
            if ($driver === 'sqlite') {
                $connection->statement('PRAGMA query_only = OFF');
            }
        }
        $more = count($rows) > $limit;

        return [
            'rows' => array_slice(array_map(fn ($r) => (array) $r, $rows), 0, $limit),
            'returned' => min(count($rows), $limit),
            'more' => $more,
            'ms' => (int) round((microtime(true) - $started) * 1000),
        ];
    }

    /** The query inside one transaction, with the database's own limits on time and writes. */
    private static function read($connection, string $driver, string $sql, array $bindings, int $limit): array
    {
        return $connection->transaction(function () use ($connection, $driver, $sql, $bindings, $limit) {
            // the database itself refuses writes inside this transaction where it can
            if ($driver === 'pgsql') {
                $connection->statement('SET TRANSACTION READ ONLY');
                $connection->statement("SET LOCAL statement_timeout = '15s'");
            } elseif ($driver === 'mysql' || $driver === 'mariadb') {
                $connection->statement('SET SESSION max_execution_time = 15000');
            }

            // a select is wrapped so the database stops after one row more than asked: a big table never comes over whole
            $wrapped = preg_match('/^\s*(select|with)\b/i', $sql) ? 'SELECT * FROM ('.$sql.') AS rekhu_query LIMIT '.($limit + 1) : $sql;

            return $connection->select($wrapped, $bindings);
        });
    }
}
