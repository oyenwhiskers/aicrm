# LPS Refinement v2.0

## Objective

Refine the current document pipeline from Gemini-first extraction into a Python-primary local document-processing architecture, while keeping Laravel as the workflow, persistence, and business-rules authority.

Agreed rollout direction:

1. Laravel remains responsible for uploads, queues, database state, normalization, business rules, checklist assignment, and workflow control.
2. Python becomes the primary document-processing provider.
3. Python handles local PDF text extraction, OCR, image preprocessing, document classification, and common field extraction.
4. Laravel validates whether the Python result is acceptable for the workflow.
5. Unresolved Python results go to manual review during this phase.
6. Gemini automatic fallback is postponed for now, but the architecture must remain ready for it later.

## 1. Current Integration Map

### Current document flow

1. Files are uploaded through:
   - `POST /api/leads/{lead}/documents`
   - `POST /api/leads/{lead}/documents/batch`
2. [`app/Http/Controllers/Api/LeadDocumentController.php`](../app/Http/Controllers/Api/LeadDocumentController.php) stores files through [`app/Services/DocumentService.php`](../app/Services/DocumentService.php).
3. `DocumentService` creates a `lead_documents` row with:
   - initial `document_type`
   - `upload_status = queued`
   - upload metadata such as `mime_type`, queue markers, and timestamps
4. [`app/Jobs/ProcessLeadDocumentJob.php`](../app/Jobs/ProcessLeadDocumentJob.php) runs asynchronously on the `documents` queue.
5. The job currently acquires a shared Gemini slot through [`app/Services/GeminiConcurrencyService.php`](../app/Services/GeminiConcurrencyService.php).
6. The job calls [`app/Services/ExtractionService.php`](../app/Services/ExtractionService.php).
7. `ExtractionService` currently:
   - loads the document and lead profile
   - preprocesses image inputs through [`app/Services/DocumentPreprocessService.php`](../app/Services/DocumentPreprocessService.php)
   - calls [`app/Services/GeminiExtractionService.php`](../app/Services/GeminiExtractionService.php)
   - normalizes classification
   - stores extraction results into:
     - `lead_documents.document_type`
     - `lead_documents.metadata.classification`
     - `lead_documents.metadata.effective_document_type`
     - `lead_extracted_data`
8. [`app/Services/LeadCompletenessService.php`](../app/Services/LeadCompletenessService.php) uses the stored metadata to assign checklist slots and determine completeness.

### Exact current Gemini seam

The narrowest replacement point is:

- `ExtractionService::runExtractionAttempt()`

That is the current place where the prepared file payload is sent to Gemini and where the normalized response is returned back into Laravel’s persistence and checklist flow.

### Current extraction result shape

The current flow already expects a normalized logical structure like:

```json
{
  "summary": "short summary",
  "confidence": "high|medium|low",
  "needs_review": true,
  "classification": {
    "document_type": "ic|payslip|pension_slip|epf|ramci|ctos|other",
    "ic_side": "front|back|null",
    "statement_year": null,
    "statement_month": null,
    "statement_period": null
  },
  "fields": {
    "full_name": null,
    "ic_number": null,
    "date_of_birth": null,
    "address": null,
    "employer": null,
    "employment_type": null,
    "basic_salary": null,
    "gross_income": null,
    "net_pay": null,
    "total_deductions": null
  },
  "raw_text": null
}
```

Python should target this shape so existing Laravel normalization and checklist logic can remain intact.

## 2. Target Integration Map

### Recommended architecture

Keep the existing Laravel orchestration and replace only the document-intelligence provider layer.

Target flow:

1. Upload, storage, queueing, and document status remain in Laravel.
2. `ProcessLeadDocumentJob` continues to own the async processing lifecycle.
3. `ExtractionService` remains the orchestration point.
4. Laravel calls Python as the primary provider.
5. Laravel validates the Python result against document-type acceptance rules.
6. Accepted results are persisted and continue to checklist assignment.
7. Unresolved results go to manual review in this phase.
8. Gemini is not called automatically in this phase.

