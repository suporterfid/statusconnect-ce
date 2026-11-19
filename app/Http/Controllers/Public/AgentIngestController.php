<?php

namespace App\Http\Controllers\Public;

use App\Application\Agents\AgentIngestService;
use App\Application\Agents\IngestOutcome;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Agent metric ingestion (agent-ingestion addendum §4). Public surface, like /ping/{token}:
 * - POST /ingest/{token}: InfluxDB line protocol from Telegraf's outputs.http;
 * - POST /ingest/{token}/v1/metrics: OTLP/HTTP JSON from the OpenTelemetry Collector or Grafana Alloy.
 */
class AgentIngestController extends Controller
{
    public function __construct(private readonly AgentIngestService $ingest)
    {
    }

    public function lineProtocol(Request $request, string $token): Response|JsonResponse
    {
        if (! config('agent.enabled')) {
            return $this->notFound();
        }

        $type = strtolower((string) $request->headers->get('Content-Type', 'text/plain'));
        if ($type !== '' && ! str_starts_with($type, 'text/plain')) {
            return response()->json(['message' => 'Unsupported Media Type. Send line protocol as text/plain.'], 415);
        }

        $body = $this->readBody($request);
        if ($body === null) {
            return response()->json(['message' => 'Payload Too Large.'], 413);
        }
        if ($body === false) {
            return response()->json(['message' => 'Body is not valid gzip.'], 400);
        }

        $outcome = $this->ingest->ingestLineProtocol($token, $body, $request->ip() ?? '0.0.0.0');

        return match ($outcome->status) {
            IngestOutcome::STORED => response()->noContent(),
            IngestOutcome::NOT_FOUND => $this->notFound(),
            IngestOutcome::RATE_LIMITED => response()->json(['message' => 'Too many requests.'], 429, ['Retry-After' => (string) $outcome->retryAfter]),
            default => response()->json(['message' => $outcome->message], 400),
        };
    }

    /**
     * OTLP/HTTP JSON. Success is 200 with an ExportMetricsServiceResponse;
     * failures carry a google.rpc.Status-shaped body, as the OTLP spec asks.
     */
    public function otlp(Request $request, string $token): Response|JsonResponse
    {
        if (! config('agent.enabled')) {
            return $this->notFound();
        }

        $type = strtolower((string) $request->headers->get('Content-Type', ''));
        if (! str_starts_with($type, 'application/json')) {
            $message = str_starts_with($type, 'application/x-protobuf')
                ? 'Protobuf is not supported. Configure the otlphttp exporter with encoding: json.'
                : 'Unsupported Media Type. Send OTLP metrics as application/json.';

            return $this->otlpStatus(415, $message);
        }

        $body = $this->readBody($request);
        if ($body === null) {
            return $this->otlpStatus(413, 'Payload Too Large.');
        }
        if ($body === false) {
            return $this->otlpStatus(400, 'Body is not valid gzip.');
        }

        $outcome = $this->ingest->ingestOtlp($token, $body, $request->ip() ?? '0.0.0.0');

        return match ($outcome->status) {
            IngestOutcome::STORED => response()->json($outcome->dropped > 0 ? [
                'partialSuccess' => [
                    // int64 is a string in proto3 JSON.
                    'rejectedDataPoints' => (string) $outcome->dropped,
                    'errorMessage' => 'Some data points were not stored: histogram, exponential histogram and summary metrics, points without a numeric value, too many attributes, keys that are too long, or new keys over the per-monitor limit.',
                ],
            ] : new \stdClass),
            IngestOutcome::NOT_FOUND => $this->notFound(),
            IngestOutcome::RATE_LIMITED => response()->json(['code' => 8, 'message' => 'Too many requests.'], 429, ['Retry-After' => (string) $outcome->retryAfter]),
            default => $this->otlpStatus(400, (string) $outcome->message),
        };
    }

    /**
     * Reads at most the configured number of bytes, so an oversized body is
     * refused without being buffered whole. Returns null when a limit is
     * exceeded and false for a broken gzip stream.
     */
    private function readBody(Request $request): string|null|false
    {
        $max = (int) config('agent.max_bytes', 262144);
        $declared = $request->headers->get('Content-Length');
        if ($declared !== null && (int) $declared > $max) {
            return null;
        }

        $stream = $request->getContent(true);
        $raw = is_resource($stream) ? (string) stream_get_contents($stream, $max + 1) : (string) $request->getContent();
        if (strlen($raw) > $max) {
            return null;
        }

        if (strtolower((string) $request->headers->get('Content-Encoding')) !== 'gzip') {
            return $raw;
        }

        return $this->gunzip($raw, (int) config('agent.max_decompressed_bytes', 1048576));
    }

    private function gunzip(string $raw, int $limit): string|null|false
    {
        $context = inflate_init(ZLIB_ENCODING_GZIP);
        if ($context === false) {
            return false;
        }

        $out = '';
        foreach (str_split($raw, 8192) as $chunk) {
            $piece = @inflate_add($context, $chunk, ZLIB_SYNC_FLUSH);
            if ($piece === false) {
                return false;
            }
            $out .= $piece;
            if (strlen($out) > $limit) {
                return null;
            }
        }
        $tail = @inflate_add($context, '', ZLIB_FINISH);
        if ($tail === false) {
            return false;
        }
        $out .= $tail;

        return strlen($out) > $limit ? null : $out;
    }

    private function otlpStatus(int $http, string $message): JsonResponse
    {
        // google.rpc.Code: 3 INVALID_ARGUMENT, 8 RESOURCE_EXHAUSTED.
        return response()->json(['code' => $http === 413 ? 8 : 3, 'message' => $message], $http);
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['message' => 'Not Found'], 404);
    }
}
