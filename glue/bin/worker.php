<?php
declare(strict_types=1);

require __DIR__ . '/../src/sync.php';

// ponytail: one worker process. claim() uses SKIP LOCKED + per-key ordering, so `--scale glue-worker=N` is safe for jobs;
// concurrent reconcile sweeps would need an advisory lock first.
// Crash handling = let it crash: Docker restarts the container and recoverStale() hands back the abandoned job.
$every = (int) (getenv('RECONCILE_EVERY') ?: 30);
$next = 0;
say("glue worker up: reconcile every {$every}s, max " . MAX_ATTEMPTS . " attempts");

while (true) {
    Otel::flush();
    recoverStale();
    if (time() >= $next) {
        enqueue('reconcile', 'all');
        $next = time() + $every;
    }
    $job = claim();
    if ($job === null) {
        usleep(500_000);
        continue;
    }
    Otel::start("job {$job['type']}", Otel::CONSUMER,
        ['job.id' => (int) $job['id'], 'job.key' => $job['dedupe_key'], 'job.attempt' => (int) $job['attempts']], $job['traceparent']);
    $error = null;
    try {
        handle($job['type'], json_decode($job['payload'], true));
    } catch (Throwable $e) {
        $error = $e;
    }
    finish($job, $error);
    Otel::end([], $error);
}
