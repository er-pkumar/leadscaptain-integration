<?php

declare(strict_types=1);

namespace Leadscaptain\Tests\Unit\Infrastructure\Http;

use InvalidArgumentException;
use Leadscaptain\Infrastructure\Http\ApiSettings;
use PHPUnit\Framework\TestCase;

final class ApiSettingsTest extends TestCase
{
    public function test_it_reads_the_package_config(): void
    {
        $settings = ApiSettings::fromConfig([
            'base_url' => 'https://api.test/',
            'api_key' => 'secret',
            'leads_path' => '/api/v1/leads',
            'count_path' => '/api/v1/leads/count',
            'auth' => ['scheme' => 'bearer', 'header' => 'X-Key'],
            'pagination' => ['page_param' => 'p', 'page_size_param' => 'size', 'page_size' => 50],
            'http' => ['timeout' => 12, 'connect_timeout' => 3],
        ]);

        $this->assertSame('https://api.test', $settings->baseUrl);
        $this->assertSame('secret', $settings->apiKey);
        $this->assertSame('/api/v1/leads', $settings->leadsPath);
        $this->assertSame('/api/v1/leads/count', $settings->countPath);
        $this->assertSame(ApiSettings::AUTH_BEARER, $settings->authScheme);
        $this->assertSame('X-Key', $settings->apiKeyHeader);
        $this->assertSame('p', $settings->pageParam);
        $this->assertSame('size', $settings->pageSizeParam);
        $this->assertSame(50, $settings->pageSize);
        $this->assertSame(12, $settings->timeout);
        $this->assertSame(3, $settings->connectTimeout);
    }

    public function test_missing_values_fall_back_to_documented_defaults(): void
    {
        $settings = ApiSettings::fromConfig([]);

        $this->assertSame('https://api.leadscaptain.com', $settings->baseUrl);
        $this->assertNull($settings->apiKey);
        $this->assertSame('/api/v1/leads', $settings->leadsPath);
        $this->assertSame(ApiSettings::AUTH_API_KEY, $settings->authScheme);
        $this->assertSame('X-API-Key', $settings->apiKeyHeader);
        $this->assertSame('page', $settings->pageParam);
        $this->assertSame('limit', $settings->pageSizeParam);
        $this->assertSame(100, $settings->pageSize);
        $this->assertSame(30, $settings->timeout);
        $this->assertSame(10, $settings->connectTimeout);
    }

    public function test_a_blank_api_key_is_treated_as_missing(): void
    {
        $this->assertNull(ApiSettings::fromConfig(['api_key' => '  '])->apiKey);
    }

    public function test_it_rejects_an_unknown_auth_scheme(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ApiSettings::fromConfig(['auth' => ['scheme' => 'basic']]);
    }

    public function test_it_rejects_a_page_size_below_one(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ApiSettings::fromConfig(['pagination' => ['page_size' => 0]]);
    }
}
