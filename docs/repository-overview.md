# Repository Overview

## Scope of this document

This overview is based on the current implementation in the repository, not on the intended future-state described in planning notes.

Primary evidence sources used for this document:

- Laravel bootstrap and route files
- controllers, services, jobs, models, enums, and request validators under `app/`
- database migrations and seeders
- frontend code in `resources/js/app.js` and `resources/views/crm.blade.php`
- configuration in `config/`

Secondary sources:

- `process.md`
- `bankmatch.md`
- tests under `tests/`

Where planning notes or tests differ from implementation, this document follows the implementation.

## System architecture

The repository is a single Laravel application with a custom browser-side CRM UI and two main backend workflows:

1. Lead workspace workflow
   - lead creation/import
   - document upload
   - background document extraction
   - completeness tracking
   - calculation
   - bank matching

2. Intake workflow
   - multi-image lead intake upload
   - background per-image extraction
   - batch aggregation and deduplication
   - row review and later import into leads

The application is structured as a monolith with clear internal layers:

- presentation layer:
  - Blade shell in `resources/views/crm.blade.php`
  - one large vanilla JavaScript frontend in `resources/js/app.js`
- HTTP API layer:
  - route definitions in `routes/api.php`
  - workflow-oriented controllers in `app/Http/Controllers/Api`
- business logic layer:
  - services in `app/Services`
- async processing layer:
  - queued jobs in `app/Jobs`
- persistence layer:
  - Eloquent models in `app/Models`
  - relational schema in `database/migrations`
- external integration layer:
  - Gemini HTTP client logic in `GeminiExtractionService`

This is not a server-rendered multi-page app in the traditional Laravel sense. The server renders a single shell page, and nearly all CRM behavior is handled client-side through JavaScript calling JSON endpoints.

## Technology stack

- Backend framework: Laravel 13
- PHP requirement: `^8.3` in `composer.json`
- Container runtime target: PHP 8.4 in `Dockerfile`
- Frontend bundler: Vite 8
- Frontend styling: Tailwind CSS 4 with extensive custom CSS
- Frontend architecture: vanilla JavaScript, no React/Vue/etc.
- ORM: Eloquent
- Queue system: Laravel queues
- Default queue driver: `database` in `config/queue.php`
- Queue workloads: separate `intake` and `documents` queue names
- Cache default: `database` in `.env.example`
- Session driver default: `database` in `.env.example`
- Database default: `sqlite` in `config/database.php` and `.env.example`
- Supported database drivers by configuration: sqlite, mysql, mariadb, pgsql, sqlsrv
- File storage: Laravel filesystem disks
- Default filesystem disk: `local`
- Actual document/intake upload storage used by services: `public`
- AI provider integration: Google Gemini through HTTP requests
- Test framework: PHPUnit 12 with Laravel feature tests

## Major modules

### 1. Browser application shell

Files:

- `routes/web.php`
- `resources/views/crm.blade.php`
- `resources/js/app.js`
- `resources/css/app.css`

Implementation notes:

- `routes/web.php` returns the same Blade view for `/`, `/dashboard`, `/lead-intake`, `/workspace`, and `/workspace/leads/{lead}`.
- `crm.blade.php` provides a single `#app` root plus `data-*` attributes such as current page and API base.
- `app.js` contains the page boot logic, state management, rendering, event binding, polling, uploads, and API calls.

This frontend is effectively a hand-built SPA delivered through Laravel.

### 2. API layer

File:

- `routes/api.php`

Current API groups:

- lead intake:
  - `POST /lead-intake/extract-image`
  - `POST /lead-intake/batches`
  - `GET /lead-intake/batches/{batch}`
- leads:
  - list, create, import, delete, show
  - document status, preview, upload, batch upload, assignment update, delete
  - stage update
  - calculation
  - bank matching

Controllers:

- `LeadController`
- `LeadDocumentController`
- `LeadProcessingController`
- `LeadBankMatchController`
- `LeadStageController`
- `LeadCaptureController`
- `LeadIntakeBatchController`

The API is workflow-driven rather than generic CRUD.

### 3. Lead domain

Core models:

- `Lead`
- `LeadProfile`
- `LeadDocument`
- `LeadExtractedData`
- `LeadCalculationResult`
- `LeadBankMatch`
- `LeadActivityLog`
- `LeadStageHistory`

