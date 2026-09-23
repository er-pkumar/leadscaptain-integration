<?php

declare(strict_types=1);

namespace Leadscaptain\Tests\Unit\Domain\Lead\ValueObject;

use Leadscaptain\Domain\Lead\Exception\InvalidLeadData;
use Leadscaptain\Domain\Lead\ValueObject\Email;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EmailTest extends TestCase
{
    public function test_it_normalises_to_lower_case_and_trims(): void
    {
        $this->assertSame('jane@example.com', (new Email('  Jane@Example.COM '))->value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidEmails(): iterable
    {
        yield 'missing at sign' => ['jane.example.com'];
        yield 'missing domain' => ['jane@'];
        yield 'blank' => ['   '];
        yield 'spaces inside' => ['jane doe@example.com'];
    }

    #[DataProvider('invalidEmails')]
    public function test_it_rejects_invalid_emails(string $email): void
    {
        $this->expectException(InvalidLeadData::class);

        new Email($email);
    }

    public function test_from_nullable_returns_null_for_missing_values(): void
    {
        $this->assertNull(Email::fromNullable(null));
        $this->assertNull(Email::fromNullable('  '));
    }

    public function test_from_nullable_still_validates_present_values(): void
    {
        $this->expectException(InvalidLeadData::class);

        Email::fromNullable('not-an-email');
    }

    public function test_equality_is_case_insensitive_through_normalisation(): void
    {
        $this->assertTrue((new Email('A@B.co'))->equals(new Email('a@b.co')));
    }
}
