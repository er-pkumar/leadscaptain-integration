<?php

declare(strict_types=1);

namespace Leadscaptain\Tests\Support;

use Leadscaptain\Application\Contract\DomainEventPublisher;
use Leadscaptain\Domain\Shared\DomainEvent;

final class RecordingEventPublisher implements DomainEventPublisher
{
    /** @var list<DomainEvent> */
    public array $events = [];

    public function publish(DomainEvent ...$events): void
    {
        foreach ($events as $event) {
            $this->events[] = $event;
        }
    }

    /**
     * @template T of DomainEvent
     *
     * @param  class-string<T>  $class
     * @return list<T>
     */
    public function ofType(string $class): array
    {
        return array_values(array_filter(
            $this->events,
            static fn (DomainEvent $event): bool => $event instanceof $class,
        ));
    }
}
