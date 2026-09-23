<?php

declare(strict_types=1);

namespace Leadscaptain\Domain\Sync;

enum SyncStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed => true,
            self::Pending, self::Running => false,
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Pending => $next === self::Running || $next === self::Failed,
            self::Running => $next === self::Completed || $next === self::Failed,
            self::Completed, self::Failed => false,
        };
    }
}
