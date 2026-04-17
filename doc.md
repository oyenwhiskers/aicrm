# AI-Assisted Loan Lead Processing CRM — Prototype Concept

## 1. Objective

This system is a prototype CRM designed to streamline the process of handling loan leads from raw data intake to pre-screened applicant shortlist.

The goal is to:
- reduce manual data entry
- reduce manual chat follow-ups
- organize documents per lead
- extract structured information automatically
- assist in filtering eligible applicants

This is not just a storage system. It is a **process-driven lead handling workflow**.

---

## 2. Core Principle

Each lead is treated as a **case** that moves through a structured journey.

The system must always know:
- who the lead is
- what data is available
- what documents are collected
- what is missing
- what stage the lead is currently in

The system is responsible for maintaining clarity and progression.

---

## 3. High-Level Flow

1. Lead data is imported into the system
2. Lead becomes a structured record
3. User uploads documents per lead
4. System extracts data from documents using AI
5. System updates lead status based on completeness
6. User triggers processing
7. System evaluates and categorizes the lead

---

## 4. Module 1 — Leads Extraction Section

### Purpose
To convert raw input into structured leads inside the CRM.

### Input Types
- image / screenshot
- Excel file
- manual entry
- any raw lead source

### Expected Behavior
- system creates a new lead record for each entry
- each lead must at minimum have:
  - name
  - contact number
- duplicates should be avoided or flagged
- leads should be tagged with source (optional but recommended)

### Output
A clean list of leads ready for follow-up and document collection.

---

## 5. Module 2 — Lead Management & Interaction

### Purpose
To manage each lead and handle interaction.

### Capabilities

#### A. Document Upload
User can upload documents under each lead:
- IC
- 3 months payslip
- CTOS report
- RAMCI report

Each document must be linked to the correct lead.

#### B. Document Tracking
System should know:
- what documents are required
- what documents have been uploaded
- what is still missing

#### C. Quick WhatsApp Chat
- button opens WhatsApp using format:
  wa.me/+60XXXXXXXXX
- used for manual communication when needed

### Expected Behavior
- documents are organized per lead
- no mixing of documents across leads
- system maintains document completeness status

### Output
A structured case file per lead with associated documents.

---

## 6. Module 3 — AI Extraction & Data Structuring

### Purpose
To convert uploaded documents into structured data automatically.

### Trigger
Every time a document is uploaded.

### Process
- document is sent to AI (Gemini)
- AI extracts relevant information depending on document type

### Examples
- IC → name, IC number, DOB
- payslip → income, employer
- CTOS/RAMCI → credit indicators

### Expected Behavior
- extracted data is stored under the lead
- system should not overwrite blindly; it should update intelligently
- extracted data should improve completeness of the lead profile

### Stage Update Logic
System updates lead stage based on document progress:
- no document → early stage
- partial documents → in progress
- all required documents → ready for processing

### Output
A structured data profile per lead derived from documents.

---

## 7. Module 4 — Processing & Eligibility Filtering

### Purpose
To evaluate leads and determine if they are suitable.

### Trigger
Manual "Process" button by user.

### Evaluation Basis
Based on extracted data:
- income level
- document completeness
- credit indicators
- any predefined business rules

### Expected Behavior
System categorizes leads into:
- eligible
- not eligible
- incomplete
- manual review required

### Important Principle
This is a **pre-screening step**, not final approval.

Human still has final control.

### Output
A filtered and categorized list of leads.

---

## 8. Lead Stage Concept

Each lead must have a stage at all times.

Example progression:
- new lead
- documents pending
- partially complete
- documents complete
- ready for processing
- eligible
- not eligible
- manual review

Stage must reflect actual progress, not guess.

---

## 9. System Behavior Rules

- every lead must be traceable
- every document must belong to a specific lead
- system must avoid data confusion
- system must maintain clarity over automation
- AI supports extraction and assistance, not uncontrolled decisions
- user should always understand current status of each lead

---

## 10. Success Criteria for Prototype

The prototype is successful if:

- user can import leads easily
- user can upload documents per lead without confusion
- system can extract useful data from documents
- system can track what is missing
- system can update lead stage automatically
- user can click process and get a categorized result
- user no longer needs to manually read every document

---

## 11. One-Line Summary

This system is a **lead-to-decision workflow CRM** that transforms raw leads into structured, document-backed, pre-screened applicants ready for human action.

---

## 12. Lead Intake Scalability Roadmap

### Current State

The intake system is now operationally stable, but it is still architecturally centered around Gemini as the first-pass extraction engine for every uploaded image.

Current intake flow:
1. user uploads one or more screenshots
2. backend creates an intake batch and one queue job per image
3. workers claim images from a shared intake queue
4. Gemini performs extraction per image
5. extracted rows are normalized and returned to the intake UI

