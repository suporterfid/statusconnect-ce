<?php

namespace App\Domain\Notifications\Transports;

use App\Domain\Outbound\EgressProfile;
use App\Domain\Outbound\OutboundPolicy;
use App\Domain\Outbound\OutboundPolicyViolation;
use App\Domain\Outbound\ValidatedEndpoint;
use App\Domain\Shared\Clock;
use App\Infrastructure\HttpClient\GuzzlePinnedHttpTransport;
use App\Infrastructure\HttpClient\PinnedHttpRequest;
use App\Infrastructure\Persistence\Eloquent\NotificationChannel;
use Throwable;

final class TaskConnectNotificationTransport implements NotificationTransportInterface
{
    public function __construct(
        private readonly OutboundPolicy $policy,
        private readonly GuzzlePinnedHttpTransport $transport,
        private readonly Clock $clock,
    ) {
    }

    public function send(NotificationChannel $channel, array $payload): array
    {
        $config = $channel->config_json ?? [];
        $url = $config['url'] ?? $config['base_url'] ?? $config['taskconnect_url'] ?? null;
        $apiKey = $config['api_key'] ?? $config['token'] ?? '';

        if (! is_string($url) || trim($url) === '') {
            return [
                'status' => 'failed',
                'response_code' => 400,
                'error_excerpt' => 'No valid TaskConnect URL configured.',
            ];
        }

        try {
            $validatedUrl = $this->policy->validateUrl(trim($url), egressProfile: EgressProfile::Webhook);
            $pinnedEndpoint = new ValidatedEndpoint(
                url: $validatedUrl->url,
                scheme: $validatedUrl->scheme,
                host: $validatedUrl->host,
                port: $validatedUrl->port,
                pinnedIp: $validatedUrl->resolvedIps[0],
                resolvedIps: $validatedUrl->resolvedIps,
                hostAllowlisted: $validatedUrl->hostAllowlisted,
            );

            $incidentId = $payload['incident_id'] ?? 'none';
            $event = $payload['event'] ?? 'unknown';
            $idempotencyKey = 'sc_'.hash('sha256', "{$incidentId}:{$channel->id}:{$event}");

            $taskPayload = [
                'name' => "StatusConnect Incident: {$event}",
                'payload' => $payload,
                'metadata' => [
                    'source' => 'statusconnect',
                    'incident_id' => $incidentId,
                    'channel_id' => $channel->public_id,
                ],
            ];

            $jsonBody = json_encode($taskPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';

            $headers = [
                'Content-Type' => 'application/json',
                'User-Agent' => 'StatusConnect-TaskConnect/1.0',
                'X-Idempotency-Key' => $idempotencyKey,
            ];

            if (is_string($apiKey) && trim($apiKey) !== '') {
                $headers['Authorization'] = 'Bearer '.trim($apiKey);
            }

            $pinnedRequest = new PinnedHttpRequest(
                method: 'POST',
                endpoint: $pinnedEndpoint,
                headers: $headers,
                body: $jsonBody,
                verifyTls: $config['verify_tls'] ?? true,
                followRedirects: true,
                egressProfile: EgressProfile::Webhook,
            );

            $response = $this->transport->send($pinnedRequest);

            if ($response->transportError !== null) {
                return [
                    'status' => 'failed',
                    'response_code' => $response->statusCode ?: 0,
                    'error_excerpt' => 'TaskConnect transport error: '.mb_substr($response->transportError, 0, 300),
                ];
            }

            $isSuccess = $response->statusCode >= 200 && $response->statusCode < 300;

            return [
                'status' => $isSuccess ? 'sent' : 'failed',
                'response_code' => $response->statusCode,
                'error_excerpt' => $isSuccess ? null : "TaskConnect HTTP {$response->statusCode}: ".mb_substr($response->bodyTruncated, 0, 300),
            ];
        } catch (OutboundPolicyViolation $violation) {
            return [
                'status' => 'failed',
                'response_code' => 403,
                'error_excerpt' => 'Outbound policy violation: '.$violation->getMessage(),
            ];
        } catch (Throwable $e) {
            return [
                'status' => 'failed',
                'response_code' => 500,
                'error_excerpt' => 'TaskConnect error: '.mb_substr($e->getMessage(), 0, 300),
            ];
        }
    }
}