### Provider abstraction

Laravel should introduce a provider abstraction so the workflow does not become hard-wired to Python.

Suggested shape:

- `DocumentIntelligenceServiceInterface`

Possible implementations:

- `PythonDocumentIntelligenceService`
- existing `GeminiExtractionService` retained for future fallback use

This keeps future Gemini fallback possible without redesigning the whole extraction pipeline again.

## 3. Files / Components Affected

### Primary affected Laravel files

- [`app/Services/ExtractionService.php`](../app/Services/ExtractionService.php)
  - main orchestration point
  - replace Gemini-only extraction with provider-based processing
- [`app/Jobs/ProcessLeadDocumentJob.php`](../app/Jobs/ProcessLeadDocumentJob.php)
  - remove Gemini-first assumptions from the job lifecycle
  - ensure unresolved results become review-required rather than generic failure
- [`app/Services/GeminiExtractionService.php`](../app/Services/GeminiExtractionService.php)
  - preserve as future fallback adapter
- [`app/Services/DocumentPreprocessService.php`](../app/Services/DocumentPreprocessService.php)
  - keep current safe preprocessing behavior until Python assumes richer preprocessing
- [`app/Services/LeadCompletenessService.php`](../app/Services/LeadCompletenessService.php)
  - should remain unchanged in responsibility if the normalized shape stays compatible
- [`config/services.php`](../config/services.php)
  - add Python service configuration
- [`.env.example`](../.env.example)
  - add provider selection and Python endpoint settings

### Suggested new Laravel components

- `app/Contracts/DocumentIntelligenceServiceInterface.php`
- `app/Services/PythonDocumentIntelligenceService.php`
- optional acceptance or evaluator service if the logic becomes too large inside `ExtractionService`

### Components that should remain unchanged in responsibility

- `LeadDocumentController`
- `DocumentService`
- `LeadCompletenessService`
- stage syncing and workflow logic
- manual checklist assignment semantics

## 4. What Stays In Laravel

Laravel remains responsible for:

- uploads and file storage
- queue dispatch and job lifecycle
- document upload status transitions
- persistence into `lead_documents` and `lead_extracted_data`
- metadata persistence
- normalization of extracted results
- checklist assignment
- lead completeness and stage transitions
- manual assignment behavior
- final workflow-readiness decision
- manual-review routing
- future Gemini fallback policy

Laravel remains the system of record and workflow brain.

## 5. What Moves To Python

Good candidates for Python in this phase:

- PDF text extraction
- scanned-PDF OCR
- image OCR
- image preprocessing and cleanup
- local document classification
- common field extraction
- local confidence scoring
- page-level document analysis

Python should focus only on document understanding, not workflow decisions.

Not suitable to move in this phase:

- checklist streak logic
- manual assignment logic
- lead completeness decisions
- lead stage transitions
- upload status lifecycle

## 6. Python Service Contract Direction

### Request direction

Laravel should send enough information for processing without giving Python ownership of storage or DB state.

Suggested request shape:

```json
{
  "document_id": 123,
  "lead_id": 10,
  "filename": "payslip-apr.pdf",
  "mime_type": "application/pdf",
  "content_base64": "...",
  "mode": "primary"
}
```

### Response direction

Python should return a Laravel-compatible normalized result shape so downstream logic does not need a major rewrite.

Suggested compatibility target:

```json
{
  "summary": "short summary",
  "confidence": "high",
  "needs_review": false,
  "classification": {
    "document_type": "payslip",
    "ic_side": null,
    "statement_year": 2026,
    "statement_month": 4,
    "statement_period": "2026-04"
  },
  "fields": {
    "full_name": "Example Name",
    "ic_number": null,
    "date_of_birth": null,
    "address": null,
    "employer": "Example Employer",
    "employment_type": "TETAP",
    "basic_salary": 2431.86,
    "gross_income": 4433.20,
    "net_pay": 2734.56,
    "total_deductions": 1698.64
  },
  "raw_text": "full OCR or extracted text",
  "provider_meta": {
    "provider": "python_local",
    "method": "pdf_text|ocr|hybrid",
    "timing_ms": 420
  }
}
```

