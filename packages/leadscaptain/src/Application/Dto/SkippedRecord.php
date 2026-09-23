<?php

declare(strict_types=1);

namespace Leadscaptain\Application\Dto;

/**
 * A record from a page that could not become a Lead.
 */
final readonly class SkippedRecord
{
    public function __construct(
        public int $index,
        public string $reason,
    ) {}
}
