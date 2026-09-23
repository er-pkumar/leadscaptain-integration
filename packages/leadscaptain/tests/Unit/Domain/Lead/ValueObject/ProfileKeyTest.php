<?php

declare(strict_types=1);

namespace Leadscaptain\Tests\Unit\Domain\Lead\ValueObject;

use Leadscaptain\Domain\Lead\Exception\InvalidLeadData;
use Leadscaptain\Domain\Lead\ValueObject\ProfileKey;
use PHPUnit\Framework\TestCase;

final class ProfileKeyTest extends TestCase
{
    public function test_it_trims_the_value(): void
    {
        $this->assertSame('abc-123', (new ProfileKey('  abc-123  '))->value);
    }

    public function test_it_rejects_a_blank_value(): void
    {
        $this->expectException(InvalidLeadData::class);

        new ProfileKey('   ');
    }

    public function test_it_rejects_a_value_longer_than_the_column_limit(): void
    {
        $this->expectException(InvalidLeadData::class);

        new ProfileKey(str_repeat('a', ProfileKey::MAX_LENGTH + 1));
    }

    public function test_it_accepts_a_value_at_the_column_limit(): void
    {
        $key = new ProfileKey(str_repeat('a', ProfileKey::MAX_LENGTH));

        $this->assertSame(ProfileKey::MAX_LENGTH, strlen($key->value));
    }

    public function test_equality_is_based_on_value(): void
    {
        $this->assertTrue((new ProfileKey('k1'))->equals(new ProfileKey(' k1 ')));
        $this->assertFalse((new ProfileKey('k1'))->equals(new ProfileKey('k2')));
    }

    public function test_it_casts_to_string(): void
    {
        $this->assertSame('k1', (string) new ProfileKey('k1'));
    }
}