The exact field names can be finalized during implementation from the existing repository expectations, but the logical shape should stay compatible.

## 7. Safe File Passing Strategy

Recommended first approach:

- Laravel reads the stored file
- Laravel sends bytes or base64 content to Python over internal HTTP

Why this is the safest first integration:

- avoids shared-filesystem coupling on day one
- avoids giving Python direct storage credentials
- keeps Laravel fully in control of source files

Avoid initially:

- public URL passing
- direct database access from Python
- Python owning Laravel storage paths

## 8. Laravel Validation And Acceptance Rules

Laravel should decide whether the Python result is workflow-ready.

### Core decision model

For every Python result, Laravel should decide:

1. Is the document type reliable?
2. Are critical fields available?
3. Are there strong conflicting signals?
4. Is the result suitable for the current checklist workflow?

### Expected handling in this phase

- workflow-ready result -> accept and persist normally
- accepted type but missing critical workflow fields -> send to manual review
- uncertain/conflicting result -> send to manual review
- unsupported but readable document -> keep as unsupported or manual review according to current workflow rules
- technical processing problem -> retry locally first, then mark for review/technical attention if exhausted

### Document-type examples

- IC:
  - must be type `ic`
  - side must be clear enough for checklist use
- Payslip:
  - must be type `payslip`
  - should have valid `statement_period`
  - should have at least one meaningful salary field
- Pension slip:
  - must stay separate from normal payslip
  - must not consume payslip checklist slots
- EPF:
  - must be type `epf`
  - should have valid `statement_year`
- CTOS / RAMCI:
  - issuer identity must be reliable enough

The exact pass/fail mechanics should follow the processing-policy documents and be implemented against the current Laravel services.

## 9. Manual Review Strategy For This Phase

Manual review is the safety layer for unresolved Python results during v2.0.

Typical manual-review cases:

- IC side remains uncertain
- payslip period is missing
- payslip and pension signals conflict
- CTOS / RAMCI issuer is unclear
- mixed-document file
- incomplete or heavily cropped document
- user-selected type strongly conflicts with detected type

Important rule:

- unresolved document-understanding cases should not be treated as permanent extraction failures by default

The system should preserve:

- detected document type
- detected side or period if available
- extracted fields
- confidence
- reason codes
- original file

That allows reviewers to correct the result and also gives the team tuning feedback for Python improvement.

## 10. Gemini Position In v2.0

Gemini remains in the repository, but automatic Gemini fallback is postponed in this rollout.

Meaning:

- Python is primary
- Laravel validates Python
- unresolved results go to manual review
- Gemini is preserved as a future provider, not part of the live automatic path yet

This is intentional so the team can clearly measure where Python succeeds and where it still needs work, instead of hiding Python weaknesses behind Gemini.

## 11. Gemini Concurrency Rule

During this phase:

- Python processing must not acquire a Gemini slot
- local Python retries must not acquire a Gemini slot
- no automatic Gemini slot should be acquired because Gemini fallback is disabled

Future rule:

1. Python processes the document
2. Laravel decides Gemini fallback is needed
3. Laravel acquires a Gemini slot
4. Gemini runs
5. Laravel releases the slot

So the current Gemini concurrency mechanism should remain available, but only for future actual Gemini calls.

## 12. Database / Metadata Direction

No major schema rewrite is required to start v2.0.

Current JSON metadata should be enough to store:

- Python provider details
- processing outcome
- reason codes
- workflow-ready flag
- review-required flag
- user-selected type conflict markers
- timing and OCR method

Possible metadata families:

- `local_processing.primary`
- `extraction_pipeline.primary_provider`
- `extraction_pipeline.reason_codes`
- `extraction_pipeline.manual_review_reason`

The exact field names should be finalized from the current repository structure during implementation rather than over-designed in policy documents.

