<?php

namespace Rekhu\LaravelDev\Console;

use Illuminate\Console\Command;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Str;
use Rekhu\LaravelDev\Rekhu;
use Rekhu\LaravelDev\Support\Platform;

/** php artisan rekhu:install — asks for the site key, checks it, writes .env, reports the app's tools. */
class InstallCommand extends Command
{
    protected $signature = 'rekhu:install {--key= : The site key from the Rekhu account} {--platform= : Another platform address, for testing}';

    protected $description = 'Connect this app to its agent in your Rekhu account';

    public function handle(): int
    {
        $this->line('');
        $this->line('  <fg=magenta>rekhu</> · Rekhu Builder for Laravel '.Rekhu::VERSION);
        $this->line('  Make a key in your account: on the Connection tab of the project, Rekhu Builder card → Make a key.');
        $this->line('  No Rekhu account yet? Create one at https://rekhu.com/register?ref=laravel-dev: it starts with $10 credit, no card needed.');
        $this->line('');
        $key = trim((string) ($this->option('key') ?: $this->secret('Builder key (agk_…)')));
        if (! preg_match('/^agk_([a-z0-9-]+)_([a-z0-9-]+)_[A-Za-z0-9_-]+$/', $key)) {
            $this->error('That is not a Rekhu site key. Copy it again from the account.');

            return self::FAILURE;
        }
        $values = ['REKHU_KEY' => $key];
        if (! config('rekhu.secret')) {
            $values['REKHU_SECRET'] = Str::random(48);
        }
        if ($this->option('platform')) {
            $values['REKHU_PLATFORM'] = rtrim((string) $this->option('platform'), '/');
        }
        $this->writeEnv($values);
        foreach ($values as $k => $v) {
            config(['rekhu.'.strtolower(substr($k, 6)) => $v]);
        }

        $check = Platform::verify();
        if (! $check->successful()) {
            $this->error('The platform refused the key ('.$check->status().'): '.($check->json('error.message') ?? $check->body()));

            return self::FAILURE;
        }
        $stored = Platform::report();
        $this->info('  Connected. The agent has '.($stored['stored'] ?? 0).' tools for this app.');
        $this->line('  Environment: '.Rekhu::environment().' · writes: '.Rekhu::writes().' · connection: '.Rekhu::mode());

        if (Rekhu::mode() === 'relay') {
            if (! class_exists(DevCommands::class)) {
                $this->addToDevScript();   // Laravel 11 and 12; Laravel 13 registers the listener with php artisan dev itself
            }
            $this->line('');
            $this->line('  This app is reached through the relay. Keep it connected with:');
            $this->line('    <fg=cyan>php artisan rekhu:listen</>   (composer run dev starts it too)');
        }
        $this->line('');

        return self::SUCCESS;
    }

    private function writeEnv(array $values): void
    {
        $path = base_path('.env');
        $env = is_file($path) ? file_get_contents($path) : '';
        foreach ($values as $name => $value) {
            $line = $name.'='.$value;
            $env = preg_match('/^'.$name.'=.*$/m', $env) ? preg_replace('/^'.$name.'=.*$/m', $line, $env) : rtrim($env)."\n".$line."\n";
        }
        file_put_contents($path, $env);
    }

    /** Adds the listener to composer run dev, the command Laravel developers start every day. */
    private function addToDevScript(): void
    {
        $path = base_path('composer.json');
        $composer = json_decode((string) file_get_contents($path), true);
        $dev = $composer['scripts']['dev'] ?? null;
        if (! is_array($dev)) {
            return;
        }
        foreach ($dev as $i => $line) {
            if (is_string($line) && str_contains($line, 'concurrently') && ! str_contains($line, 'rekhu:listen')) {
                // the concurrently line: one more process with its own name and colour
                $line = preg_replace('/-c "([^"]*)"/', '-c "$1,#a55cff"', $line, 1);
                $line = preg_replace('/--names=([^\s]+)/', '--names=$1,rekhu', $line, 1);
                $line .= ' "php artisan rekhu:listen"';
                $composer['scripts']['dev'][$i] = $line;
                file_put_contents($path, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
                $this->line('  Added rekhu:listen to composer run dev.');

                return;
            }
        }
    }
}
