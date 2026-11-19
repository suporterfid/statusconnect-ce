<?php

namespace Tests\Feature;

use App\Infrastructure\HttpClient\PinnedHttpRequest;
use App\Infrastructure\HttpClient\PinnedHttpResponse;
use App\Infrastructure\HttpClient\PinnedHttpTransport;

/** Records Bot API requests and answers from a queue (default: ok). */
final class FakeTelegramApi implements PinnedHttpTransport
{
    /** @var list<PinnedHttpRequest> */
    public array $requests = [];

    public bool $failWithUrl = false;

    /** @var list<array{0: int, 1: array<string, mixed>}> */
    private array $answers = [];

    /** @param array<string, mixed> $body */
    public function queue(int $status, array $body): void
    {
        $this->answers[] = [$status, $body];
    }

    public function send(PinnedHttpRequest $request): PinnedHttpResponse
    {
        $this->requests[] = $request;
        if ($this->failWithUrl) {
            return new PinnedHttpResponse(0, [], '', hash('sha256', ''), false, $request->endpoint->url, 0,
                transportError: 'cURL error 28: Operation timed out after 5001 milliseconds for '.$request->endpoint->url);
        }

        [$status, $body] = array_shift($this->answers) ?? [200, ['ok' => true, 'result' => ['message_id' => 1]]];
        $json = (string) json_encode($body);

        return new PinnedHttpResponse($status, [], $json, hash('sha256', $json), false, $request->endpoint->url, 0);
    }

    /** @return list<array<string, mixed>> */
    public function bodies(): array
    {
        return array_map(fn (PinnedHttpRequest $r): array => json_decode((string) $r->body, true), $this->requests);
    }

    public function method(int $index): string
    {
        return basename(parse_url($this->requests[$index]->endpoint->url, PHP_URL_PATH));
    }
}