## 13. Deployment Requirements

### Current state

Current Docker runtime is PHP-only:

- [`Dockerfile`](../Dockerfile)

It currently provides:

- PHP CLI runtime
- GD
- no Python runtime
- no Tesseract

### New deployment needs

To support Python-primary processing:

1. Laravel app/container
2. Python document-processing service/container
3. Tesseract installed in the Python runtime
4. internal network connectivity between Laravel and Python
5. environment config for endpoint URL and timeouts

### Recommended deployment model

Preferred setup:

- Laravel remains the app and queue-worker runtime
- Python runs as a separate internal service
- Tesseract is installed with Python

This is cleaner than forcing Python and Tesseract into the Laravel container immediately.

## 14. Fixture Dataset Requirement

Python should be tuned using a manually verified fixture dataset.

Fixture coverage should include:

- IC front and back
- payslips
- pension slips
- EPF statements
- CTOS reports
- RAMCI / approved Experian report families
- scanned PDFs
- compressed images
- rotated images
- blurred or cropped files
- mixed-document files
- unsupported documents

Each fixture should define at least:

- expected document type
- expected side where relevant
- expected statement period or year where relevant
- expected critical extracted fields
- whether it should be workflow-ready
- expected outcome category
- known confusion case

Every real production misclassification or recurring weak case should become a regression fixture where permitted.

## 15. Test Plan

### Existing coverage anchors to preserve

- [`tests/Feature/LeadExtractionWorkflowTest.php`](../tests/Feature/LeadExtractionWorkflowTest.php)
- [`tests/Feature/ProcessLeadDocumentJobTest.php`](../tests/Feature/ProcessLeadDocumentJobTest.php)
- [`tests/Feature/LeadCompletenessServiceTest.php`](../tests/Feature/LeadCompletenessServiceTest.php)

### New tests required

#### Provider abstraction tests

- `ExtractionService` uses the configured primary provider
- current workflow still works when the provider is swapped
- provider exceptions do not corrupt document status lifecycle

#### Python-primary acceptance tests

- clear payslip is accepted and persisted correctly
- clear pension slip is accepted and kept out of payslip checklist slots
- clear IC front / back is accepted correctly
- clear EPF with valid year is accepted correctly

#### Manual-review routing tests

- missing payslip `statement_period` routes to manual review
- uncertain IC side routes to manual review
- issuer-uncertain CTOS / RAMCI routes to manual review
- mixed-document result routes to manual review

#### Checklist preservation tests

- three consecutive payslips still auto-assign correctly
- pension slips do not consume payslip checklist slots
- IC front/back assignment still works
- EPF year assignment still works

#### Job / retry tests

- retryable Python technical failure retries locally
- exhausted Python technical failure ends in a non-stuck review/failure state
- no Gemini slot is acquired during Python-primary processing

## 16. Implementation Phases

This refinement should be implemented in small phases, not as one large change.

Each phase should leave the system in a stable, testable state before the next phase begins.

### Phase 1. Laravel Provider Abstraction

Purpose:

- prepare the current extraction flow so it no longer depends directly on Gemini-only wiring

Scope:

- introduce a provider contract
- wrap the current Gemini extraction behind that contract
- keep real behavior unchanged

Expected result:

- the system still behaves the same as today
- Laravel extraction is now provider-based internally

Primary areas affected:

- `app/Services/ExtractionService.php`
- `app/Services/GeminiExtractionService.php`
- new provider contract/service wiring

### Phase 2. Python Service Skeleton And Integration Contract

Purpose:

- establish the Python service boundary cleanly before making it authoritative

Scope:

- create the Python service skeleton
- define the request and response contract
- add Laravel configuration for provider selection and Python endpoint access
- add the Laravel client/service that talks to Python

Expected result:

- Laravel can call Python successfully
- the integration seam is working
- production workflow is still not yet switched to Python-primary

Primary areas affected:

- `config/services.php`
- `.env.example`
- new `PythonDocumentIntelligenceService`
- Python service scaffold

