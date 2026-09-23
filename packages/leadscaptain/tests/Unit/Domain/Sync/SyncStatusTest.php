<?php

declare(strict_types=1);

namespace Leadscaptain\Tests\Unit\Domain\Sync;

use Leadscaptain\Domain\Sync\SyncStatus;
use PHPUnit\Framework\TestCase;

final class SyncStatusTest extends TestCase
{
    public function test_terminal_states(): void
    {
        $this->assertFalse(SyncStatus::Pending->isTerminal());
        $this->assertFalse(SyncStatus::Running->isTerminal());
        $this->assertTrue(SyncStatus::Completed->isTerminal());
        $this->assertTrue(SyncStatus::Failed->isTerminal());
    }

    public function test_allowed_transitions(): void
    {
        $this->assertTrue(SyncStatus::Pending->canTransitionTo(SyncStatus::Running));
        $this->assertTrue(SyncStatus::Pending->canTransitionTo(SyncStatus::Failed));
        $this->assertTrue(SyncStatus::Running->canTransitionTo(SyncStatus::Completed));
        $this->assertTrue(SyncStatus::Running->canTransitionTo(SyncStatus::Failed));
    }

    public function test_forbidden_transitions(): void
    {
        $this->assertFalse(SyncStatus::Pending->canTransitionTo(SyncStatus::Completed));
        $this->assertFalse(SyncStatus::Running->canTransitionTo(SyncStatus::Pending));
        $this->assertFalse(SyncStatus::Completed->canTransitionTo(SyncStatus::Running));
        $this->assertFalse(SyncStatus::Failed->canTransitionTo(SyncStatus::Running));
    }

    public function test_it_is_backed_by_stable_strings(): void
    {
        $this->assertSame(SyncStatus::Completed, SyncStatus::from('completed'));
    }
}
