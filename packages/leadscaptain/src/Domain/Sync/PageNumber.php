<?php

declare(strict_types=1);

namespace Leadscaptain\Domain\Sync;

use InvalidArgumentException;

final readonly class PageNumber
{
    public function __construct(public int $value)
    {
        if ($value < 1) {
            throw new InvalidArgumentException("Page number must be 1 or greater, got {$value}.");
        }
    }

    public static function first(): self
    {
        return new self(1);
    }

    public function isFirst(): bool
    {
        return $this->value === 1;
    }

    public function next(): self
    {
        return new self($this->value + 1);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
