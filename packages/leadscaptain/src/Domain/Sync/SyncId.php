<?php

declare(strict_types=1);

namespace Leadscaptain\Domain\Sync;

use InvalidArgumentException;
use Stringable;

/**
 * Identifies one full sync run (usually the queue batch ID).
 */
final readonly class SyncId implements Stringable
{
    public string $value;

    public function __construct(string $value)
    {
        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException('A sync ID cannot be empty.');
        }

        $this->value = $value;
    }

    /**
     * Generates a random UUID v4.
     */
    public static function generate(): self
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return new self(vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4)));
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
