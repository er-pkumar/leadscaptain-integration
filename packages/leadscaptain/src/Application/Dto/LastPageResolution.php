<?php

declare(strict_types=1);

namespace Leadscaptain\Application\Dto;

use Leadscaptain\Domain\Sync\PageNumber;

final readonly class LastPageResolution
{
    /**
     * @param  ?int  $lastPage  Null when the strategy is Unknown
     * @param  bool  $capped  True when max pages lowered the last page
     */
    public function __construct(
        public LastPageStrategy $strategy,
        public ?int $lastPage,
        public bool $capped = false,
    ) {}

    public static function unknown(): self
    {
        return new self(LastPageStrategy::Unknown, null);
    }

    public function isKnown(): bool
    {
        return $this->lastPage !== null;
    }

    /**
     * Pages 2..lastPage; empty when unknown or when there is one page.
     *
     * @return list<PageNumber>
     */
    public function remainingPages(): array
    {
        if ($this->lastPage === null || $this->lastPage < 2) {
            return [];
        }

        return array_map(
            static fn (int $page): PageNumber => new PageNumber($page),
            range(2, $this->lastPage),
        );
    }
}
