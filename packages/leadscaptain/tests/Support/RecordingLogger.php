<?php

declare(strict_types=1);

namespace Leadscaptain\Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;

final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<mixed>}> */
    public array $records = [];

    /**
     * @param  array<mixed>  $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    }

    /**
     * @return list<array<mixed>> Contexts of the records with this message
     */
    public function contexts(string $message): array
    {
        return array_values(array_map(
            static fn (array $record): array => $record['context'],
            array_filter($this->records, static fn (array $record): bool => $record['message'] === $message),
        ));
    }
}
