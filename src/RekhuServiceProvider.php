<?php

namespace Rekhu\LaravelDev;

use Illuminate\Foundation\DevCommands;
use Illuminate\Support\ServiceProvider;
use Rekhu\LaravelDev\Console\InstallCommand;
use Rekhu\LaravelDev\Console\ListenCommand;
use Rekhu\LaravelDev\Console\ReportCommand;
use Rekhu\LaravelDev\Console\RollbackCommand;
use Rekhu\LaravelDev\Console\StatusCommand;

class RekhuServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/rekhu.php', 'rekhu');
    }

    public function boot(): void
    {
        // the door the platform calls in direct mode; without a key it answers that the app is not connected
        $this->loadRoutesFrom(__DIR__.'/../routes/rekhu.php');

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/rekhu.php' => config_path('rekhu.php')], 'rekhu-config');
            $this->commands([InstallCommand::class, StatusCommand::class, ListenCommand::class, ReportCommand::class, RollbackCommand::class]);

            // Laravel 13's php artisan dev (composer run dev) runs the listener next to the server, queue and Vite,
            // so a local app is connected whenever the developer works. Older versions get it through composer.json.
            if (class_exists(DevCommands::class) && Rekhu::key() && Rekhu::mode() === 'relay') {
                DevCommands::artisan('rekhu:listen', 'rekhu')->purple();
            }
        }
    }
}
