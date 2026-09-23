<?php

declare(strict_types=1);

namespace Leadscaptain\Domain\Lead\Exception;

use DomainException;

final class InvalidLeadData extends DomainException
{
    public static function emptyProfileKey(): self
    {
        return new self('A lead must have a non-empty profile key.');
    }

    public static function profileKeyTooLong(int $maxLength): self
    {
        return new self("A lead profile key cannot be longer than {$maxLength} characters.");
    }

    public static function invalidEmail(string $email): self
    {
        return new self("\"{$email}\" is not a valid email address.");
    }

    public static function invalidCountryCode(string $code): self
    {
        return new self("\"{$code}\" is not a valid ISO 3166-1 alpha-2 country code.");
    }
}
