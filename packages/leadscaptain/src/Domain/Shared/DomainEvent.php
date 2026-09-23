<?php

declare(strict_types=1);

namespace Leadscaptain\Domain\Shared;

use DateTimeImmutable;

/**
 * A fact that happened in the domain. Payloads contain primitives only,
 * so any transport (Laravel events, gRPC, a message bus) can serialise them.
 */
interface DomainEvent
{
    public function eventName(): string;

    public function occurredAt(): DateTimeImmutable;

    /**
     * @return array<string, scalar|null>
     */
    public function payload(): array;
}
