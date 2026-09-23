<?php

declare(strict_types=1);

namespace Leadscaptain\Application\Contract;

use Leadscaptain\Domain\Shared\DomainEvent;

/**
 * Port for emitting domain events to any transport (Laravel events,
 * gRPC, a message bus).
 */
interface DomainEventPublisher
{
    public function publish(DomainEvent ...$events): void;
}
