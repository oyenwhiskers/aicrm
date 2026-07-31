# Phase 1 Worker Runbook

## Goal

Run enough queue workers to prevent document batches from becoming fully serial, while still respecting the shared Gemini concurrency cap.

## Queue names

- intake queue: `config('queue.workloads.intake')`, default `intake`
- document queue: `config('queue.workloads.documents')`, default `documents`

## Sizing guidance

Start with a fixed worker count, not autoscaling.

- shared Gemini cap = `N`
- recommended document workers = `N` to `N + 1`

Examples:

- if `GEMINI_GLOBAL_CONCURRENCY=2`, start with `2` to `3` document workers
- if `GEMINI_GLOBAL_CONCURRENCY=3`, start with `3` to `4` document workers

Do not increase worker count blindly. More workers do not increase Gemini throughput once the shared cap is already saturated.

## Development commands

Current repo `composer dev` runs one generic queue listener:

```bash
php artisan queue:listen --tries=1 --timeout=0
```

For Phase 1 document/intake verification, prefer dedicated queue workers in separate terminals:

```bash
php artisan queue:work database --queue=documents --tries=1 --timeout=0
php artisan queue:work database --queue=intake --tries=1 --timeout=0
```

To simulate higher document parallelism locally, run multiple document workers:

```bash
php artisan queue:work database --queue=documents --tries=1 --timeout=0
php artisan queue:work database --queue=documents --tries=1 --timeout=0
php artisan queue:work database --queue=documents --tries=1 --timeout=0
```

## Production recommendation

- run dedicated workers for `documents`
- run dedicated workers for `intake`
- size both pools so they keep the shared Gemini slots busy without excessive idle contention
- use the shared Gemini cap as the hard upper bound for simultaneous Gemini calls, not worker count

## What to watch after rollout

- document queue wait time
- Gemini slot requeue frequency
- extraction duration
- batch completion time
- rate-limit and retry frequency

## Note on document retries

`ProcessLeadDocumentJob` now carries its own backpressure retry window for Gemini slot contention.

- `release()` calls caused by a full Gemini gate still count as queue attempts in Laravel
- the job therefore overrides the default low worker retry limit with a larger job-level retry budget
- if the retry window is eventually exhausted, the document is marked `failed` explicitly instead of remaining misleadingly `queued`
