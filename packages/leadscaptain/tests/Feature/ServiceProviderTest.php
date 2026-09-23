<?php

declare(strict_types=1);

namespace Leadscaptain\Tests\Feature;

use Illuminate\Support\Facades\Log;
use Leadscaptain\Tests\TestCase;

final class ServiceProviderTest extends TestCase
{
    public function test_it_merges_the_package_config(): void
    {
        $this->assertSame('https://api.leadscaptain.com', config('leadscaptain.base_url'));
        $this->assertSame('/api/v1/leads', config('leadscaptain.leads_path'));
        $this->assertSame('limit', config('leadscaptain.pagination.page_size_param'));
        $this->assertSame([1000, 5000, 30000], config('leadscaptain.retry.backoff_ms'));
    }

    public function test_it_registers_a_dedicated_log_channel(): void
    {
        $this->assertIsArray(config('logging.channels.leadscaptain'));

        Log::channel('leadscaptain')->info('leadscaptain smoke test');

        $this->addToAssertionCount(1);
    }

    public function test_the_config_is_publishable(): void
    {
        $this->artisan('vendor:publish', ['--tag' => 'leadscaptain-config', '--force' => true])
            ->assertSuccessful();

        $this->assertFileExists(config_path('leadscaptain.php'));
    }
}
