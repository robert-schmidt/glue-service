<?php
declare(strict_types=1);

/**
 * Minimal OpenTelemetry client: spans + logs over OTLP/HTTP JSON, W3C traceparent in and out.
 * Disabled (no-op) when OTEL_EXPORTER_OTLP_ENDPOINT is empty. Telemetry failures never reach business code.
 * ponytail: hand-rolled to stay dependency-free; switch to open-telemetry/sdk when you need metrics, sampling or batching.
 */
final class Otel
{
    public const SERVER = 2, CLIENT = 3, CONSUMER = 5;
    private const SEVERITY = ['info' => 9, 'warn' => 13, 'error' => 17];

    private static array $stack = [], $spans = [], $logs = [];

    public static function enabled(): bool
    {
        return (getenv('OTEL_EXPORTER_OTLP_ENDPOINT') ?: '') !== '';
    }

    /** Opens a span; child of the current span, or of $traceparent (incoming header / stored with a job). */
    public static function start(string $name, int $kind, array $attrs = [], ?string $traceparent = null): void
    {
        $parent = end(self::$stack) ?: null;
        if ($parent === null && $traceparent !== null && preg_match('/^00-([0-9a-f]{32})-([0-9a-f]{16})-[0-9a-f]{2}$/', $traceparent, $m)) {
            $parent = ['traceId' => $m[1], 'spanId' => $m[2]];
        }
        self::$stack[] = [
            'traceId' => $parent['traceId'] ?? bin2hex(random_bytes(16)),
            'spanId' => bin2hex(random_bytes(8)),
            'parentSpanId' => $parent['spanId'] ?? '',
            'name' => $name,
            'kind' => $kind,
            'startTimeUnixNano' => self::now(),
            'attributes' => $attrs,
        ];
    }

    public static function end(array $attrs = [], ?Throwable $error = null): void
    {
        $span = array_pop(self::$stack);
        if ($span === null || !self::enabled()) return;
        $span['endTimeUnixNano'] = self::now();
        $span['attributes'] = self::attrs([...$span['attributes'], ...$attrs]);
        $span['status'] = $error ? ['code' => 2, 'message' => $error->getMessage()] : ['code' => 0];
        self::$spans[] = $span;
    }

    /** Header value for outgoing calls, so the next service continues the same trace. */
    public static function traceparent(): ?string
    {
        $s = end(self::$stack);
        return $s ? "00-{$s['traceId']}-{$s['spanId']}-01" : null;
    }

    public static function log(string $level, string $message): void
    {
        if (!self::enabled()) return;
        $s = end(self::$stack) ?: [];
        self::$logs[] = [
            'timeUnixNano' => self::now(),
            'severityNumber' => self::SEVERITY[$level] ?? 9,
            'severityText' => strtoupper($level),
            'body' => ['stringValue' => $message],
            'traceId' => $s['traceId'] ?? '',
            'spanId' => $s['spanId'] ?? '',
        ];
    }

    public static function flush(): void
    {
        if (!self::enabled() || (!self::$spans && !self::$logs)) return;
        $resource = ['attributes' => self::attrs(['service.name' => getenv('OTEL_SERVICE_NAME') ?: 'glue', 'service.version' => '1.0'])];
        $scope = ['name' => 'glue'];
        if (self::$spans) {
            self::post('/v1/traces', ['resourceSpans' => [['resource' => $resource, 'scopeSpans' => [['scope' => $scope, 'spans' => self::$spans]]]]]);
        }
        if (self::$logs) {
            self::post('/v1/logs', ['resourceLogs' => [['resource' => $resource, 'scopeLogs' => [['scope' => $scope, 'logRecords' => self::$logs]]]]]);
        }
        self::$spans = self::$logs = [];
    }

    private static function post(string $path, array $body): void
    {
        $ch = curl_init(rtrim(getenv('OTEL_EXPORTER_OTLP_ENDPOINT'), '/') . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => 1000,
        ]);
        curl_exec($ch); // best effort: a missing collector must not slow down or break syncing
    }

    private static function attrs(array $kv): array
    {
        $out = [];
        foreach ($kv as $k => $v) {
            if ($v === null) continue;
            $out[] = ['key' => $k, 'value' => is_int($v) ? ['intValue' => $v] : ['stringValue' => (string) $v]];
        }
        return $out;
    }

    private static function now(): string
    {
        return sprintf('%.0f', microtime(true) * 1e9);
    }
}
