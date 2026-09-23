<?php

declare(strict_types=1);

namespace Leadscaptain\Tests\Unit\Domain\Sync;

use InvalidArgumentException;
use Leadscaptain\Domain\Sync\PageNumber;
use PHPUnit\Framework\TestCase;

final class PageNumberTest extends TestCase
{
    public function test_first_page(): void
    {
        $this->assertTrue(PageNumber::first()->isFirst());
        $this->assertSame(1, PageNumber::first()->value);
    }

    public function test_next_page(): void
    {
        $next = PageNumber::first()->next();

        $this->assertSame(2, $next->value);
        $this->assertFalse($next->isFirst());
        $this->assertTrue($next->equals(new PageNumber(2)));
    }

    public function test_it_rejects_zero_and_negative_numbers(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PageNumber(0);
    }
}
