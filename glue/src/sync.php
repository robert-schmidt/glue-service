<?php
declare(strict_types=1);

require __DIR__ . '/otel.php';

/** 5xx, 429, timeouts, connection refused: worth retrying. */
final class Transient extends RuntimeException {}
/** Any other 4xx: retrying will not help, a human has to look. */
final class Permanent extends RuntimeException {}

define('ERP', getenv('ERP_URL') ?: 'http://erp:8080');
define('WMS', getenv('WMS_URL') ?: 'http://wms:8080');
define('MAX_ATTEMPTS', (int) (getenv('MAX_ATTEMPTS') ?: 6));
define('INLINE_BUDGET', (float) (getenv('INLINE_BUDGET') ?: 3));

/**
 * Canonical field => each system's field name, plus who owns each field.
 * owner 'erp' / 'wms' = that side always wins; 'shared' = three-way merge, true conflicts go to a human.
 */
const MAP = [
    'customer' => [
        'erpPath' => '/api/customers',
        'wmsPath' => '/api/partners',
        'erp' => ['code' => 'CUST_NO', 'name' => 'CUST_NAME', 'email' => 'EMAIL', 'address' => 'ADDR_LINE1', 'city' => 'CITY'],
        'wms' => ['code' => 'erpRef', 'name' => 'name', 'email' => 'email', 'address' => 'shipAddress', 'city' => 'shipCity'],
        'owner' => ['name' => 'erp', 'email' => 'erp', 'address' => 'shared', 'city' => 'shared'],
    ],
    'product' => [
        'erpPath' => '/api/products',
        'wmsPath' => '/api/items',
        'erp' => ['code' => 'SKU', 'name' => 'DESCR', 'price' => 'PRICE', 'stock' => 'QTY_ON_HAND'],
        'wms' => ['code' => 'sku', 'name' => 'description', 'price' => 'unitPrice', 'stock' => 'onHand'],
        'owner' => ['name' => 'erp', 'price' => 'erp', 'stock' => 'wms'],
    ],
];

// ---------------------------------------------------------------- infrastructure

