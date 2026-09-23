<?php

declare(strict_types=1);

namespace Leadscaptain\Tests\Support;

use RuntimeException;

/**
 * Loads JSON fixtures from tests/Fixtures.
 */
final class Fixture
{
    /**
     * @return array<mixed>
     */
    public static function json(string $path): array
    {
        $contents = file_get_contents(__DIR__.'/../Fixtures/'.$path);

        if ($contents === false) {
            throw new RuntimeException("Fixture {$path} not found.");
        }

        $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException("Fixture {$path} is not a JSON object or array.");
        }

        return $decoded;
    }
}