Stabilization already completed:
- backend-owned intake batches
- queue-safe image claim and finalize flow
- shared worker model with shared queue
- global and per-batch Gemini concurrency controls
- retry classification and adaptive slowdown for Gemini overload
- image compression before upload
- per-image timing visibility in the intake UI

This is the correct stabilization phase, but it is not the correct long-term high-scale architecture.

### Core Limitation

The current bottleneck is no longer Laravel or queue safety.

The real bottleneck is that every image still depends on Gemini on the critical path.

That means throughput is still bounded by:
- safe Gemini concurrency
- Gemini latency per image
- Gemini overload and rate-limit behavior

### Target Architecture

The intake pipeline should evolve into a staged backend pipeline:

1. upload and object storage
2. preprocess stage
3. OCR and layout stage
4. deterministic parsing stage
5. AI normalization stage only for ambiguous cases
6. aggregation stage
7. live progress reporting to the UI

Target processing philosophy:
- OCR and layout should handle the cheap and obvious path
- Gemini should only be used for ambiguity, cleanup, and low-confidence recovery
- expensive AI should stop being the mandatory first-pass engine for all traffic

### Required Long-Term Changes

#### A. Move to OCR-First Intake

Future target path:
1. image preprocessing
2. OCR text extraction
3. line grouping and row segmentation
4. deterministic name and phone parsing
5. confidence scoring
6. Gemini only when the OCR path is uncertain

Reason:
- reduces total Gemini calls
- lowers cost per image
- improves throughput under user concurrency
- makes provider overload less visible to end users

#### B. Split the Pipeline Into Stages

The current intake job combines too much responsibility into one logical lane.

Future queues should be separated by stage:
- intake-upload
- intake-preprocess
- intake-ocr
- intake-ai
- intake-aggregate

Reason:
- different stages can scale independently
- AI slowdown does not block cheap stages
- observability becomes clearer

#### C. Replace Database Queue Coordination With Redis

The current database queue and cache lock design is acceptable for stabilization and moderate usage.

For higher scale, coordination should move to Redis:
- Redis queues
- Redis semaphore or token-bucket control for Gemini
- Redis fairness state
- Redis-backed adaptive rate control

Reason:
- lower coordination overhead
- better concurrency semantics
- better fit for shared async workloads

#### D. Add Stronger Fairness Controls

Current fairness controls:
- shared workers across all users
- shared intake queue
- global Gemini concurrency cap
- per-batch Gemini concurrency cap

Future fairness controls should include:
- per-user active AI slot cap
- per-batch active AI slot cap
- optional tenant-level quotas if multi-tenant load matters later

Recommended future starting point:
- global AI concurrency tuned to provider capacity
- per-user AI concurrency = 1
- per-batch AI concurrency = 1 or 2 depending on testing

#### E. Add Duplicate Detection Earlier

Users often upload overlapping screenshots.

Future intake should add:
- perceptual hash or near-duplicate image detection
- overlap detection across screenshots in the same batch
- row-level duplicate suppression based on normalized phone number

Reason:
- duplicate screenshots should not repeatedly consume expensive OCR and AI work

#### F. Add Real Service Metrics

The intake system should be measured with service-level metrics, not just anecdotal speed.

Metrics to track:
- queue depth per stage
- OCR throughput
- AI slot utilization
- per-user wait time
- per-batch wait time
- overload and rate-limit error rate
- percent of images resolved without AI
- cost per completed batch

### Proposed Implementation Phases

#### Phase 1: Keep Current Intake Stable While Creating Stage Boundaries

Goal:
- preserve current behavior while preparing for OCR-first expansion

Changes:
- introduce explicit batch-image processing stages in data or metadata
- isolate preprocess logic from Gemini extraction logic
- make aggregation a distinct service step

Expected result:
- easier incremental migration without breaking the current UI

##### Phase 1 Checklist For This Codebase

1. Add explicit intake image stage metadata
  Target files:
  - [app/Models/IntakeBatchImage.php](app/Models/IntakeBatchImage.php)
  - [database/migrations/2026_04_16_000007_create_intake_batches_tables.php](database/migrations/2026_04_16_000007_create_intake_batches_tables.php)
  Goal:
  - introduce a stage field or structured metadata that distinguishes upload, preprocess, OCR, AI, and aggregate responsibilities instead of treating every image as one generic processing step

2. Split current monolithic image processing orchestration into sub-services
  Target files:
  - [app/Services/IntakeBatchService.php](app/Services/IntakeBatchService.php)
  - new services to introduce later such as `IntakePreprocessService`, `IntakeAggregationService`, and `IntakeRowNormalizationService`
  Goal:
  - keep `IntakeBatchService` as the orchestrator only
  - move image preparation, extraction, and aggregation responsibilities into separate services