Key enums:

- `LeadStage`
- `DocumentType`
- `UploadStatus`
- `ExtractionStatus`
- `EligibilityStatus`
- `CalculationStatus`
- `MatchStatus`

This module models the lead lifecycle from raw record to calculated and matched case.

### 4. Document processing module

Key services:

- `DocumentService`
- `ExtractionService`
- `GeminiExtractionService`
- `LeadCompletenessService`
- `LeadStageService`
- `ActivityLogService`

Key jobs:

- `ProcessLeadDocumentJob`
- `DeleteLeadDocumentJob`
- `RefreshLeadDocumentStateJob`

Implementation notes:

- Uploaded documents are stored and registered immediately.
- AI extraction runs asynchronously on the `documents` queue.
- Document classification and review state are stored in document metadata.
- Extracted structured output is stored in `lead_extracted_data`.
- Lead completeness and lead stage are refreshed after processing or deletion.

### 5. Intake batch module

Key services:

- `LeadCaptureService`
- `IntakeBatchService`
- `IntakeImagePreprocessService`
- `IntakeImageExtractionService`
- `IntakeBatchAggregationService`
- `IntakeImageStageService`
- `IntakeGeminiConcurrencyService`

Key jobs:

- `ProcessIntakeBatchImageJob`
- `ProcessIntakeBatchImageAiJob`
- `AggregateIntakeBatchJob`

Key models/tables:

- `IntakeBatch`
- `IntakeBatchImage`
- `IntakeImageAttempt`
- `IntakeExtractedRow`
- `IntakeBatchNormalizedRow`

Implementation notes:

- The intake path is explicitly multi-image and queue-driven.
- Each image can be claimed by a worker, retried, and tracked through pipeline metadata.
- Aggregation deduplicates rows by `phone_number`.
- Batch status includes progress counters and performance telemetry.

### 6. Calculation module

Primary service:

- `CalculationService`

Current implementation uses:

- lead profile data
- latest payslip extraction
- request overrides

Current implementation does not model the full calculation workflow described in `process.md`. The actual code calculates a simplified prototype result:

- recognized income
- commitments
- DSR
- allowed financing amount
- installment
- payout estimate
- eligibility status

### 7. Bank matching module

Primary service:

- `BankMatchingService`

Current bank matching checks only a small rule set stored in `bank_rules`:

- accepted sectors
- minimum salary
- maximum loan amount
- maximum DSR

The broader rule matrix described in `bankmatch.md` is not implemented in the inspected service code.

### 8. Gemini integration

Primary service:

- `GeminiExtractionService`

Responsibilities:

- build Gemini endpoint URLs from config
- send requests to Gemini
- retry transient failures
- optionally fall back to a second model
- decode JSON text responses
- expose two prompt families:
  - document extraction
  - lead capture image extraction

This service is the external AI boundary for both document workflow and intake workflow.

## Request flow

### A. Page load flow

1. Browser requests a page such as `/dashboard`, `/lead-intake`, or `/workspace`.
2. Laravel returns `crm.blade.php`.
3. The view mounts Vite assets and provides page metadata in `data-*` attributes.
4. `resources/js/app.js` boots the matching page mode and starts client-side rendering and API calls.

### B. Lead creation and import flow

1. Frontend calls `POST /api/leads` or `POST /api/leads/import`.
2. Request validation is handled by `StoreLeadRequest` or `ImportLeadsRequest`.
3. `LeadService` checks duplicates by phone number and optional IC number.
4. A `Lead` is created.
5. A blank `LeadProfile` is created.
6. An initial `LeadStageHistory` row is created.
7. `ActivityLogService` writes `lead.created`.
8. The API returns transformed lead data.

### C. Document upload flow

1. Frontend uploads documents using:
   - `POST /api/leads/{lead}/documents`
   - `POST /api/leads/{lead}/documents/batch`
2. `DocumentService` stores the file on the `public` disk and creates a `lead_documents` record with `QUEUED` status.
3. The controller dispatches `ProcessLeadDocumentJob` jobs.
4. The response is `202 Accepted`, not a completed extraction result.
5. Frontend polls `GET /api/leads/{lead}/documents/status`.

