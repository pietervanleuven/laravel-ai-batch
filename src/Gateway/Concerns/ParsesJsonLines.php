<?php

namespace AiBatch\Gateway\Concerns;

use AiBatch\BatchRequestFailed;
use AiBatch\BatchRequestFailureType;
use AiBatch\Exceptions\BatchException;
use Generator;
use Illuminate\Http\Client\Response;
use Psr\Http\Message\StreamInterface;

trait ParsesJsonLines
{
    /**
     * Bytes read from the response stream per iteration.
     */
    protected int $jsonLineChunkSize = 65536;

    /**
     * Decode a JSONL document line by line without holding the whole body in memory.
     *
     * A line that cannot be decoded yields a BatchRequestFailed when its custom id is
     * recoverable, and throws otherwise, so a truncated download never looks like fewer results.
     *
     * @return Generator<int, array<string, mixed>|BatchRequestFailed>
     */
    protected function jsonLines(StreamInterface|Response|string $source): Generator
    {
        $stream = $source instanceof Response ? $source->toPsrResponse()->getBody() : $source;

        if ($stream instanceof StreamInterface && $stream->isSeekable()) {
            $stream->rewind();
        }

        $buffer = '';

        $chunks = is_string($stream)
            ? [$stream]
            : (function () use ($stream): Generator {
                while (! $stream->eof()) {
                    yield $stream->read($this->jsonLineChunkSize);
                }
            })();

        foreach ($chunks as $chunk) {
            $buffer .= $chunk;

            while (($newline = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $newline);
                $buffer = substr($buffer, $newline + 1);

                if (($decoded = $this->decodeJsonLine($line)) !== null) {
                    yield $decoded;
                }
            }
        }

        if (($decoded = $this->decodeJsonLine($buffer)) !== null) {
            yield $decoded;
        }
    }

    /**
     * @return array<string, mixed>|BatchRequestFailed|null
     */
    protected function decodeJsonLine(string $line): array|BatchRequestFailed|null
    {
        $line = trim($line);

        if ($line === '') {
            return null;
        }

        $decoded = json_decode($line, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/"custom_id"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/', $line, $matches)) {
            return new BatchRequestFailed(
                customId: stripcslashes($matches[1]),
                message: 'The result line could not be decoded: '.json_last_error_msg(),
                type: BatchRequestFailureType::Invalid,
                code: 'invalid_line',
                raw: ['line' => $line],
            );
        }

        throw new BatchException('A batch result line could not be decoded and carries no custom id: '.substr($line, 0, 200));
    }

    /**
     * Encode records as a JSONL document.
     *
     * @param  iterable<int, array<string, mixed>>  $records
     */
    protected function toJsonLines(iterable $records): string
    {
        $lines = '';

        foreach ($records as $record) {
            $lines .= json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        }

        return $lines;
    }
}
