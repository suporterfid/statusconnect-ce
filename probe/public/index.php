<?php

declare(strict_types=1);

// StatusConnect remote probe front controller (#128). See ../README.md.

require dirname(__DIR__).'/src/Probe.php';

$setting = static function (string $name): string {
    foreach ([getenv($name), $_SERVER[$name] ?? null, $_SERVER['REDIRECT_'.$name] ?? null] as $value) {
        if (is_string($value) && $value !== '') {
            return $value;
        }
    }
    static $file = null;
    if ($file === null) {
        $path = dirname(__DIR__).'/config.php';
        $file = is_file($path) ? (array) (require $path) : [];
    }

    return is_string($file[$name] ?? null) ? $file[$name] : '';
};

$headers = [];
foreach ($_SERVER as $key => $value) {
    if (is_string($value) && str_starts_with($key, 'HTTP_')) {
        $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
    }
}

$probe = new StatusConnectProbe(
    $setting('PROBE_SECRET'),
    $setting('PROBE_LOCATION') ?: 'unknown',
    maxDelayMs: max(0, min(10000, (int) $setting('PROBE_DELAY_MAX_MS'))),
);
$response = $probe->handle(
    (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
    (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/'),
    $headers,
    (string) file_get_contents('php://input', false, null, 0, StatusConnectProbe::MAX_REQUEST_BYTES + 1),
);

http_response_code($response['status']);
header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
echo json_encode($response['body'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
