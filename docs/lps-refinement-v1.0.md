# LPS Refinement v1.0

## Objective

This refinement plan focuses on improving the current lead processing system by addressing the most immediate performance and stability issues first, especially around Gemini-backed document extraction.

The approach is split into two phases:

- Phase 1: immediate pipeline stabilization and throughput improvement
- Phase 2: deeper optimization after Phase 1 results are measured

## Phase 1

### 1. Add one shared Gemini concurrency limiter

Introduce one shared Gemini traffic limiter across the whole system so both intake jobs and document extraction jobs use the same concurrency control.

Purpose:

- stop intake and document jobs from competing blindly against the same Gemini quota
- stabilize request throughput
- reduce overload, retry, and rate-limit behavior

### 2. Update document extraction to use the shared limiter

Refine the document extraction pipeline so every document job must acquire and release a shared Gemini slot before calling Gemini.

Purpose:

- bring document extraction in line with controlled intake behavior
- prevent document jobs from bypassing Gemini traffic control

### 3. Run a sensible fixed number of document workers

Run enough `documents` queue workers so document batches are not effectively processed one by one, while still respecting the shared Gemini limit.

Purpose:

- avoid full serialization of uploaded document batches
- improve batch completion time
- keep the pipeline moving without blindly increasing concurrency

Note:

- this does not require autoscaling as the first step
- start with a fixed, sensible worker count and adjust later if needed

### 4. Add safe document preprocessing before Gemini

Add a preprocessing step for document extraction that creates an optimized working copy only for Gemini extraction, while keeping the original uploaded file untouched.

Purpose:

- reduce payload size for clearly oversized files
- improve Gemini response time
- lower per-file extraction cost
- avoid destructive changes to original source documents

Guiding principle:

- preprocess conservatively
- optimize for readability, not smallest file size

### 5. Add fallback-to-original extraction behavior

If an optimized document produces low confidence, unclear classification, or missing critical fields, retry extraction using the original file.

Purpose:

- reduce the risk of preprocessing harming extraction quality
- preserve accuracy while still gaining speed on oversized files

### 6. Refine the document pipeline into explicit stages

Document extraction should follow a clearer staged flow:

1. load file
2. preprocess if needed
3. acquire Gemini slot
4. call Gemini
5. save extraction result
6. release Gemini slot

Purpose:

- isolate the true bottleneck
- make pipeline behavior easier to reason about
- improve future maintainability and monitoring

### 7. Add performance and stability monitoring

Track the following operational signals:

- document queue wait time
- extraction duration per file
- Gemini retry and rate-limit frequency
- document batch completion time
- fallback-to-original frequency

Purpose:

- confirm whether Phase 1 actually improves throughput
- create a reliable baseline for later optimization work

### 8. Clean up nearby code inconsistencies

While refining this pipeline, align obvious inconsistencies directly related to the extraction path, such as stale tests or request/enum drift.

Purpose:

- reduce confusion between intended and actual behavior
- keep the extraction pipeline maintainable after the refinement

## Phase 2

### 1. Reassess performance after Phase 1

After Phase 1 is implemented and observed, evaluate whether document processing is still too slow or still too dependent on Gemini.

Purpose:

- avoid premature complexity
- decide next improvements based on measured behavior

### 2. Introduce OCR-first or rules-first handling for easy documents

For simple and predictable document types, use OCR and rules before escalating to Gemini.

Purpose:

- reduce unnecessary Gemini usage
- improve speed on straightforward documents
- preserve Gemini for ambiguous or messy inputs

### 3. Call Gemini only when confidence is low or the document is unclear

Use Gemini selectively for:

- low-confidence OCR results
- unclear document classification
- missing critical fields
- messy, noisy, or unusual files

Purpose:

- reduce Gemini cost
- reduce Gemini dependency
- improve overall system efficiency

### 4. Consider autoscaling only after Phase 1 is stable

If workload patterns later show meaningful spikes or uneven usage, consider autoscaling worker infrastructure after the shared limiter and fixed-worker strategy have proven stable.

Purpose:

- scale based on real demand
- avoid introducing infrastructure complexity too early

### 5. Expand optimization and routing rules based on observed production behavior

Refine preprocessing policies, OCR routing, and extraction fallback rules using actual measured outcomes after Phase 1.

Purpose:

- make the system progressively smarter
- tune behavior using real document patterns instead of assumptions

## Summary

### Phase 1 focus

- stabilize Gemini usage
- stop cross-pipeline contention
- improve document batch throughput
- safely reduce payload cost
- preserve extraction accuracy with fallback protection

### Phase 2 focus

- reduce Gemini dependency further
- use OCR/rules for simpler cases
- scale only where actual usage data supports it
