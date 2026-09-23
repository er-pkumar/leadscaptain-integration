<?php

declare(strict_types=1);

namespace Leadscaptain\Domain\Lead;

use ArrayIterator;
use Countable;
use IteratorAggregate;

/**
 * Immutable set of leads, unique by profile key.
 * If the same key appears twice, the last occurrence wins, so a page
 * containing duplicates still produces one write per lead.
 *
 * @implements IteratorAggregate<int, Lead>
 */
final readonly class LeadCollection implements Countable, IteratorAggregate
{
    /** @var list<Lead> */
    private array $leads;

    public function __construct(Lead ...$leads)
    {
        $unique = [];

        foreach ($leads as $lead) {
            $unique[$lead->profileKey->value] = $lead;
        }

        $this->leads = array_values($unique);
    }

    public static function empty(): self
    {
        return new self;
    }

    public function isEmpty(): bool
    {
        return $this->leads === [];
    }

    public function count(): int
    {
        return count($this->leads);
    }

    /**
     * @return ArrayIterator<int, Lead>
     */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->leads);
    }

    /**
     * @return list<Lead>
     */
    public function all(): array
    {
        return $this->leads;
    }

    /**
     * @return list<string>
     */
    public function profileKeys(): array
    {
        return array_map(
            static fn (Lead $lead): string => $lead->profileKey->value,
            $this->leads,
        );
    }

    public function merge(self $other): self
    {
        return new self(...$this->leads, ...$other->leads);
    }
}