### Phase 3. Verified Fixture Dataset And Tuning Baseline

Purpose:

- create a trustworthy tuning and regression foundation before rollout

Scope:

- prepare manually verified fixture files
- label expected document types, key fields, and expected outcomes
- cover confusion cases and weak-quality files

Expected result:

- there is a stable fixture baseline for development and testing
- Python can be evaluated against real examples instead of assumptions

Primary areas affected:

- fixture dataset storage
- test fixtures
- supporting documentation and labels

### Phase 4. Local Classification And Extraction Tuning

Purpose:

- improve Python quality before it becomes the live primary processor

Scope:

- tune local PDF extraction
- tune OCR behavior
- tune classification rules
- tune common field extraction against the verified fixtures

Expected result:

- Python is good enough to become the first live processor for supported document types

Primary areas affected:

- Python classification logic
- Python extraction logic
- fixture-driven validation

### Phase 5. Python-Primary Laravel Rollout

Purpose:

- switch the live document pipeline from Gemini-first to Python-first

Scope:

- make Python the configured primary provider
- let Laravel validate the Python result
- accept workflow-ready results
- route unresolved results to manual review
- ensure Python processing does not acquire Gemini slots

Expected result:

- live document processing runs through Python first
- accepted results continue through the existing Laravel normalization and checklist path
- unresolved cases no longer depend on Gemini in this phase

Primary areas affected:

- `app/Services/ExtractionService.php`
- `app/Jobs/ProcessLeadDocumentJob.php`
- provider selection configuration

### Phase 6. Manual Review Hardening And Observability

Purpose:

- make unresolved cases understandable, traceable, and safe to operate

Scope:

- record structured reason codes
- improve processing metadata
- improve logs and timings
- ensure unresolved cases land in a clean review-required path instead of confusing failure states
- preserve enough detail for later tuning

Expected result:

- the team can see why documents were accepted, rejected, or sent to review
- recurring weak cases can be turned into regression examples

Primary areas affected:

- extraction metadata handling
- review-state handling
- tests around retries, review, and persistence

### Phase 7. Post-Rollout Stabilization

Purpose:

- strengthen the Python-primary pipeline after real usage begins

Scope:

- review recurring failure categories
- add new regression fixtures from real reviewed cases
- refine Laravel acceptance rules
- refine Python extraction and classification behavior

Expected result:

- the Python-primary pipeline becomes more accurate and more predictable over time

Primary areas affected:

- fixture set
- Python logic
- Laravel acceptance rules
- observability outputs

### Future Phase. Targeted Gemini Fallback

This is intentionally outside the current v2.0 rollout.

It should only begin after:

- Python performance is understood
- manual-review categories are measured
- the team knows which unresolved cases Gemini can realistically improve
- quota and cost are acceptable

At that point, Gemini can be introduced as a targeted fallback provider rather than a default dependency.

## 17. Main Risks And Assumptions

### Assumptions

- Python can return a Laravel-compatible normalized result shape
- internal service-to-service HTTP is acceptable
- Tesseract can be deployed in the target environment
- Laravel remains the authoritative persistence and workflow layer

### Risks

- deployment complexity increases
- OCR quality may still be weak for noisy images
- Python output may drift from Laravel expectations if not kept contract-compatible
- unresolved-state handling may remain confusing if review-required outcomes are not represented cleanly
- larger file payloads may slow processing if base64 transport is used
- if workers are not restarted after provider changes, stale processing logic may remain active

## Recommendation

The repository supports this migration well if Python is introduced as a provider behind the current extraction seam, not as a second workflow system.

The cleanest rollout is:

- keep `ProcessLeadDocumentJob`
- keep `ExtractionService`
- introduce a provider abstraction
- make Python the primary document-intelligence engine
- let Laravel validate the result
- send unresolved cases to manual review
- keep Gemini preserved for a later targeted fallback phase

This preserves the existing workflow backbone while reducing long-term dependence on Gemini.
