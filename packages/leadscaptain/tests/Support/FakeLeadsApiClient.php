<?php

declare(strict_types=1);

namespace Leadscaptain\Tests\Support;

use Leadscaptain\Application\Contract\LeadsApiClient;
use Leadscaptain\Application\Dto\PageFetchFailure;
use Leadscaptain\Application\Dto\RawPage;
use Leadscaptain\Application\Exception\LeadsApiException;
use Leadscaptain\Domain\Sync\PageNumber;

/**
 * In-memory API that serves generated leads in the documented response
 * shape (data[] + pagination{}) and can be told to fail pages.
 */
final class FakeLeadsApiClient implements LeadsApiClient
{
    private int $totalLeads = 0;

    private bool $withPagination = true;

    private ?int $count = null;

    /** @var array<int, LeadsApiException> */
    private array $failures = [];

    /** @var list<int> Every page requested, in order */
    public array $requestedPages = [];

    /** @var list<list<int>> Pages requested per fetchPages() call */
    public array $concurrentBatches = [];

    public int $countCalls = 0;

    public function __construct(private readonly int $pageSize = 100) {}

    public function withLeads(int $total): self
    {
        $this->totalLeads = $total;

        return $this;
    }

    /** Responses carry no pagination block, like an undocumented API. */
    public function withoutPagination(): self
    {
        $this->withPagination = false;

        return $this;
    }

    public function withCount(?int $count): self
    {
        $this->count = $count;

        return $this;
    }

    public function failPage(int $page, ?LeadsApiException $exception = null): self
    {
        $this->failures[$page] = $exception ?? LeadsApiException::forPage(new PageNumber($page), 500, 'Internal Server Error');

        return $this;
    }

    public function fetchPage(PageNumber $page): RawPage
    {
        $this->requestedPages[] = $page->value;

        if (isset($this->failures[$page->value])) {
            throw $this->failures[$page->value];
        }

        return RawPage::fromResponse($page, $this->body($page->value));
    }

    public function fetchPages(PageNumber ...$pages): array
    {
        $this->concurrentBatches[] = array_map(static fn (PageNumber $page): int => $page->value, $pages);

        $results = [];

        foreach ($pages as $page) {
            try {
                $results[$page->value] = $this->fetchPage($page);
            } catch (LeadsApiException $e) {
                $results[$page->value] = new PageFetchFailure($page, $e);
            }
        }

        return $results;
    }

    public function countLeads(): ?int
    {
        $this->countCalls++;

        return $this->count;
    }

    public function lastPage(): int
    {
        return max(1, (int) ceil($this->totalLeads / $this->pageSize));
    }

    /**
     * @return array<string, mixed>
     */
    private function body(int $page): array
    {
        $first = ($page - 1) * $this->pageSize + 1;
        $last = min($this->totalLeads, $page * $this->pageSize);

        $data = [];

        for ($id = $first; $id <= $last; $id++) {
            $data[] = [
                'id' => $id,
                'first_name' => "First{$id}",
                'last_name' => "Last{$id}",
                'email' => "lead{$id}@example.com",
                'position_title' => 'Developer',
                'company_name' => 'Acme',
                'country_code' => 'IN',
                'industry_name' => 'Technology',
                'email_status' => 'verified',
            ];
        }

        if (! $this->withPagination) {
            return ['data' => $data];
        }

        return [
            'data' => $data,
            'pagination' => [
                'page' => $page,
                'limit' => $this->pageSize,
                'total' => $this->totalLeads,
                'total_pages' => (int) ceil($this->totalLeads / $this->pageSize),
            ],
        ];
    }
}
