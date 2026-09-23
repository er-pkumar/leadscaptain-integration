<?php

declare(strict_types=1);

namespace Leadscaptain\Tests\Unit\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Guards the Onion Architecture rules:
 * - Domain depends on nothing outside itself (no framework, no outer layers).
 * - Application does not depend on Infrastructure or Presentation.
 * - Every source file declares strict types.
 */
final class LayerBoundariesTest extends TestCase
{
    private const string SRC = __DIR__.'/../../../src';

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function layerRules(): iterable
    {
        yield 'Domain' => ['Domain', [
            'Illuminate\\',
            'Laravel\\',
            'GuzzleHttp\\',
            'Leadscaptain\\Application\\',
            'Leadscaptain\\Infrastructure\\',
            'Leadscaptain\\Presentation\\',
        ]];

        yield 'Application' => ['Application', [
            'Leadscaptain\\Infrastructure\\',
            'Leadscaptain\\Presentation\\',
        ]];
    }

    /**
     * @param  list<string>  $forbidden
     */
    #[DataProvider('layerRules')]
    public function test_layer_does_not_depend_on_outer_layers(string $layer, array $forbidden): void
    {
        $violations = [];

        foreach (self::phpFiles(self::SRC.'/'.$layer) as $file) {
            $code = (string) file_get_contents($file->getPathname());

            foreach ($forbidden as $namespace) {
                if (str_contains($code, 'use '.$namespace) || str_contains($code, '\\'.$namespace)) {
                    $violations[] = "{$file->getFilename()} references {$namespace}";
                }
            }
        }

        $this->assertSame([], $violations, "{$layer} layer boundary violated.");
    }

    public function test_every_source_file_declares_strict_types(): void
    {
        $missing = [];

        foreach (self::phpFiles(self::SRC) as $file) {
            if (! str_contains((string) file_get_contents($file->getPathname()), 'declare(strict_types=1);')) {
                $missing[] = $file->getFilename();
            }
        }

        $this->assertSame([], $missing, 'These files are missing declare(strict_types=1).');
    }

    /**
     * @return list<SplFileInfo>
     */
    private static function phpFiles(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file;
            }
        }

        return $files;
    }
}