### D. Background document extraction flow

1. `ProcessLeadDocumentJob` loads the document and lead.
2. It marks the document as `PROCESSING`.
3. `ExtractionService` reads the stored file and calls `GeminiExtractionService`.
4. Gemini output is normalized and written into:
   - `lead_documents.metadata`
   - `lead_extracted_data`
5. `ExtractionService` may sync specific extracted fields back into:
   - `leads.ic_number`
   - `lead_profiles.employer`
   - `lead_profiles.salary`
   - `lead_profiles.employment_type`
   - `lead_profiles.age`
6. The document status becomes `UPLOADED` or `FAILED`.
7. `RefreshLeadDocumentStateJob` recalculates completeness and lead stage.

### E. Document assignment and review flow

1. Frontend calls `PATCH /api/leads/{lead}/documents/{document}/assignment`.
2. The controller stores:
   - `manual_assignment_key`
   - `manual_review_resolved`
   - `effective_document_type`
3. `LeadCompletenessService` reassigns checklist slots.
4. `LeadStageService` syncs the lead to `DOC_REQUESTED`, `DOC_PARTIAL`, or `DOC_COMPLETE` when applicable.

### F. Calculation flow

1. Frontend calls `POST /api/leads/{lead}/calculate`.
2. `LeadProcessingController` checks that required documents are complete through `LeadCompletenessService`.
3. Lead stage transitions to `PROCESSING`.
4. `CalculationService` computes a result.
5. The result is saved in `lead_calculation_results`.
6. Lead stage transitions to:
   - `PROCESSED`
   - `NOT_ELIGIBLE`
   - `MANUAL_REVIEW`
7. API returns the calculation payload.

### G. Bank matching flow

1. Frontend calls `POST /api/leads/{lead}/match-banks`.
2. Controller verifies a calculation result exists.
3. `BankMatchingService` loads active banks with their rules.
4. The service evaluates each bank against profile plus calculation data.
5. Results are upserted into `lead_bank_matches`.
6. Lead stage transitions to:
   - `MATCHED`
   - `MANUAL_REVIEW`
   - `NOT_ELIGIBLE`

### H. Intake batch flow

1. Frontend calls `POST /api/lead-intake/batches` with multiple images.
2. `IntakeBatchService` creates:
   - one `intake_batches` row
   - one `intake_batch_images` row per uploaded image
3. Each image is stored on the `public` disk.
4. `ProcessIntakeBatchImageJob` queues preprocessing completion.
5. `ProcessIntakeBatchImageAiJob` performs worker claim, concurrency-slot acquisition, extraction, retry handling, and finalization.
6. `LeadCaptureService` calls Gemini with the lead-capture prompt.
7. Per-image rows are written to `intake_extracted_rows`.
8. `AggregateIntakeBatchJob` rebuilds `intake_batch_normalized_rows`.
9. Batch progress counters are refreshed.
10. Frontend polls `GET /api/lead-intake/batches/{batch}` until the batch reaches a terminal state.

### I. Single-image extraction endpoint

`POST /api/lead-intake/extract-image` exists and is implemented in `LeadCaptureController`, but the inspected frontend code does not call it. The current browser UI uses the asynchronous batch endpoint instead.

## Data flow

### Lead workflow data flow

1. User-entered lead data is stored in `leads`.
2. Companion applicant profile data is stored in `lead_profiles`.
3. Uploaded files are written to the filesystem and referenced by `lead_documents`.
4. Gemini extraction output is written to `lead_extracted_data.structured_fields`.
5. Selected extracted values are copied back into normalized lead/profile columns by `ExtractionService`.
6. `LeadCompletenessService` reads document metadata and maps uploaded files into required checklist slots.
7. `CalculationService` reads profile data plus latest payslip extraction and stores output in `lead_calculation_results`.
8. `BankMatchingService` reads the latest calculation result plus bank rules and stores output in `lead_bank_matches`.
9. `ActivityLogService` and `LeadStageService` persist operational history into:
   - `lead_activity_logs`
   - `lead_stage_histories`

### Intake workflow data flow

