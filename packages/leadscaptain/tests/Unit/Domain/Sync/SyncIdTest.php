<?php

declare(strict_types=1);

namespace Leadscaptain\Tests\Unit\Domain\Sync;

use InvalidArgumentException;
use Leadscaptain\Domain\Sync\SyncId;
use PHPUnit\Framework\TestCase;

final class SyncIdTest extends TestCase
{
    public function test_generate_returns_a_uuid_v4(): void
    {
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            SyncId::generate()->value,
        );
    }

    public function test_generated_ids_are_unique(): void
    {
        $this->assertFalse(SyncId::generate()->equals(SyncId::generate()));
    }

    public function test_it_rejects_a_blank_value(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SyncId(' ');
    }

    public function test_it_accepts_an_external_batch_id(): void
    {
        $this->assertSame('batch-42', (string) new SyncId('batch-42'));
    }
}
