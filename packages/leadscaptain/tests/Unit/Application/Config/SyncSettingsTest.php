<?php

declare(strict_types=1);

namespace Leadscaptain\Tests\Unit\Application\Config;

use InvalidArgumentException;
use Leadscaptain\Application\Config\SyncSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SyncSettingsTest extends TestCase
{
    public function test_it_holds_valid_settings(): void
    {
        $settings = new SyncSettings(pageSize: 100, maxPages: 1000, concurrency: 10);

        $this->assertSame(100, $settings->pageSize);
        $this->assertSame(1000, $settings->maxPages);
        $this->assertSame(10, $settings->concurrency);
    }

    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function invalidSettings(): iterable
    {
        yield 'zero page size' => [0, 1000, 10];
        yield 'zero max pages' => [100, 0, 10];
        yield 'zero concurrency' => [100, 1000, 0];
    }

    #[DataProvider('invalidSettings')]
    public function test_it_rejects_values_below_one(int $pageSize, int $maxPages, int $concurrency): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SyncSettings($pageSize, $maxPages, $concurrency);
    }
}
