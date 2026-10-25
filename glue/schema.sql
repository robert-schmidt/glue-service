-- Durable work queue. Every write the glue makes to either system is a job: retried, backed off, dead-lettered.
CREATE TABLE jobs (
    id         bigserial PRIMARY KEY,
    type       text NOT NULL,
    dedupe_key text NOT NULL,                       -- "<type>:<code>", jobs with the same key run in order
    payload    jsonb NOT NULL DEFAULT '{}',
    status     text NOT NULL DEFAULT 'pending'
               CHECK (status IN ('pending', 'running', 'done', 'dead', 'superseded', 'discarded')),
    attempts   int NOT NULL DEFAULT 0,
    run_at     timestamptz NOT NULL DEFAULT now(),
    locked_at  timestamptz,
    last_error text,
    traceparent text,                               -- W3C trace context of the request that created the job
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);
-- at most one pending job per key: a newer write coalesces into it (latest value wins, no pile-up)
CREATE UNIQUE INDEX jobs_one_pending ON jobs (dedupe_key) WHERE status = 'pending';
CREATE INDEX jobs_ready ON jobs (run_at) WHERE status = 'pending';

-- Last state both systems agreed on, per record: the "base" of the three-way merge.
CREATE TABLE snapshots (
    entity     text NOT NULL,
    code       text NOT NULL,
    data       jsonb NOT NULL,
    updated_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (entity, code)
);

-- Both sides changed the same shared field differently: a human decides.
CREATE TABLE conflicts (
    id          bigserial PRIMARY KEY,
    entity      text NOT NULL,
    code        text NOT NULL,
    field       text NOT NULL,
    base_value  text,
    erp_value   text,
    wms_value   text,
    status      text NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'resolved')),
    resolution  text,
    created_at  timestamptz NOT NULL DEFAULT now(),
    resolved_at timestamptz
);
CREATE UNIQUE INDEX conflicts_one_open ON conflicts (entity, code, field) WHERE status = 'open';

CREATE TABLE audit (
    id      bigserial PRIMARY KEY,
    at      timestamptz NOT NULL DEFAULT now(),
    level   text NOT NULL DEFAULT 'info',
    message text NOT NULL
);
