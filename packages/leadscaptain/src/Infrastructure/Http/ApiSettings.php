<?php

declare(strict_types=1);

namespace Leadscaptain\Infrastructure\Http;

use InvalidArgumentException;

/**
 * Connection settings for the Leadscaptain API, read from the package
 * config (`leadscaptain.*`).
 */
final readonly class ApiSettings
{
    public const string AUTH_API_KEY = 'api_key';

    public const string AUTH_BEARER = 'bearer';

    public function __construct(
        public string $baseUrl,
        public ?string $apiKey,
        public string $leadsPath,
        public string $countPath,
        public string $pageParam,
        public string $pageSizeParam,
        public int $pageSize,
        public int $timeout,
        public int $connectTimeout,
        public string $authScheme = self::AUTH_API_KEY,
        public string $apiKeyHeader = 'X-API-Key',
    ) {
        if (! in_array($authScheme, [self::AUTH_API_KEY, self::AUTH_BEARER], true)) {
            throw new InvalidArgumentException("Unknown Leadscaptain auth scheme \"{$authScheme}\"; use \"api_key\" or \"bearer\".");
        }

        if ($pageSize < 1) {
            throw new InvalidArgumentException("Leadscaptain page size must be 1 or greater, got {$pageSize}.");
        }
    }

    /**
     * @param  array<string, mixed>  $config  The `leadscaptain` config array
     */
    public static function fromConfig(array $config): self
    {
        $apiKey = trim(self::string($config, 'api_key', ''));

        return new self(
            baseUrl: rtrim(self::string($config, 'base_url', 'https://api.leadscaptain.com'), '/'),
            apiKey: $apiKey === '' ? null : $apiKey,
            leadsPath: self::string($config, 'leads_path', '/api/v1/leads'),
            countPath: self::string($config, 'count_path', '/api/v1/leads/count'),
            pageParam: self::string($config, 'pagination.page_param', 'page'),
            pageSizeParam: self::string($config, 'pagination.page_size_param', 'limit'),
            pageSize: self::int($config, 'pagination.page_size', 100),
            timeout: self::int($config, 'http.timeout', 30),
            connectTimeout: self::int($config, 'http.connect_timeout', 10),
            authScheme: self::string($config, 'auth.scheme', self::AUTH_API_KEY),
            apiKeyHeader: self::string($config, 'auth.header', 'X-API-Key'),
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function value(array $config, string $path): mixed
    {
        $value = $config;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function string(array $config, string $path, string $default): string
    {
        $value = self::value($config, $path);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function int(array $config, string $path, int $default): int
    {
        $value = self::value($config, $path);

        return is_numeric($value) ? (int) $value : $default;
    }
}