1. Uploaded intake images are stored and referenced by `intake_batch_images`.
2. Client-provided preprocessing metadata is stored in image metadata.
3. Per-image extracted rows are stored in `intake_extracted_rows`.
4. Aggregation groups rows by `phone_number`.
5. One normalized row per phone number is stored in `intake_batch_normalized_rows`.
6. The frontend reads normalized rows for operator review and later import into the lead workflow.

### Metadata-heavy behavior

Important workflow state is stored in JSON metadata rather than dedicated columns.

Examples:

- document classification
- document review flags
- effective document type
- manual assignment key
- batch preprocess metadata
- intake pipeline stage state
- image timing/performance details
- source-image aggregation details

This is a meaningful architectural trait of the current implementation.

## Schema summary

### Lead-related tables

- `leads`
- `lead_profiles`
- `lead_documents`
- `lead_extracted_data`
- `lead_calculation_results`
- `lead_bank_matches`
- `lead_activity_logs`
- `lead_stage_histories`
- `banks`
- `bank_rules`

### Intake-related tables

- `intake_batches`
- `intake_batch_images`
- `intake_image_attempts`
- `intake_extracted_rows`
- `intake_batch_normalized_rows`

### Queue/support tables from base Laravel migrations

- `jobs`
- `cache`
- `users`

## Authentication and authorization

Default Laravel auth configuration and a `User` model exist, but the inspected application routes/controllers do not apply authentication middleware.

Verified implementation details:

- `bootstrap/app.php` does not register custom API middleware
- `routes/api.php` does not wrap routes in auth middleware
- each inspected `FormRequest` returns `authorize(): true`

So the current inspected API surface is effectively unauthenticated at the application route level.

## Known implementation mismatches worth noting

These are not planning assumptions. They are cross-file inconsistencies present in the repository as inspected.

### 1. Upload slot validation references a missing enum method

- `UploadLeadDocumentRequest` calls `DocumentType::allowedUploadSlots()`
- the inspected `DocumentType` enum does not define that method

This suggests the single-document upload validator is out of sync with the enum.

### 2. Some feature tests are out of sync with the current implementation

Examples:

- `LeadDocumentController::store()` returns `202 Accepted`, but `tests/Feature/LeadWorkflowApiTest.php` asserts `201 Created`
- `LeadDocumentController::store()` queues extraction and does not return inline extraction payload, but `tests/Feature/LeadExtractionWorkflowTest.php` asserts `data.extraction.status`
- `ExtractionService` checks `services.gemini.api_key`, while `LeadExtractionWorkflowTest.php` sets `services.openai.api_key`

These tests should not be treated as a reliable source of current behavior.

## Assumptions that could not be fully verified from code

### 1. Production deployment topology

The code shows a Docker-based container build and `php artisan serve` runtime command, but it does not prove how the application is actually deployed in production.

### 2. Actual database engine in active environments

The repository defaults to SQLite and supports multiple drivers, but the active runtime database cannot be confirmed from code alone.

### 3. Queue worker scale in real environments

The code supports separate `documents` and `intake` workloads and includes concurrency controls, but the number of workers actually running is not visible from source.

### 4. Whether the single-image extraction endpoint is still used by any external client

The route and controller exist, and the bundled frontend does not use it, but source inspection alone cannot rule out external consumers.

### 5. Whether additional bank rules exist outside seed data

The repository seeds only three prototype banks, but the live contents of the database cannot be verified from source alone.

## Implementation boundaries versus planning notes

Two repository documents describe a larger intended business engine than the current code implements:

- `process.md`
- `bankmatch.md`

These are useful design references, but they are not the source of truth for current behavior.

Verified implementation boundaries:

- calculation is currently simplified and prototype-oriented
- bank matching currently checks a small set of rules
- document extraction updates a limited subset of lead/profile fields
- the browser UI currently uses async intake batches, not the synchronous intake extraction endpoint

## Summary

The repository currently implements a Laravel monolith with:

- one Blade-delivered vanilla JS CRM frontend
- a workflow-oriented JSON API
- queue-driven document and intake extraction pipelines
- prototype-level calculation and bank-matching logic
- significant use of JSON metadata for workflow state

The main architectural caution is that planning documents and some tests describe capabilities beyond, or different from, the currently inspected implementation. For architectural review and optimization work, the implementation files under `app/`, `routes/`, `resources/js/`, and `database/migrations/` should be treated as the baseline source of truth.
