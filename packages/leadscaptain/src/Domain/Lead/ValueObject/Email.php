<?php

declare(strict_types=1);

namespace Leadscaptain\Domain\Lead\ValueObject;

use Leadscaptain\Domain\Lead\Exception\InvalidLeadData;
use Stringable;

final readonly class Email implements Stringable
{
    public string $value;

    public function __construct(string $value)
    {
        $value = mb_strtolower(trim($value));

        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw InvalidLeadData::invalidEmail($value);
        }

        $this->value = $value;
    }

    /**
     * Returns null for null or blank input instead of throwing.
     */
    public static function fromNullable(?string $value): ?self
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return new self($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