3. Isolate current Gemini extraction behind an intake extraction interface
  Target files:
  - [app/Services/LeadCaptureService.php](app/Services/LeadCaptureService.php)
  - [app/Services/GeminiExtractionService.php](app/Services/GeminiExtractionService.php)
  Goal:
  - create a clean boundary so OCR-first extraction can later be introduced without rewriting the intake batch orchestration

4. Move row normalization into its own dedicated service
  Target files:
  - [app/Services/IntakeBatchService.php](app/Services/IntakeBatchService.php)
  - [app/Models/IntakeBatchNormalizedRow.php](app/Models/IntakeBatchNormalizedRow.php)
  Goal:
  - extract `normalizeBatchRows()` into a dedicated normalization service so aggregation can become an explicit pipeline stage

5. Persist preprocess-ready metadata on each image
  Target files:
  - [app/Models/IntakeBatchImage.php](app/Models/IntakeBatchImage.php)
  - [resources/js/app.js](resources/js/app.js)
  Goal:
  - keep current browser compression behavior
  - start storing metadata such as original size, optimized size, dimensions, and future crop hints so later preprocessing is observable

6. Prepare stage-specific job boundaries even if they still call the same implementation initially
  Target files:
  - [app/Jobs/ProcessIntakeBatchImageJob.php](app/Jobs/ProcessIntakeBatchImageJob.php)
  - future jobs such as `PreprocessIntakeBatchImageJob`, `OcrIntakeBatchImageJob`, `AggregateIntakeBatchJob`
  Goal:
  - establish queue boundaries before changing the actual extraction engine
  - keep current production path intact while making the future pipeline possible

7. Expand attempt and batch observability so future stages can be measured separately
  Target files:
  - [app/Models/IntakeImageAttempt.php](app/Models/IntakeImageAttempt.php)
  - [app/Services/IntakeGeminiConcurrencyService.php](app/Services/IntakeGeminiConcurrencyService.php)
  - [resources/js/app.js](resources/js/app.js)
  Goal:
  - record stage name, stage start and finish time, stage error class, and whether Gemini was actually invoked
  - distinguish queue wait from preprocess wait, OCR time, and AI time later

8. Keep the current UI contract stable while enriching the payload
  Target files:
  - [app/Http/Controllers/Api/LeadIntakeBatchController.php](app/Http/Controllers/Api/LeadIntakeBatchController.php)
  - [app/Services/IntakeBatchService.php](app/Services/IntakeBatchService.php)
  - [resources/js/app.js](resources/js/app.js)
  Goal:
  - preserve the current batch polling API
  - add richer stage information without breaking the existing review screen

9. Do not remove the current Gemini-first fallback during Phase 1
  Goal:
  - Phase 1 is a refactor-for-boundaries phase, not the OCR rollout itself
  - current behavior must remain operational while the new pipeline edges are introduced

##### Phase 1 Success Criteria

Phase 1 is successful when:
1. the current intake UI still works without behavior regression
2. `IntakeBatchService` is reduced to orchestration responsibilities
3. preprocessing, extraction, and aggregation are separable units in code
4. future OCR-first insertion points exist without another full redesign
5. observability clearly shows where time is spent before and after Gemini is called

#### Phase 2: Add Preprocess Stage

Goal:
- shrink and clean images before expensive extraction

Changes:
- crop UI chrome where possible
- isolate likely content area
- detect near-duplicates inside the batch
- persist preprocessing metadata per image

Expected result:
- smaller payloads and less repeated work

#### Phase 3: Add OCR and Layout Stage

Goal:
- extract most rows without Gemini

Changes:
- run OCR on intake images
- group lines into candidate lead rows
- parse obvious phone-number-driven records deterministically
- mark confidence per candidate row

Expected result:
- Gemini traffic becomes selective instead of universal

#### Phase 4: Add AI-Only-for-Ambiguous-Cases Stage

Goal:
- reserve Gemini for hard cases only

Changes:
- only low-confidence or structurally ambiguous rows are sent to Gemini
- Gemini receives cropped or row-level context instead of the full screenshot when possible

Expected result:
- higher throughput
- lower cost
- lower overload sensitivity

#### Phase 5: Redis Cutover

Goal:
- move shared coordination to infrastructure designed for concurrency

Changes:
- Redis queue backend
- Redis semaphore for Gemini slot control
- Redis-backed adaptive slowdown and fairness keys

Expected result:
- better scaling semantics under concurrent usage

### Practical Engineering Principle

The system should stop being "Gemini-first" and become "pipeline-first".

Correct mindset:
- cheap stages should scale wide
- expensive AI should be isolated and selective
- queue and fairness controls should protect the AI lane, not define the entire system architecture

### One-Line Long-Term Conclusion

The stabilized Gemini-first intake pipeline is acceptable for the current phase, but the long-term scalable target is an OCR-first, staged, Redis-coordinated pipeline where Gemini is reserved only for ambiguous cases rather than all images.