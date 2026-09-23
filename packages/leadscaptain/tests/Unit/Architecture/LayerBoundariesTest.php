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
 * - Application depends on Domain only and stays framework-free.
 * - Presentation does not reach into Infrastructure.
 * - Domain and Application call no Laravel helper functions.
 * - Every source file declares strict types.
 */
final class LayerBoundariesTest extends TestCase
{
    private const string SRC = __DIR__.'/../../../src';

    /** Global Laravel helpers that hide a container or config lookup. */
    private const array FORBIDDEN_HELPERS = [
        'app', 'config', 'env', 'now', 'today', 'resolve', 'logger', 'info',
        'event', 'dispatch', 'dispatch_sync', 'cache', 'session', 'request', 'response',
    ];

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
            'Illuminate\\',
            'Laravel\\',
            'GuzzleHttp\\',
            'Leadscaptain\\Infrastructure\\',
            'Leadscaptain\\Presentation\\',
        ]];

        yield 'Presentation' => ['Presentation', [
            'Leadscaptain\\Infrastructure\\',
        ]];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function frameworkFreeLayers(): iterable
    {
        yield 'Domain' => ['Domain'];
        yield 'Application' => ['Application'];
    }

    #[DataProvider('frameworkFreeLayers')]
    public function test_layer_calls_no_laravel_helper_functions(string $layer): void
    {
        $violations = [];

        foreach (self::phpFiles(self::SRC.'/'.$layer) as $file) {
            foreach (self::globalFunctionCalls((string) file_get_contents($file->getPathname())) as $function) {
                if (in_array($function, self::FORBIDDEN_HELPERS, true)) {
                    $violations[] = "{$file->getFilename()} calls {$function}()";
                }
            }
        }

        $this->assertSame([], $violations, "{$layer} layer must not use Laravel helpers.");
    }

    public function test_helper_detection_ignores_methods_and_declarations(): void
    {
        $code = '<?php $a->config(); A::now(); function app() {} new event(); config(); \\now();';

        $this->assertSame(['config', 'now'], self::globalFunctionCalls($code));
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
     * Names of plain function calls (not methods, static calls,
     * declarations or instantiations), lower-cased, without a leading "\".
     *
     * @return list<string>
     */
    private static function globalFunctionCalls(string $code): array
    {
        $tokens = array_values(array_filter(
            token_get_all($code),
            static fn (array|string $token): bool => ! is_array($token)
                || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));

        $calls = [];

        foreach ($tokens as $i => $token) {
            if (! is_array($token) || ! in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }

            if (($tokens[$i + 1] ?? null) !== '(') {
                continue;
            }

            $previous = $tokens[$i - 1] ?? null;

            if (is_array($previous) && in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true)) {
                continue;
            }

            $calls[] = strtolower(ltrim($token[1], '\\'));
        }

        return $calls;
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
