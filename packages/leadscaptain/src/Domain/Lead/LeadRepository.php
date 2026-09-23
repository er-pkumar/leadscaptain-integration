<?php

declare(strict_types=1);

namespace Leadscaptain\Domain\Lead;

use Leadscaptain\Domain\Lead\ValueObject\ProfileKey;

/**
 * Persistence contract for leads. Implementations live in the
 * Infrastructure layer.
 */
interface LeadRepository
{
    /**
     * Inserts new leads and updates existing ones matched by profile key.
     * Must be idempotent: saving the same collection twice leaves the
     * store in the same state as saving it once.
     *
     * @return int Number of leads written
     */
    public function upsertMany(LeadCollection $leads): int;

    public function findByProfileKey(ProfileKey $profileKey): ?Lead;

    public function count(): int;
}
