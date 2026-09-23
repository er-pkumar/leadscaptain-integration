<?php

declare(strict_types=1);

namespace Leadscaptain\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Leadscaptain\Domain\Lead\Lead;
use Leadscaptain\Domain\Lead\LeadCollection;
use Leadscaptain\Domain\Lead\ValueObject\CountryCode;
use Leadscaptain\Domain\Lead\ValueObject\Email;
use Leadscaptain\Domain\Lead\ValueObject\ProfileKey;
use Leadscaptain\Infrastructure\Persistence\EloquentLeadRepository;
use Leadscaptain\Tests\TestCase;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\DataProvider;

final class ListLeadsEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_empty_store_returns_an_empty_first_page(): void
    {
        $this->getJson('/api/leadscaptain/leads')
            ->assertOk()
            ->assertExactJson([
                'data' => [],
                'meta' => ['page' => 1, 'per_page' => 20, 'total' => 0, 'last_page' => 1],
                'links' => ['self' => 'http://localhost/api/leadscaptain/leads?page=1', 'next' => null, 'prev' => null],
            ]);
    }

    public function test_it_returns_a_lead_with_every_field(): void
    {
        $this->store(new Lead(
            profileKey: new ProfileKey('123'),
            fullName: 'John Doe',
            email: new Email('john.doe@example.com'),
            positionTitle: 'Senior Developer',
            companyName: 'ABC Technologies',
            industry: 'Technology',
            countryCode: new CountryCode('IN'),
            attributes: ['id' => 123, 'email_status' => 'verified'],
        ));

        $this->getJson('/api/leadscaptain/leads')
            ->assertOk()
            ->assertExactJson([
                'data' => [[
                    'profile_key' => '123',
                    'full_name' => 'John Doe',
                    'email' => 'john.doe@example.com',
                    'position_title' => 'Senior Developer',
                    'company_name' => 'ABC Technologies',
                    'industry' => 'Technology',
                    'location' => null,
                    'country_code' => 'IN',
                    'attributes' => ['id' => 123, 'email_status' => 'verified'],
                ]],
                'meta' => ['page' => 1, 'per_page' => 20, 'total' => 1, 'last_page' => 1],
                'links' => ['self' => 'http://localhost/api/leadscaptain/leads?page=1', 'next' => null, 'prev' => null],
            ]);
    }

    public function test_it_paginates_with_page_and_per_page(): void
    {
        $this->storeMany(25);

        $response = $this->getJson('/api/leadscaptain/leads?page=2&per_page=10')->assertOk();

        $this->assertSame(['11', '12', '13', '14', '15', '16', '17', '18', '19', '20'], array_column($response->json('data'), 'profile_key'));
        $response->assertJsonPath('meta', ['page' => 2, 'per_page' => 10, 'total' => 25, 'last_page' => 3]);
        $response->assertJsonPath('links.next', 'http://localhost/api/leadscaptain/leads?page=3&per_page=10');
        $response->assertJsonPath('links.prev', 'http://localhost/api/leadscaptain/leads?page=1&per_page=10');
    }

    public function test_the_default_page_size_is_20(): void
    {
        $this->storeMany(25);

        $this->getJson('/api/leadscaptain/leads')->assertOk()->assertJsonCount(20, 'data');
    }

    public function test_a_page_past_the_end_is_empty(): void
    {
        $this->storeMany(3);

        $this->getJson('/api/leadscaptain/leads?page=9')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('links.next', null);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidQueries(): iterable
    {
        yield 'page zero' => ['page=0'];
        yield 'page not a number' => ['page=abc'];
        yield 'per_page zero' => ['per_page=0'];
        yield 'per_page over 100' => ['per_page=101'];
        yield 'page as an array' => ['page[]=1'];
    }

    #[DataProvider('invalidQueries')]
    public function test_invalid_paging_returns_422_json(string $query): void
    {
        $this->getJson("/api/leadscaptain/leads?{$query}")
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors']);
    }

    public function test_validation_errors_are_json_even_for_a_browser(): void
    {
        $this->get('/api/leadscaptain/leads?page=0', ['Accept' => 'text/html'])
            ->assertStatus(422)
            ->assertHeader('Content-Type', 'application/json');
    }

    #[DefineEnvironment('useCustomPrefix')]
    public function test_the_route_prefix_is_configurable(): void
    {
        $this->getJson('/crm/leads')->assertOk();
        $this->getJson('/api/leadscaptain/leads')->assertNotFound();
    }

    #[DefineEnvironment('disableRoutes')]
    public function test_routes_can_be_disabled(): void
    {
        $this->getJson('/api/leadscaptain/leads')->assertNotFound();
    }

    public function test_the_route_is_named(): void
    {
        $this->assertSame('http://localhost/api/leadscaptain/leads', route('leadscaptain.leads.index'));
    }

    protected function useCustomPrefix($app): void
    {
        $app['config']->set('leadscaptain.routes.prefix', 'crm');
    }

    protected function disableRoutes($app): void
    {
        $app['config']->set('leadscaptain.routes.enabled', false);
    }

    private function store(Lead ...$leads): void
    {
        (new EloquentLeadRepository)->upsertMany(new LeadCollection(...$leads));
    }

    private function storeMany(int $count): void
    {
        $this->store(...array_map(
            static fn (int $i): Lead => new Lead(new ProfileKey((string) $i), fullName: "Lead {$i}"),
            range(1, $count),
        ));
    }
}
