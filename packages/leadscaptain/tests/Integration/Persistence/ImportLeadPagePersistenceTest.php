<?php

declare(strict_types=1);

namespace Leadscaptain\Tests\Integration\Persistence;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Leadscaptain\Application\Mapper\LeadMapper;
use Leadscaptain\Application\UseCase\ImportLeadPage;
use Leadscaptain\Domain\Sync\PageNumber;
use Leadscaptain\Domain\Sync\SyncId;
use Leadscaptain\Infrastructure\Persistence\EloquentLeadRepository;
use Leadscaptain\Infrastructure\Persistence\LeadModel;
use Leadscaptain\Tests\Support\FakeLeadsApiClient;
use Leadscaptain\Tests\Support\RecordingEventPublisher;
use Leadscaptain\Tests\TestCase;

/**
 * The page import use case against the real database: re-processing a
 * page (a retried job) must not create duplicates.
 */
final class ImportLeadPagePersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_re_importing_pages_never_creates_duplicates(): void
    {
        $api = (new FakeLeadsApiClient(pageSize: 50))->withLeads(120);
        $useCase = new ImportLeadPage($api, new LeadMapper, new EloquentLeadRepository, new RecordingEventPublisher);

        foreach ([1, 2, 3, 2, 1, 3] as $page) {
            $useCase->execute(new SyncId('s1'), new PageNumber($page));
        }

        $this->assertSame(120, LeadModel::query()->count());
        $this->assertSame(120, LeadModel::query()->distinct()->count('profile_key'));
        $this->assertSame('First77 Last77', LeadModel::query()->where('profile_key', '77')->value('full_name'));
    }
}
