<?php

namespace App\Domain\Notifications\Transports;

use App\Domain\Notifications\ValueObjects\WebhookSignature;
use App\Domain\Outbound\EgressProfile;
use App\Domain\Outbound\OutboundPolicy;
use App\Domain\Outbound\OutboundPolicyViolation;
use App\Domain\Outbound\ValidatedEndpoint;
use App\Domain\Shared\Clock;
use App\Infrastructure\HttpClient\GuzzlePinnedHttpTransport;
use App\Infrastructure\HttpClient\PinnedHttpRequest;
use App\Infrastructure\Persistence\Eloquent\NotificationChannel;
use Throwable;

final class WebhookNotificationTransport implements NotificationTransportInterface
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
        $url = $config['url'] ?? $config['webhook_url'] ?? null;
        $secret = $config['secret'] ?? $config['signing_secret'] ?? '';

        if (! is_string($url) || trim($url) === '') {
            return [
                'status' => 'failed',
                'response_code' => 400,
                'error_excerpt' => 'No valid webhook URL configured.',
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

            $now = $this->clock->nowUtc()->getTimestamp();
            $jsonBody = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
            $signatureHeader = WebhookSignature::create($jsonBody, (string) $secret, $now);

            $headers = [
                'Content-Type' => 'application/json',
                'User-Agent' => 'StatusConnect-Webhook/1.0',
                WebhookSignature::HEADER_NAME => $signatureHeader,
            ];

            if (isset($config['custom_headers']) && is_array($config['custom_headers'])) {
                foreach ($config['custom_headers'] as $headerName => $headerVal) {
                    if (is_string($headerName) && is_string($headerVal)) {
                        $headers[$headerName] = $headerVal;
                    }
                }
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
                    'error_excerpt' => mb_substr($response->transportError, 0, 500),
                ];
            }

            $isSuccess = $response->statusCode >= 200 && $response->statusCode < 300;

            return [
                'status' => $isSuccess ? 'sent' : 'failed',
                'response_code' => $response->statusCode,
                'error_excerpt' => $isSuccess ? null : "HTTP {$response->statusCode}: ".mb_substr($response->bodyTruncated, 0, 300),
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
                'error_excerpt' => mb_substr($e->getMessage(), 0, 500),
            ];
        }
    }
}
