<?php

declare(strict_types=1);

namespace Leadscaptain\Tests\Unit\Domain\Lead;

use Leadscaptain\Domain\Lead\Lead;
use Leadscaptain\Domain\Lead\ValueObject\CountryCode;
use Leadscaptain\Domain\Lead\ValueObject\Email;
use Leadscaptain\Domain\Lead\ValueObject\ProfileKey;
use PHPUnit\Framework\TestCase;

final class LeadTest extends TestCase
{
    public function test_it_can_be_created_with_only_a_profile_key(): void
    {
        $lead = new Lead(new ProfileKey('k1'));

        $this->assertSame('k1', $lead->profileKey->value);
        $this->assertNull($lead->fullName);
        $this->assertNull($lead->email);
        $this->assertSame([], $lead->attributes);
    }

    public function test_blank_text_fields_become_null_and_others_are_trimmed(): void
    {
        $lead = new Lead(
            profileKey: new ProfileKey('k1'),
            fullName: '  Jane Doe ',
            positionTitle: '   ',
            companyName: '',
            industry: ' Software ',
        );

        $this->assertSame('Jane Doe', $lead->fullName);
        $this->assertNull($lead->positionTitle);
        $this->assertNull($lead->companyName);
        $this->assertSame('Software', $lead->industry);
    }

    public function test_identity_is_the_profile_key_only(): void
    {
        $a = new Lead(new ProfileKey('k1'), fullName: 'Jane');
        $b = new Lead(new ProfileKey('k1'), fullName: 'Jane Updated');
        $c = new Lead(new ProfileKey('k2'), fullName: 'Jane');

        $this->assertTrue($a->hasSameIdentityAs($b));
        $this->assertFalse($a->hasSameIdentityAs($c));
    }

    public function test_it_exposes_raw_attributes(): void
    {
        $lead = new Lead(new ProfileKey('k1'), attributes: ['seniority' => 'senior']);

        $this->assertSame('senior', $lead->attribute('seniority'));
        $this->assertSame('n/a', $lead->attribute('missing', 'n/a'));
    }

    public function test_to_array_returns_primitives(): void
    {
        $lead = new Lead(
            profileKey: new ProfileKey('k1'),
            fullName: 'Jane Doe',
            email: new Email('JANE@example.com'),
            positionTitle: 'CTO',
            companyName: 'Acme',
            industry: 'Software',
            location: 'Bucharest',
            countryCode: new CountryCode('ro'),
            attributes: ['id' => 7],
        );

        $this->assertSame([
            'profile_key' => 'k1',
            'full_name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'position_title' => 'CTO',
            'company_name' => 'Acme',
            'industry' => 'Software',
            'location' => 'Bucharest',
            'country_code' => 'RO',
            'attributes' => ['id' => 7],
        ], $lead->toArray());
    }
}
