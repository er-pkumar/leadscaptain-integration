<?php

declare(strict_types=1);

namespace Leadscaptain\Domain\Lead\ValueObject;

use Leadscaptain\Domain\Lead\Exception\InvalidLeadData;
use Stringable;

/**
 * ISO 3166-1 alpha-2 country code, stored in upper case.
 */
final readonly class CountryCode implements Stringable
{
    public string $value;

    public function __construct(string $value)
    {
        $value = strtoupper(trim($value));

        if (preg_match('/^[A-Z]{2}$/', $value) !== 1) {
            throw InvalidLeadData::invalidCountryCode($value);
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
