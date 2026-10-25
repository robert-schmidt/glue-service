<?php
declare(strict_types=1);

require __DIR__ . '/../src/sync.php';

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

if (!in_array($path, ['/', '/health'], true)) { // the auto-refreshing dashboard would only add noise
    Otel::start("$method " . preg_replace('#/[A-Z]+-\d+#', '/{id}', $path), Otel::SERVER,
        ['http.request.method' => $method, 'url.path' => $path], $_SERVER['HTTP_TRACEPARENT'] ?? null);
    register_shutdown_function(function (): void {
        $status = http_response_code() ?: 200;
        Otel::end(['http.response.status_code' => $status], $status >= 500 ? new RuntimeException("HTTP $status") : null);
        Otel::flush();
    });
}

try {
    match (true) {
        str_starts_with($path, '/erp/') => proxy($method, substr($path, 4)),
        $path === '/' => dashboard(),
        $path === '/health' => json(['ok' => true]),
        $method !== 'POST' => json(['error' => 'not found'], 404),
        $path === '/reconcile' => back(enqueue('reconcile', 'all')),
        $path === '/chaos' => back(setChaos($_POST)),
        preg_match('#^/jobs/(\d+)/(retry|discard)$#', $path, $m) === 1 => back(jobAction((int) $m[1], $m[2])),
        preg_match('#^/conflicts/(\d+)/resolve$#', $path, $m) === 1 => back(resolve((int) $m[1], (string) ($_POST['value'] ?? ''))),
        default => json(['error' => 'not found'], 404),
    };
} catch (Throwable $e) {
    json(['error' => $e->getMessage()], 500);
}

// ---------------------------------------------------------------- the WMS's view of "the ERP"

/**
 * Reads pass straight through (with a quick retry). Writes are taken over: tried inline, and if the ERP
 * is down they are queued and the WMS gets a 202, so it never has to fail or retry by hand.
 */
function proxy(string $method, string $path): void
{
    budget(INLINE_BUDGET);
    $raw = file_get_contents('php://input');
    $in = $raw === '' ? [] : json_decode($raw, true);
    if (!is_array($in)) {
        json(['error' => 'body must be a JSON object'], 400);
        return;
    }

    if ($method === 'PUT' && preg_match('#^/api/customers/([^/]+)$#', $path, $m)) {
        $code = rawurldecode($m[1]);
        // the WMS sends a full record; the merge works out which fields it actually changed
        writeThrough('customer.sync', $code, ['code' => $code, 'wms' => canon('customer', 'erp', ['CUST_NO' => $code, ...$in])]);
    } elseif ($method === 'PUT' && preg_match('#^/api/products/([^/]+)/stock$#', $path, $m)) {
        $qty = filter_var($in['QTY'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $qty === false
            ? json(['error' => 'QTY must be an integer >= 0'], 400)
            : writeThrough('erp.stock', rawurldecode($m[1]), ['sku' => rawurldecode($m[1]), 'qty' => $qty]);
    } elseif ($method === 'POST' && preg_match('#^/api/orders/([^/]+)/ship$#', $path, $m)) {
        $tracking = trim((string) ($in['TRACKING_NO'] ?? ''));
        $tracking === ''
            ? json(['error' => 'TRACKING_NO is required'], 400)
            : writeThrough('erp.ship', rawurldecode($m[1]), ['orderNo' => rawurldecode($m[1]), 'trackingNo' => $tracking]);
    } else {
        forward($method, $path . (isset($_SERVER['QUERY_STRING']) ? "?{$_SERVER['QUERY_STRING']}" : ''), $in);
    }
}

function writeThrough(string $type, string $code, array $payload): void
{
    // an older write for the same record is still queued: go behind it, or we would overtake it
    if (hasPending($type, $code)) {
        enqueue($type, $code, $payload);
        json(['queued' => true], 202);
        return;
    }
    try {
        handle($type, $payload);
        json(['ok' => true]);
    } catch (Permanent $e) {
        json(['error' => $e->getMessage()], $e->getCode() ?: 422); // real business error: the WMS user should see it
    } catch (Throwable $e) {
        enqueue($type, $code, $payload);
        audit("$type $code queued, ERP not reachable: {$e->getMessage()}", 'warn');
        json(['queued' => true], 202);
    }
}

function forward(string $method, string $path, array $in): void
{
    for ($try = 1; ; $try++) {
        try {
            [$code, $data] = http($method, ERP . $path, $in ?: null, accept: range(400, 499), timeout: 10);
            json($data, $code);
            return;
        } catch (Transient $e) {
            if ($method !== 'GET' || $try === 3) {
                json(['error' => $e->getMessage()], 502);
                return;
            }
            usleep(200_000 * $try);
        }
    }
}

// ---------------------------------------------------------------- dashboard actions

function jobAction(int $id, string $action): void
{
    $action === 'retry'
        ? q("UPDATE jobs SET " . REQUEUE . ", attempts = 0, run_at = now(), updated_at = now() WHERE id = ? AND status = 'dead'", [$id])
        : q("UPDATE jobs SET status = 'discarded', updated_at = now() WHERE id = ? AND status = 'dead'", [$id]);
    audit("operator: $action job #$id");
}

function resolve(int $id, string $value): void
{
    if (trim($value) === '') {
        throw new InvalidArgumentException('value is required');
    }
    enqueue('conflict.resolve', (string) $id, ['id' => $id, 'value' => trim($value)]);
}

function setChaos(array $in): void
{
    $base = ['erp' => ERP, 'wms' => WMS][$in['target'] ?? ''] ?? throw new InvalidArgumentException('target must be erp or wms');
    http('POST', "$base/admin/chaos", [
        'failRate' => max(0, min(1, (float) ($in['failRate'] ?? 0))),
        'latencyMs' => max(0, min(10_000, (int) ($in['latencyMs'] ?? 0))),
    ]);
}

function dashboard(): void
{
    $counts = q("SELECT status, count(*) FROM jobs GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
    $conflicts = q("SELECT * FROM conflicts WHERE status = 'open' ORDER BY id")->fetchAll();
    $dead = q("SELECT * FROM jobs WHERE status = 'dead' ORDER BY id DESC LIMIT 20")->fetchAll();
    $jobs = q("SELECT * FROM jobs WHERE type <> 'reconcile' OR status <> 'done' ORDER BY updated_at DESC LIMIT 15")->fetchAll();
    $log = q("SELECT * FROM audit ORDER BY id DESC LIMIT 25")->fetchAll();
    $chaos = $compare = [];
    foreach (['erp' => ERP, 'wms' => WMS] as $sys => $url) {
        try { $chaos[$sys] = http('GET', "$url/admin/chaos", timeout: 2)[1]; } catch (Throwable) { $chaos[$sys] = null; }
    }
    foreach (array_keys(MAP) as $entity) {
        try { $compare[$entity] = [fetch($entity, 'erp'), fetch($entity, 'wms')]; } catch (Throwable $e) { $compare[$entity] = $e->getMessage(); }
    }
    header('Content-Type: text/html; charset=utf-8');
    require __DIR__ . '/../src/dashboard.phtml';
}

// ---------------------------------------------------------------- helpers

function json(mixed $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function back(mixed $ignored = null): void
{
    header('Location: /', true, 303);
}

function h(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES);
}
