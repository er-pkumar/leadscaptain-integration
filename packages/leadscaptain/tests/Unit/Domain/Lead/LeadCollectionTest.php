<?php

declare(strict_types=1);

namespace Leadscaptain\Tests\Unit\Domain\Lead;

use Leadscaptain\Domain\Lead\Lead;
use Leadscaptain\Domain\Lead\LeadCollection;
use Leadscaptain\Domain\Lead\ValueObject\ProfileKey;
use PHPUnit\Framework\TestCase;

final class LeadCollectionTest extends TestCase
{
    public function test_empty_collection(): void
    {
        $collection = LeadCollection::empty();

        $this->assertTrue($collection->isEmpty());
        $this->assertCount(0, $collection);
        $this->assertSame([], $collection->all());
    }

    public function test_duplicates_by_profile_key_keep_the_last_occurrence(): void
    {
        $collection = new LeadCollection(
            self::lead('k1', 'First'),
            self::lead('k2', 'Other'),
            self::lead('k1', 'Latest'),
        );

        $this->assertCount(2, $collection);
        $this->assertSame(['k1', 'k2'], $collection->profileKeys());
        $this->assertSame('Latest', $collection->all()[0]->fullName);
    }

    public function test_numeric_profile_keys_are_preserved_as_strings(): void
    {
        $collection = new LeadCollection(self::lead('123'), self::lead('0045'));

        $this->assertSame(['123', '0045'], $collection->profileKeys());
    }

    public function test_it_is_iterable(): void
    {
        $keys = [];

        foreach (new LeadCollection(self::lead('a'), self::lead('b')) as $lead) {
            $keys[] = $lead->profileKey->value;
        }

        $this->assertSame(['a', 'b'], $keys);
    }

    public function test_merge_returns_a_new_deduplicated_collection(): void
    {
        $first = new LeadCollection(self::lead('a'), self::lead('b'));
        $second = new LeadCollection(self::lead('b', 'Newer'), self::lead('c'));

        $merged = $first->merge($second);

        $this->assertSame(['a', 'b', 'c'], $merged->profileKeys());
        $this->assertSame('Newer', $merged->all()[1]->fullName);
        $this->assertCount(2, $first, 'The original collection must not change.');
    }

    private static function lead(string $key, ?string $name = null): Lead
    {
        return new Lead(new ProfileKey($key), fullName: $name);
    }
}