function db(): PDO
{
    static $pdo;
    return $pdo ??= new PDO(getenv('GLUE_DSN') ?: 'pgsql:host=glue-db;dbname=glue', 'glue', 'glue', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function q(string $sql, array $args = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st;
}

function audit(string $message, string $level = 'info'): void
{
    q('INSERT INTO audit (level, message) VALUES (?, ?)', [$level, $message]);
    say("[$level] $message", $level);
}

/** Log line to the container log (stderr: stdout of the web server is the HTTP response) and to the central log. */
function say(string $line, string $level = 'info'): void
{
    file_put_contents('php://stderr', date('H:i:s') . " $line\n");
    Otel::log($level, $line);
}

/**
 * Seconds left for the current request. The proxy sets it: the WMS stops waiting after 5 s, so an inline attempt has to
 * give up early enough for the 202 to reach it, or the WMS rolls back a write the glue then delivers anyway.
 */
function budget(?float $seconds = null): float
{
    static $until = INF;
    if ($seconds !== null) {
        $until = microtime(true) + $seconds;
    }
    return $until - microtime(true);
}

/**
 * JSON over HTTP with failure classification. Status codes in $accept are returned instead of thrown.
 * @return array{int, mixed}
 */
function http(string $method, string $url, ?array $body = null, array $accept = [], int $timeout = 5): array
{
    $ms = (int) (min($timeout, budget()) * 1000);
    if ($ms <= 0) {
        throw new Transient("$method $url: inline time budget used up");
    }
    $host = parse_url($url, PHP_URL_HOST);
    Otel::start("$method $host", Otel::CLIENT, ['http.request.method' => $method, 'url.full' => $url, 'server.address' => $host]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT_MS => min(2000, $ms),
        CURLOPT_TIMEOUT_MS => $ms,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'traceparent: ' . Otel::traceparent()],
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $data = $raw === false ? null : json_decode($raw, true);
    $why = "$method $url → $code " . ($data['message'] ?? substr((string) $raw, 0, 200));
    $error = match (true) {
        $raw === false => new Transient("$method $url: " . curl_error($ch)),
        $code >= 500 || $code === 429 => new Transient($why),
        $code >= 400 && !in_array($code, $accept, true) => new Permanent($why, $code),
        default => null,
    };
    Otel::end(['http.response.status_code' => $code ?: null], $error);
    if ($error) {
        throw $error;
    }
    return [$code, $data];
}

// ---------------------------------------------------------------- plugins

/**
 * Every write the glue makes to either system goes through here. Plugin hooks for "<system>.write":
 *  - transforms run before the write, in file-name order, and may change or reject the payload;
 *  - observers run after it succeeded, get the final payload and can neither change it nor fail it.
 * ctx.id comes from the payload as the glue built it, so every repeat of a write (retry, replay) has the same id.
 */
function send(string $sys, string $method, string $path, array $body, array $accept = []): array
{
    $event = "$sys.write";
    $ctx = ['event' => $event, 'system' => $sys, 'method' => $method, 'path' => $path];
    $ctx['id'] = substr(hash('sha256', json_encode([$ctx, $body])), 0, 16);
    $body = transform($event, $body, $ctx);
    $result = http($method, ($sys === 'erp' ? ERP : WMS) . $path, $body, $accept);
    notify($event, $body, [...$ctx, 'status' => $result[0]]);
    return $result;
}

/**
 * Plugins are the *.php files in PLUGINS_DIR (*.php.off = disabled), each returning
 * ['transform' => [event => fn], 'observe' => [event => fn]]. Re-read whenever a file is added, renamed or edited.
 * @return array{transform: array<string, list<array{string, callable}>>, observe: array<string, list<array{string, callable}>>}
 */
function plugins(): array
{
    static $key = null, $hooks = [];
    clearstatcache();
    $files = glob((getenv('PLUGINS_DIR') ?: __DIR__ . '/../plugins') . '/*.php') ?: [];
    $now = implode('|', array_map(fn(string $f) => $f . '@' . filemtime($f), $files));
    if ($now === $key) {
        return $hooks;
    }
    [$key, $hooks, $loaded] = [$now, ['transform' => [], 'observe' => []], []];
    foreach ($files as $file) {
        $name = basename($file, '.php');
        try {
            $plugin = require $file;
            if (!is_array($plugin) || array_diff_key($plugin, $hooks)) {
                throw new UnexpectedValueException("must return ['transform' => [event => fn], 'observe' => [event => fn]]");
            }
            foreach ($plugin as $kind => $byEvent) {
                foreach ($byEvent as $event => $fn) {
                    $hooks[$kind][$event][] = [$name, $fn];
                }
            }
            $loaded[] = $name;
        } catch (Throwable $e) {
            say("[plugin] $name not loaded: {$e->getMessage()}", 'error'); // a broken plugin never stops the glue
        }
    }
    say('[plugin] active: ' . (implode(', ', $loaded) ?: 'none'));
    return $hooks;
}

/**
 * Transforms return the payload (changed or not), or null to leave it. Throwing Permanent rejects the write;
 * any other error is logged and the payload passes on unchanged. They run again on every retry: same input, same output.
 * Logged before any transform runs and once more if one changed it (PAYLOAD_LOG: always | changed | off).
 */
function transform(string $event, array $payload, array $ctx): array
{
    $mode = getenv('PAYLOAD_LOG') ?: 'always';
    $line = fn(array $p) => "$event {$ctx['method']} {$ctx['path']} id={$ctx['id']} " . json_encode($p, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($mode === 'always') {
        say('[payload] ' . $line($payload));
    }
    $out = $payload;
    $by = [];
    foreach (plugins()['transform'][$event] ?? [] as [$name, $fn]) {
        try {
            $r = $fn($out, $ctx);
        } catch (Permanent $e) {
            say("[plugin] $name rejected {$ctx['method']} {$ctx['path']} id={$ctx['id']}: {$e->getMessage()}", 'warn');
            throw $e;
        } catch (Throwable $e) {
            say("[plugin] $name failed, payload passed on unchanged: {$e->getMessage()}", 'error');
            continue;
        }
        if (is_array($r) && $r !== $out) {
            [$out, $by[]] = [$r, $name];
        }
    }
    if ($by && $mode !== 'off') {
        if ($mode === 'changed') {
            say('[payload] ' . $line($payload));
        }
        $fields = array_filter(array_unique([...array_keys($payload), ...array_keys($out)]), fn($k) => ($payload[$k] ?? null) !== ($out[$k] ?? null));
        say('[payload altered by ' . implode(', ', $by) . '] changed ' . implode(', ', $fields) . ': ' . $line($out));
    }
    return $out;
}

/** Observers get a copy of what was written. Errors are logged and never fail the write, which already happened. */
function notify(string $event, array $payload, array $ctx): void
{
    foreach (plugins()['observe'][$event] ?? [] as [$name, $fn]) {
        try {
            $fn($payload, $ctx);
        } catch (Throwable $e) {
            say("[plugin] $name observer failed: {$e->getMessage()}", 'error');
        }
    }
}

// ---------------------------------------------------------------- job queue

/** Returns false when skipped because the same key is already dead-lettered (a human has to look first). */
function enqueue(string $type, string $code, array $payload = [], bool $skipIfDead = false): bool
{
    $key = "$type:$code";
    $guard = $skipIfDead ? "WHERE NOT EXISTS (SELECT 1 FROM jobs WHERE dedupe_key = :k2 AND status = 'dead')" : '';
    // traceparent travels with the job, so every retry shows up in the trace of the request that caused it
    $st = q("INSERT INTO jobs (type, dedupe_key, payload, traceparent) SELECT :t, :k, CAST(:p AS jsonb), :tp $guard
             ON CONFLICT (dedupe_key) WHERE status = 'pending'
             DO UPDATE SET payload = EXCLUDED.payload, traceparent = EXCLUDED.traceparent, updated_at = now()",
        ['t' => $type, 'k' => $key, 'p' => json_encode($payload), 'tp' => Otel::traceparent()] + ($skipIfDead ? ['k2' => $key] : []));
    return $st->rowCount() > 0;
}

function hasPending(string $type, string $code): bool
{
    return (bool) q("SELECT 1 FROM jobs WHERE dedupe_key = ? AND status IN ('pending', 'running')", ["$type:$code"])->fetchColumn();
}

/** Next runnable job; jobs sharing a key run strictly in order, others in parallel. */
function claim(): ?array
{
    return q("UPDATE jobs SET status = 'running', attempts = attempts + 1, locked_at = now(), updated_at = now()
              WHERE id = (
                  SELECT j.id FROM jobs j
                  WHERE j.status = 'pending' AND j.run_at <= now()
                    AND NOT EXISTS (SELECT 1 FROM jobs o WHERE o.dedupe_key = j.dedupe_key AND o.id < j.id AND o.status IN ('pending', 'running'))
                  ORDER BY j.id LIMIT 1 FOR UPDATE SKIP LOCKED)
              RETURNING *")->fetch() ?: null;
}

/** Back to pending, unless a newer pending job for the same key already carries a fresher payload. */
const REQUEUE = "status = CASE WHEN EXISTS (SELECT 1 FROM jobs s WHERE s.dedupe_key = jobs.dedupe_key AND s.status = 'pending' AND s.id <> jobs.id)
                          THEN 'superseded' ELSE 'pending' END";

function finish(array $job, ?Throwable $e = null): void
{
    $tag = "job #{$job['id']} {$job['dedupe_key']}";
    if ($e === null) {
        q("UPDATE jobs SET status = 'done', last_error = NULL, locked_at = NULL, updated_at = now() WHERE id = ?", [$job['id']]);
        say("[ok] $tag (attempt {$job['attempts']})");
        return;
    }
    if ($e instanceof Permanent || $job['attempts'] >= MAX_ATTEMPTS) {
        // a failed sweep is not a dead letter: the next scheduled sweep replaces it
        $status = $job['type'] === 'reconcile' ? 'superseded' : 'dead';
        q("UPDATE jobs SET status = ?, last_error = ?, locked_at = NULL, updated_at = now() WHERE id = ?", [$status, $e->getMessage(), $job['id']]);
        audit("$tag gave up after {$job['attempts']} attempt(s), now $status: {$e->getMessage()}", 'error');
        return;
    }
    // exponential backoff with jitter: ~2s, 4s, 8s ... capped at 60s
    $delay = min(2 ** $job['attempts'], 60) + random_int(0, 1000) / 1000;
    q("UPDATE jobs SET " . REQUEUE . ", last_error = ?, locked_at = NULL, run_at = now() + make_interval(secs => ?), updated_at = now() WHERE id = ?",
        [$e->getMessage(), $delay, $job['id']]);
    say("[retry] $tag in " . round($delay, 1) . "s: {$e->getMessage()}", 'warn');
}

/** A worker that died mid-job leaves it 'running'; hand it back. Every handler is idempotent, so re-running is safe. */
function recoverStale(): void
{
    $n = q("UPDATE jobs SET " . REQUEUE . ", locked_at = NULL, updated_at = now()
            WHERE status = 'running' AND locked_at < now() - interval '2 minutes'")->rowCount();
    if ($n > 0) {
        audit("recovered $n job(s) abandoned by a crashed worker", 'warn');
    }
}

function handle(string $type, array $p): void
{
    match ($type) {
        'erp.ship' => send('erp', 'POST', '/api/orders/' . rawurlencode($p['orderNo']) . '/ship', ['TRACKING_NO' => $p['trackingNo']], accept: [409]),
        'erp.stock' => send('erp', 'PUT', '/api/products/' . rawurlencode($p['sku']) . '/stock', ['QTY' => $p['qty']]),
        'customer.sync' => syncOne('customer', $p),
        'product.sync' => syncOne('product', $p),
        'conflict.resolve' => resolveConflict($p['id'], $p['value']),
        'reconcile' => reconcile(),
        default => throw new Permanent("unknown job type $type"),
    };
}

// ---------------------------------------------------------------- mapping & merge

function norm(mixed $v): ?string
{
    // 12.50 (MySQL) and 12.5 (Postgres) are the same price
    return $v === null ? null : (is_numeric($v) ? (string) ($v + 0) : trim((string) $v));
}

/** system record → canonical */
function canon(string $entity, string $sys, array $rec): array
{
    return array_map(fn(string $f) => norm($rec[$f] ?? null), MAP[$entity][$sys]);
}

/** canonical (partial) → system record */
function native(string $entity, string $sys, array $canon): array
{
    $out = [];
    foreach (MAP[$entity][$sys] as $c => $f) {
        if (array_key_exists($c, $canon)) {
            $out[$f] = $canon[$c];
        }
    }
    return $out;
}

/** One record, or all records keyed by code, from either system, in canonical form. */
function fetch(string $entity, string $sys, ?string $code = null): array
{
    $url = ($sys === 'erp' ? ERP : WMS) . MAP[$entity]["{$sys}Path"] . ($code === null ? '' : '/' . rawurlencode($code));
    [, $data] = http('GET', $url);
    if ($code !== null) {
        return canon($entity, $sys, $data);
    }
    return $data
        |> (fn(array $rows) => array_map(fn(array $r) => canon($entity, $sys, $r), $rows))
        |> (fn(array $rows) => array_column($rows, null, 'code'));
}

/**
 * Field-level three-way merge. $base = the last values both systems agreed on.
 * Returns the merged record, the patch for each side and the unresolvable conflicts [base, erp, wms].
 */
function merge(array $base, array $erp, array $wms, array $owners): array
{
    $r = ['merged' => [], 'erp' => [], 'wms' => [], 'conflicts' => []];
    foreach ($owners as $f => $owner) {
        [$b, $e, $w] = [$base[$f] ?? $erp[$f], $erp[$f], $wms[$f]];
        $winner = match (true) {
            $e === $w => 'erp',              // in sync
            $owner !== 'shared' => $owner,   // owner wins, other side gets corrected
            $w === $b => 'erp',              // only the ERP changed it
            $e === $b => 'wms',              // only the WMS changed it
            default => 'conflict',           // both changed it, differently
        };
        if ($winner === 'conflict') {
            $r['conflicts'][$f] = [$b, $e, $w];
            $r['merged'][$f] = $b;           // base stays until a human decides
            continue;
        }
        $v = $winner === 'wms' ? $w : $e;
        $r['merged'][$f] = $v;
        if ($e !== $v) $r['erp'][$f] = $v;
        if ($w !== $v) $r['wms'][$f] = $v;
    }
    return $r;
}

/** The WMS sends current values, never deltas: a message that no longer matches its own record is a replay or arrived late. */
function isCurrent(array $msg, array $current): bool
{
    return array_diff_assoc(array_filter($msg, fn($v) => $v !== null), $current) === [];
}

// ---------------------------------------------------------------- sync

function snapshot(string $entity, string $code): ?array
{
    $json = q('SELECT data FROM snapshots WHERE entity = ? AND code = ?', [$entity, $code])->fetchColumn();
    return $json === false ? null : json_decode($json, true);
}

function saveSnapshot(string $entity, string $code, array $data): void
{
    q('INSERT INTO snapshots (entity, code, data) VALUES (?, ?, CAST(? AS jsonb))
       ON CONFLICT (entity, code) DO UPDATE SET data = EXCLUDED.data, updated_at = now()', [$entity, $code, json_encode($data)]);
}

function erpWrite(string $entity, string $code, array $current, array $patch): void
{
    if ($entity === 'customer') {
        // the ERP only takes full records: current ERP state + our patch, so nothing else gets clobbered
        send('erp', 'PUT', '/api/customers/' . rawurlencode($code), native('customer', 'erp', [...$current, ...$patch]));
    } elseif (isset($patch['stock'])) {
        // stock is the only product field outside systems may post to the ERP
        send('erp', 'PUT', '/api/products/' . rawurlencode($code) . '/stock', ['QTY' => (int) $patch['stock']]);
    }
}

function wmsWrite(string $entity, string $code, array $patch): void
{
    send('wms', 'PUT', MAP[$entity]['wmsPath'] . '/' . rawurlencode($code), native($entity, 'wms', $patch));
}

/** Merge one record and push the result to both sides. Idempotent: running it twice changes nothing the second time. */
function syncRecord(string $entity, string $code, array $erp, array $wms): void
{
    $m = merge(snapshot($entity, $code) ?? $erp, $erp, $wms, MAP[$entity]['owner']); // first sight: ERP is the system of record
    if ($m['erp']) erpWrite($entity, $code, $erp, $m['erp']);
    if ($m['wms']) wmsWrite($entity, $code, $m['wms']);
    foreach ($m['conflicts'] as $field => [$b, $e, $w]) {
        $new = q("INSERT INTO conflicts (entity, code, field, base_value, erp_value, wms_value) VALUES (?, ?, ?, ?, ?, ?)
                  ON CONFLICT (entity, code, field) WHERE status = 'open' DO UPDATE SET erp_value = EXCLUDED.erp_value, wms_value = EXCLUDED.wms_value
                  RETURNING (xmax = 0) AS inserted", [$entity, $code, $field, $b, $e, $w])->fetchColumn();
        if ($new) audit("CONFLICT $entity $code.$field: ERP '$e' vs WMS '$w' (was '$b')", 'warn');
    }
    saveSnapshot($entity, $code, $m['merged']);
    if ($m['erp']) audit("$entity $code → ERP " . json_encode($m['erp'], JSON_UNESCAPED_UNICODE));
    if ($m['wms']) audit("$entity $code → WMS " . json_encode($m['wms'], JSON_UNESCAPED_UNICODE));
}

/** Single-record job. $p['wms'] carries values captured by the proxy; otherwise read the WMS. */
function syncOne(string $entity, array $p): void
{
    $erp = fetch($entity, 'erp', $p['code']);
    $wms = isset($p['wms']) ? array_filter($p['wms'], fn($v) => $v !== null) + $erp : fetch($entity, 'wms', $p['code']);
    syncRecord($entity, $p['code'], $erp, $wms);
}

/** Full sweep of both systems. One bad record never blocks the rest: it becomes its own retryable job. */
function reconcile(): void
{
    foreach (array_keys(MAP) as $entity) {
        $wms = fetch($entity, 'wms');
        // ponytail: full scan each run; switch to ROW_VER/updated_at cursors once tables pass ~10k rows
        foreach (fetch($entity, 'erp') as $code => $erp) {
            $code = (string) $code;
            if (!isset($wms[$code])) continue; // the WMS creates new records itself on its next ERP pull
            try {
                // the bulk read can be seconds old: a record with something to write is re-read first,
                // or a stale full record would overwrite an ERP edit made meanwhile (lost update)
                $m = merge(snapshot($entity, $code) ?? $erp, $erp, $wms[$code], MAP[$entity]['owner']);
                $m['erp'] || $m['wms'] ? syncOne($entity, ['code' => $code]) : syncRecord($entity, $code, $erp, $wms[$code]);
            } catch (Throwable $e) {
                if (enqueue("$entity.sync", $code, ['code' => $code], skipIfDead: true)) {
                    audit("$entity $code failed during reconcile, queued on its own: {$e->getMessage()}", 'warn');
                }
            }
        }
    }
    // shipments booked in the WMS whose confirmation never reached the ERP
    $status = array_column(http('GET', ERP . '/api/orders')[1], 'STATUS', 'ORDER_NO');
    foreach (http('GET', WMS . '/api/shipments')[1] as $s) {
        if (($status[$s['orderNo']] ?? null) !== 'SHIPPED'
            && enqueue('erp.ship', $s['orderNo'], ['orderNo' => $s['orderNo'], 'trackingNo' => $s['trackingNo']], skipIfDead: true)) {
            audit("shipment {$s['orderNo']} missing in ERP, re-sending");
        }
    }
}

function resolveConflict(int $id, string $value): void
{
    $c = q("SELECT * FROM conflicts WHERE id = ?", [$id])->fetch();
    if (!$c || $c['status'] !== 'open') return;
    $erp = fetch($c['entity'], 'erp', $c['code']);
    erpWrite($c['entity'], $c['code'], $erp, [$c['field'] => $value]);
    wmsWrite($c['entity'], $c['code'], [$c['field'] => $value]);
    saveSnapshot($c['entity'], $c['code'], [...(snapshot($c['entity'], $c['code']) ?? $erp), $c['field'] => $value]);
    q("UPDATE conflicts SET status = 'resolved', resolution = ?, resolved_at = now() WHERE id = ?", [$value, $id]);
    audit("conflict #$id {$c['entity']} {$c['code']}.{$c['field']} resolved to '$value'");
}
