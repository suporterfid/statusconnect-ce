<?php

namespace App\Domain\Monitoring\Agent;

/**
 * Parser for the InfluxDB line protocol as sent by Telegraf's outputs.http
 * with data_format = "influx":
 *
 *     measurement[,tag=value...] field=value[,field=value...] [timestamp_ns]
 *
 * Integers (1i), unsigned (1u), floats and booleans become numeric samples.
 * String fields are ignored and counted (agent-ingestion addendum §5.5).
 * Escapes follow the protocol: "\," and "\ " in measurements; "\,", "\=" and
 * "\ " in tag keys, tag values and field keys; "\"" and "\\" inside strings.
 */
final class LineProtocolParser
{
    public function __construct(
        private readonly int $maxLines = 2000,
        private readonly int $maxLineLength = 65536,
        private readonly int $maxKeyLength = 255,
    ) {
    }

    public function parse(string $body): ParseResult
    {
        $samples = [];
        $ignoredStrings = 0;
        $droppedKeys = 0;
        $count = 0;

        foreach (preg_split('/\r\n|\n|\r/', $body) ?: [] as $index => $rawLine) {
            $lineNumber = $index + 1;
            $line = trim($rawLine);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (++$count > $this->maxLines) {
                return ParseResult::failed(new LineProtocolError(LineProtocolError::TOO_MANY_LINES, $lineNumber, sprintf('More than %d lines', $this->maxLines)));
            }
            if (strlen($line) > $this->maxLineLength) {
                return ParseResult::failed(new LineProtocolError(LineProtocolError::LINE_TOO_LONG, $lineNumber, sprintf('Line %d is longer than %d bytes', $lineNumber, $this->maxLineLength)));
            }

            $parsed = $this->parseLine($line);
            if ($parsed === null) {
                return ParseResult::failed(new LineProtocolError(LineProtocolError::MALFORMED, $lineNumber, sprintf('Line %d is not valid line protocol', $lineNumber)));
            }

            [$measurement, $tags, $fields, $timestamp] = $parsed;
            foreach ($fields as $field => $value) {
                if ($value === null) {
                    $ignoredStrings++;

                    continue;
                }

                $key = MetricSample::canonicalKey($measurement, $field, $tags);
                if (strlen($key) > $this->maxKeyLength) {
                    $droppedKeys++;

                    continue;
                }

                ksort($tags, SORT_STRING);
                $samples[$key] = new MetricSample($key, $measurement, $field, $tags, $value, $timestamp);
            }
        }

        return new ParseResult(array_values($samples), $ignoredStrings, $droppedKeys);
    }

    /**
     * @return array{0: string, 1: array<string, string>, 2: array<string, float|null>, 3: int|null}|null
     */
    private function parseLine(string $line): ?array
    {
        $sections = $this->split($line, ' ', true);
        $sections = array_values(array_filter($sections, static fn (string $s): bool => $s !== ''));
        if (count($sections) < 2 || count($sections) > 3) {
            return null;
        }

        $series = $this->split($sections[0], ',', false);
        $measurement = $this->unescape(array_shift($series));
        if ($measurement === '') {
            return null;
        }

        $tags = [];
        foreach ($series as $pair) {
            $kv = $this->splitPair($pair);
            if ($kv === null) {
                return null;
            }
            $tags[$this->unescape($kv[0])] = $this->unescape($kv[1]);
        }

        $fields = [];
        foreach ($this->split($sections[1], ',', true) as $pair) {
            $kv = $this->splitPair($pair);
            if ($kv === null) {
                return null;
            }
            $value = $this->fieldValue($kv[1]);
            if ($value === false) {
                return null;
            }
            $fields[$this->unescape($kv[0])] = $value;
        }
        if ($fields === []) {
            return null;
        }

        $timestamp = null;
        if (isset($sections[2])) {
            if (preg_match('/^-?\d{1,19}$/', $sections[2]) !== 1) {
                return null;
            }
            $timestamp = (int) $sections[2];
        }

        return [$measurement, $tags, $fields, $timestamp];
    }

    /**
     * Numeric value, null for a string field (ignored), false when malformed.
     */
    private function fieldValue(string $raw): float|null|false
    {
        if ($raw === '') {
            return false;
        }
        if ($raw[0] === '"') {
            return strlen($raw) >= 2 && str_ends_with($raw, '"') ? null : false;
        }
        if (in_array($raw, ['t', 'T', 'true', 'True', 'TRUE'], true)) {
            return 1.0;
        }
        if (in_array($raw, ['f', 'F', 'false', 'False', 'FALSE'], true)) {
            return 0.0;
        }
        if (preg_match('/^-?\d+i$/', $raw) === 1 || preg_match('/^\d+u$/', $raw) === 1) {
            return (float) substr($raw, 0, -1);
        }
        if (preg_match('/^[-+]?(\d+\.?\d*|\.\d+)([eE][-+]?\d+)?$/', $raw) === 1) {
            $value = (float) $raw;

            return is_finite($value) ? $value : false;
        }

        return false;
    }

    /**
     * Splits on an unescaped separator, optionally treating double-quoted
     * spans as opaque.
     *
     * @return list<string>
     */
    private function split(string $input, string $separator, bool $honourQuotes): array
    {
        $parts = [];
        $current = '';
        $inQuotes = false;
        $length = strlen($input);

        for ($i = 0; $i < $length; $i++) {
            $char = $input[$i];
            if ($char === '\\' && $i + 1 < $length) {
                $current .= $char.$input[++$i];

                continue;
            }
            if ($honourQuotes && $char === '"') {
                $inQuotes = ! $inQuotes;
            }
            if ($char === $separator && ! $inQuotes) {
                $parts[] = $current;
                $current = '';

                continue;
            }
            $current .= $char;
        }
        $parts[] = $current;

        return $parts;
    }

    /**
     * @return array{0: string, 1: string}|null split at the first unescaped "="
     */
    private function splitPair(string $pair): ?array
    {
        $length = strlen($pair);
        for ($i = 0; $i < $length; $i++) {
            if ($pair[$i] === '\\') {
                $i++;

                continue;
            }
            if ($pair[$i] === '=') {
                $key = substr($pair, 0, $i);
                $value = substr($pair, $i + 1);

                return $key === '' || $value === '' ? null : [$key, $value];
            }
        }

        return null;
    }

    private function unescape(string $value): string
    {
        return preg_replace('/\\\\([ ,=\\\\"])/', '$1', $value) ?? $value;
    }
}
