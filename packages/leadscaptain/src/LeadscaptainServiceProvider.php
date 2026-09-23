<?php

declare(strict_types=1);

namespace Leadscaptain;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\ServiceProvider;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;

final class LeadscaptainServiceProvider extends ServiceProvider
{
    private const string CONFIG_PATH = __DIR__.'/../config/leadscaptain.php';

    private const string MIGRATIONS_PATH = __DIR__.'/../database/migrations';

    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG_PATH, 'leadscaptain');

        $this->registerLogChannel($this->app->make(Config::class));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(self::MIGRATIONS_PATH);

        if ($this->app->runningInConsole()) {
            $this->publishes([
                self::CONFIG_PATH => $this->app->configPath('leadscaptain.php'),
            ], 'leadscaptain-config');

            $this->publishesMigrations([
                self::MIGRATIONS_PATH => $this->app->databasePath('migrations'),
            ], 'leadscaptain-migrations');
        }
    }

    /**
     * Registers a dedicated channel that writes JSON lines to stderr so
     * container log collectors pick it up. A host-defined channel wins.
     */
    private function registerLogChannel(Config $config): void
    {
        $channel = (string) $config->get('leadscaptain.logging.channel', 'leadscaptain');

        if ($config->has("logging.channels.{$channel}")) {
            return;
        }

        $config->set("logging.channels.{$channel}", [
            'driver' => 'monolog',
            'name' => $channel,
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => $config->get('leadscaptain.logging.stream', 'php://stderr'),
            ],
            'formatter' => JsonFormatter::class,
            'level' => $config->get('leadscaptain.logging.level', 'info'),
        ]);
    }
}
