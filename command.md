# Steady-State Worker Setup

## Intake Workers
Run these in separate terminals. Three to four intake workers is fine now because Gemini concurrency is capped separately.

php artisan queue:work database --queue=intake --timeout=240 --sleep=1 --tries=1
php artisan queue:work database --queue=intake --timeout=240 --sleep=1 --tries=1

Optional additional intake workers after observing stability:

php artisan queue:work database --queue=intake --timeout=240 --sleep=1 --tries=1
php artisan queue:work database --queue=intake --timeout=240 --sleep=1 --tries=1

## Document Workers
Run this separately so document jobs are isolated from intake jobs.

php artisan queue:work database --queue=documents --timeout=240 --sleep=1 --tries=1

## Current Safe Timing

- DB_QUEUE_RETRY_AFTER=300
- worker timeout=240
- tries=1

## Current Gemini Intake Control

- GEMINI_INTAKE_GLOBAL_CONCURRENCY=2
- GEMINI_INTAKE_PER_BATCH_CONCURRENCY=2
- adaptive slowdown can temporarily reduce effective global Gemini concurrency to 1 when recent 429 or 503 errors rise
- worker count is not the Gemini concurrency control

## Redis Cutover Checklist
Do not switch QUEUE_CONNECTION=redis until all of these are true:

1. PHP Redis support exists on this machine:
	- either the redis extension is loaded
	- or a Redis PHP client package is installed and configured
2. A Redis server is reachable on the configured host and port.
3. Then switch the queue backend and run workers like:

php artisan queue:work redis --queue=intake --timeout=240 --sleep=1 --tries=1
php artisan queue:work redis --queue=intake --timeout=240 --sleep=1 --tries=1
php artisan queue:work redis --queue=documents --timeout=240 --sleep=1 --tries=1

