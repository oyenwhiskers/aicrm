# Phase 1 Worker Runbook

## Goal

Run enough queue workers to prevent document batches from becoming fully serial, while keeping Laravel workers lightweight and letting Python handle heavy document processing.

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

Do not increase worker count blindly. More workers do not increase Gemini throughput once the shared cap is already saturated, and they should not be used to compensate for heavy PHP-side document processing.

## Shared storage requirement

The Python-primary document pipeline now assumes Laravel and Python can both access the same stored document files.

- Laravel stores documents on the `public` disk by default
- Python must have the same storage mounted or accessible at its configured shared-storage root
- Laravel passes `storage_disk` and `storage_path`
- Python opens the file directly from shared storage

Recommended mapping:

- Laravel `public` disk root: `storage/app/public`
- Python shared root: same mounted directory path in the Python runtime

Do not use `public_url` as the processing source. It is UI metadata only.

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
- size Laravel worker pools for orchestration throughput, not for heavy image/PDF processing
- scale the Python service separately for actual document-processing capacity
- keep the shared Gemini cap as the hard upper bound for simultaneous Gemini fallback calls, not worker count
- ensure the Python service can read the same shared storage used by Laravel document uploads

## What to watch after rollout

- document queue wait time
- Laravel worker memory usage
- Python service CPU and memory usage
- Gemini slot requeue frequency
- extraction duration
- batch completion time
- rate-limit and retry frequency

## Note on document retries

`ProcessLeadDocumentJob` now carries its own backpressure retry window for Gemini slot contention.

- `release()` calls caused by a full Gemini gate still count as queue attempts in Laravel
- the job therefore overrides the default low worker retry limit with a larger job-level retry budget
- if the retry window is eventually exhausted, the document is marked `failed` explicitly instead of remaining misleadingly `queued`

## Scaling guidance in simple terms

- adding more Laravel document workers increases queue orchestration parallelism
- adding more Python service processes increases document-processing capacity
- do not treat Python as a queue worker; it is an HTTP processing service
- if Laravel workers stay light, adding workers becomes safer under concurrent uploads
