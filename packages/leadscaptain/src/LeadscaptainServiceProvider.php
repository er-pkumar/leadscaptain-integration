<?php

declare(strict_types=1);

namespace Leadscaptain;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Leadscaptain\Application\Contract\LeadQuery;
use Leadscaptain\Infrastructure\Persistence\EloquentLeadQuery;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;

final class LeadscaptainServiceProvider extends ServiceProvider
{
    private const string CONFIG_PATH = __DIR__.'/../config/leadscaptain.php';

    private const string MIGRATIONS_PATH = __DIR__.'/../database/migrations';

    private const string ROUTES_PATH = __DIR__.'/../routes/api.php';

    /** @var array<class-string, class-string> */
    public array $bindings = [
        LeadQuery::class => EloquentLeadQuery::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG_PATH, 'leadscaptain');

        $this->registerLogChannel($this->app->make(Config::class));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(self::MIGRATIONS_PATH);
        $this->registerRoutes($this->app->make(Config::class));

        if ($this->app->runningInConsole()) {
            $this->publishes([
                self::CONFIG_PATH => $this->app->configPath('leadscaptain.php'),
            ], 'leadscaptain-config');

            $this->publishesMigrations([
                self::MIGRATIONS_PATH => $this->app->databasePath('migrations'),
            ], 'leadscaptain-migrations');
        }
    }

    private function registerRoutes(Config $config): void
    {
        if (! (bool) $config->get('leadscaptain.routes.enabled', true)) {
            return;
        }

        Route::prefix((string) $config->get('leadscaptain.routes.prefix', 'api/leadscaptain'))
            ->middleware((array) $config->get('leadscaptain.routes.middleware', ['api']))
            ->group(fn () => $this->loadRoutesFrom(self::ROUTES_PATH));
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
