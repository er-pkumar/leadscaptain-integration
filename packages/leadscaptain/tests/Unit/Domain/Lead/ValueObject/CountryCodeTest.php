<?php

declare(strict_types=1);

namespace Leadscaptain\Tests\Unit\Domain\Lead\ValueObject;

use Leadscaptain\Domain\Lead\Exception\InvalidLeadData;
use Leadscaptain\Domain\Lead\ValueObject\CountryCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CountryCodeTest extends TestCase
{
    public function test_it_normalises_to_upper_case(): void
    {
        $this->assertSame('RO', (new CountryCode(' ro '))->value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidCodes(): iterable
    {
        yield 'three letters' => ['ROU'];
        yield 'one letter' => ['R'];
        yield 'digits' => ['12'];
        yield 'blank' => [''];
    }

    #[DataProvider('invalidCodes')]
    public function test_it_rejects_invalid_codes(string $code): void
    {
        $this->expectException(InvalidLeadData::class);

        new CountryCode($code);
    }

    public function test_from_nullable_returns_null_for_missing_values(): void
    {
        $this->assertNull(CountryCode::fromNullable(null));
        $this->assertNull(CountryCode::fromNullable(''));
        $this->assertSame('US', CountryCode::fromNullable('us')?->value);
    }
}
