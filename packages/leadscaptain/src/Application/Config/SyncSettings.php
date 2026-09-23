<?php

declare(strict_types=1);

namespace Leadscaptain\Application\Config;

use InvalidArgumentException;

/**
 * Sync settings the Application layer needs. Built from the package config
 * by the service provider, so use cases never read config themselves.
 */
final readonly class SyncSettings
{
    public function __construct(
        public int $pageSize,
        public int $maxPages,
        public int $concurrency,
    ) {
        foreach (['pageSize' => $pageSize, 'maxPages' => $maxPages, 'concurrency' => $concurrency] as $name => $value) {
            if ($value < 1) {
                throw new InvalidArgumentException("{$name} must be 1 or greater, got {$value}.");
            }
        }
    }
}
