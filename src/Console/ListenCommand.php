<?php

namespace Rekhu\LaravelDev\Console;

use Illuminate\Console\Command;
use Rekhu\LaravelDev\Rekhu;
use Rekhu\LaravelDev\Support\Platform;
use Rekhu\LaravelDev\Tools\Registry;
use Throwable;

/**
 * php artisan rekhu:listen — the relay: keeps one outgoing connection to the platform, takes the agent's tool calls
 * for this app, runs them here and sends the results back. Nothing has to be opened inbound, so it works on a laptop,
 * in Docker or behind a firewall. Reconnects by itself.
 */
class ListenCommand extends Command
{
    protected $signature = 'rekhu:listen {--once : Take one call and stop (for tests)}';

    protected $description = 'Connect this app to its Rekhu agent through the relay (no inbound connection needed)';

    public function handle(): int
    {
        config(['rekhu.mode' => 'relay']);
        $pause = 1;
        $reported = false;
        $this->line('<fg=magenta>rekhu</> listening for the agent · '.config('app.name').' · '.Rekhu::environment().' · writes '.Rekhu::writes());
        while (true) {
            try {
                if (! $reported) {
                    $stored = Platform::report();
                    $reported = true;
                    $this->line('  connected · '.($stored['stored'] ?? 0).' tools reported');
                }
                $call = Platform::next(25);
                $pause = 1;
                if (! $call) {
                    continue;
                }
                $tool = (string) ($call['tool'] ?? '');
                $started = microtime(true);
                $this->line('  → '.$tool);
                $result = Registry::run($tool, (array) ($call['input'] ?? []), ['run' => $call['run'] ?? null, 'user' => $call['user'] ?? null]);
                Platform::answer((int) $call['id'], $result);
                $ms = (int) round((microtime(true) - $started) * 1000);
                $this->line('  ← '.$tool.' '.(isset($result['error']) ? '<fg=red>'.mb_substr($result['error'], 0, 120).'</>' : '<fg=green>ok</>').' · '.$ms.' ms');
                if ($this->option('once')) {
                    return self::SUCCESS;
                }
            } catch (Throwable $e) {
                $this->line('  <fg=yellow>connection lost: '.mb_substr($e->getMessage(), 0, 160).' · retrying in '.$pause.' s</>');
                sleep($pause);
                $pause = min(30, $pause * 2);
                $reported = false;
            }
        }
    }
}
