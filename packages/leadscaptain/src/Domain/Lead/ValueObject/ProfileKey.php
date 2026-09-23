<?php

declare(strict_types=1);

namespace Leadscaptain\Domain\Lead\ValueObject;

use Leadscaptain\Domain\Lead\Exception\InvalidLeadData;
use Stringable;

/**
 * The identity of a lead in the Leadscaptain API. Used as the
 * de-duplication key, so re-importing a lead updates it instead of
 * creating a copy.
 */
final readonly class ProfileKey implements Stringable
{
    public const int MAX_LENGTH = 191;

    public string $value;

    public function __construct(string $value)
    {
        $value = trim($value);

        if ($value === '') {
            throw InvalidLeadData::emptyProfileKey();
        }

        if (mb_strlen($value) > self::MAX_LENGTH) {
            throw InvalidLeadData::profileKeyTooLong(self::MAX_LENGTH);
        }

        $this->value = $value;
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
