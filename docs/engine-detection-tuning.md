```text
Refine the current IC classification and confidence logic.

Context
=======

The Python engine is currently able to classify the tested Malaysian IC front and back images correctly.

Front IC evidence currently detected:
- `MyKad`
- valid IC number
- OCR variation such as `KAD PENGENALAWN`
- partial or noisy person name

Current result:
- document_type = ic
- ic_side = front
- confidence = medium

Back IC evidence currently detected:
- `KETUA PENGARAH PENDAFTARAN NEGARA`
- `PENDAFTARAN NEGARA`
- valid IC number
- optional text such as `Touch n Go` or `chip`

Current result:
- document_type = ic
- ic_side = back
- confidence = medium

The classifications are correct.

The problem is that:

1. The confidence calculation appears to mix document-classification certainty with OCR quality and field completeness.
2. Front-side reasoning should rely on positive front evidence, not mainly on the absence of back markers.
3. OCR variations of known IC phrases are not handled consistently.
4. IC number and name must remain supporting evidence only.
5. Contradictory payroll or statement evidence must still prevent unsafe IC acceptance.

Do not replace the current classifier or introduce a machine-learning or external AI dependency.

This refinement should improve the existing deterministic Python logic.


Objectives
==========

1. Preserve correct IC front and back classification.
2. Improve OCR tolerance for known Malaysian IC phrases.
3. Separate classification confidence from OCR quality.
4. Make IC-side detection depend on positive side-specific evidence.
5. Keep name and IC number as supporting evidence only.
6. Add or preserve contradiction safeguards.
7. Maintain backward compatibility with the existing Laravel response contract.
8. Do not implement visual AI detection during this phase.


1. Refine the IC evidence model
===============================

Classify IC evidence into three categories:

A. Strong document-level IC evidence

Examples:

Front-related:
- `mykad`
- `kad pengenalan`
- recognised OCR variations of `kad pengenalan`

Back-related:
- `ketua pengarah pendaftaran negara`
- `pendaftaran negara`
- other verified issuing-authority wording already supported by the project

These signals help prove that the document itself is an IC.

B. Supporting identity evidence

Examples:
- valid Malaysian IC number
- full name
- date of birth where available
- citizenship information where available

These signals increase confidence only after strong IC-specific evidence exists.

Do not allow:

- full name
- `nama`
- `name`
- IC number

to independently establish an IC classification.

C. Contradictory evidence

Examples:
- statement period
- salary period
- basic salary
- gross income
- net pay
- employer
- earnings table
- deduction table
- payroll terminology
- EPF statement structure
- CTOS or RAMCI report structure

Strong contradictory evidence must either:

- prevent IC from being accepted automatically, or
- set `needs_review = true` with a structured reason.


2. Add controlled OCR-tolerant phrase matching
==============================================

Improve matching for a small whitelist of known IC phrases.

Examples that should map to `kad pengenalan` where similarity is sufficiently strong:

- `KAD PENGENALAN`
- `KAD PENGENALAWN`
- `KAD PENGENA1AN`
- `KAD PENGENA IAN`
- reasonable spacing or single-character OCR variations

Also tolerate controlled spacing errors for verified authority phrases.

Do not introduce unrestricted fuzzy matching across all OCR text.

The matching must:

- apply only to a known phrase whitelist
- use a conservative similarity threshold
- avoid converting unrelated text into IC evidence
- preserve the original OCR text for audit and debugging
- record which normalized phrase was matched

Example metadata:

- original_text = `KAD PENGENALAWN`
- normalized_marker = `kad pengenalan`
- match_method = `controlled_fuzzy_match`


3. Refine IC front acceptance
=============================

IC front must be determined using positive front evidence.

Recommended front acceptance bundle:

Required:
- document type has strong IC-specific evidence
- at least one strong front-specific marker exists
- no stronger back-side evidence exists
- no strong contradictory document evidence exists

Supporting:
- valid IC number
- full or partial person name

For the current tested front IC:

- `MyKad` is strong front-specific evidence
- valid IC number is supporting evidence
- noisy or incomplete name should reduce field completeness
- noisy name should not unnecessarily reduce classification confidence

Expected result for the current front sample:

- document_type = ic
- ic_side = front
- classification_confidence = high
- side_confidence = high
- ocr_quality = medium
- field_completeness = medium
- needs_review = false, provided there is no contradictory evidence

Do not explain the decision only as:

- no back markers were found

The correct reasoning is:

- positive front evidence was found
- supporting identity evidence was found
- back evidence was not stronger
- no conflicting document structure was found


4. Refine IC back acceptance
============================

Recommended back acceptance bundle:

Required:
- document type has strong IC-specific evidence
- at least one strong back-specific authority marker exists
- no stronger front-side evidence exists
- no strong contradictory document evidence exists

Strong back evidence includes:

- `ketua pengarah pendaftaran negara`
- `pendaftaran negara`
- other verified back-side authority wording

Supporting:
- valid IC number

Do not depend strongly on:

- `Touch n Go`
- `chip`
- `80K chip`
- exact chip terminology

These may be supporting signals but must not be required because card versions and OCR results may differ.

Expected result for the current back sample:

- document_type = ic
- ic_side = back
- classification_confidence = high
- side_confidence = high
- ocr_quality = medium
- field_completeness = medium
- needs_review = false, provided there is no contradictory evidence


5. Separate the confidence dimensions
=====================================

Do not use one confidence value to represent all of the following:

- certainty that the document is an IC
- certainty that the side is front or back
- OCR readability
- completeness of extracted fields

Introduce or expose separate internal confidence dimensions:

- classification_confidence
- side_confidence
- ocr_quality
- field_completeness

Suggested allowed values:

- high
- medium
- low

Meaning:

classification_confidence
- certainty that the document type is IC

side_confidence
- certainty that the IC side is front or back

ocr_quality
- quality and readability of the OCR output

field_completeness
- completeness of extracted fields such as name and IC number

Example:

A front IC may have:

- strong `MyKad` evidence
- valid IC number
- noisy name

The correct interpretation is:

- classification_confidence = high
- side_confidence = high
- ocr_quality = medium
- field_completeness = medium

Do not reduce document-classification certainty merely because the full name is noisy.


6. Preserve backward compatibility
==================================

The existing Laravel integration may still expect:

- confidence
- needs_review
- classification.document_type
- classification.ic_side

Do not break the existing response contract.

If new confidence fields are introduced, place them inside the existing provider metadata or another backward-compatible section.

For example:

- existing `confidence` remains available
- detailed confidence dimensions are stored under provider metadata

Determine the safest mapping for the existing `confidence` field.

Recommended approach:

- let existing `confidence` represent classification confidence
- store OCR quality, side confidence, and field completeness separately

Document any compatibility decision clearly.


7. Refine side detection behaviour
==================================

Only run IC-side detection after the document has passed the strong IC evidence gate.

Do not perform this circular sequence:

- name and IC number cause IC classification
- the same name and IC number cause front-side classification
- the same fields then cause `needs_review = false`

Required behaviour:

1. Prove the document is likely an IC using IC-specific evidence.
2. Determine the side using positive front or back evidence.
3. Independently check contradictions and review requirements.

Side outcomes:

Front:
- strong positive front evidence exists
- back evidence is not stronger

Back:
- strong positive back evidence exists
- front evidence is not stronger

Uncertain:
- IC type is likely, but side evidence is insufficient
- front and back evidence conflict
- both sides may appear in one image

For uncertain side:

- ic_side = uncertain
- needs_review = true
- reason = ic_side_uncertain


8. Add contradiction safeguards
===============================

Even when a strong IC phrase is present, check whether the overall document contains conflicting structure.

Examples:

- MyKad wording appears inside an HR form
- an IC number appears inside a payslip
- the document contains a statement period
- salary values and payroll tables are present
- the document contains earnings and deductions
- the file contains multiple unrelated document types

Recommended behaviour:

If strong IC evidence and strong conflicting document evidence both exist:

- do not silently accept the result
- set needs_review = true
- set workflow_ready = false where supported
- record reason = conflicting_document_evidence

Do not automatically convert the result into payslip or another type unless an existing explicit normalization rule already supports that conversion.


9. Do not introduce visual AI during this phase
===============================================

The current refinement should remain focused on OCR and deterministic rules.

Do not currently add:

- face recognition
- biometric recognition
- flag recognition model
- chip detection model
- colour-based MyKad recognition
- external computer-vision API
- Gemini fallback

However, keep the design open for future visual supporting evidence such as:

- card-like geometry
- portrait region
- landscape card structure
- front or back layout characteristics

Visual evidence may be introduced later as supporting evidence for cases where OCR is insufficient.


10. Improve decision evidence and metadata
==========================================

For every IC decision, preserve the evidence used.

Useful metadata should include:

- matched strong IC markers
- matched front markers
- matched back markers
- supporting identity evidence
- contradictory evidence
- original OCR phrase
- normalized phrase
- phrase match method
- classification score
- side score
- score margin
- classification confidence
- side confidence
- OCR quality
- field completeness
- review reason

Example decision explanation:

Document type:
- ic

Strong markers:
- mykad

Supporting evidence:
- valid_ic_number

Front markers:
- mykad

Back markers:
- none

Contradictions:
- none

Decision:
- ic front

Confidence:
- classification = high
- side = high
- OCR quality = medium
- field completeness = medium


11. Review logic
================

Set `needs_review = true` when:

- IC-specific evidence is insufficient
- IC side is uncertain
- front and back signals conflict
- strong contradictory document evidence exists
- multiple IC cards or both sides appear in one image
- classification score is tied or has an unsafe margin
- only generic identity fields are present
- extracted result is internally inconsistent

Do not set `needs_review = true` merely because:

- the full name is partially noisy
- one non-critical field is missing
- OCR quality is medium

provided the IC type and side are still strongly proven.


12. Required regression tests
=============================

Add or update tests for the following.

Test 1: Current front IC sample

Evidence:
- `MyKad`
- valid IC number
- OCR variation `KAD PENGENALAWN`
- noisy name

Expected:
- document_type = ic
- ic_side = front
- classification confidence = high
- side confidence = high
- OCR quality may remain medium
- field completeness may remain medium
- needs_review = false

Test 2: Current back IC sample

Evidence:
- `KETUA PENGARAH PENDAFTARAN NEGARA`
- `PENDAFTARAN NEGARA`
- valid IC number

Expected:
- document_type = ic
- ic_side = back
- classification confidence = high
- side confidence = high
- OCR quality may remain medium
- needs_review = false

Test 3: Payslip containing name and IC number

Expected:
- must not classify as IC based only on identity fields

Test 4: Payslip containing monthly period and salary values

Expected:
- must not classify as IC
- if Python still produces IC, contradiction checking must require review

Test 5: IC phrase with strong payroll contradiction

Expected:
- needs_review = true
- reason = conflicting_document_evidence

Test 6: Strong IC type but uncertain side

Expected:
- document_type = ic
- ic_side = uncertain
- needs_review = true

Test 7: Both front and back markers detected

Expected:
- do not silently choose one side
- needs_review = true

Test 8: Genuine IC with poor full-name OCR

Expected:
- classification can still be accepted when strong IC and side evidence exist
- field completeness should be reduced independently

Test 9: Only name and IC number found

Expected:
- must not auto-classify as IC
- uncertain or review-required result

Test 10: Controlled OCR phrase variation

Input:
- `KAD PENGENALAWN`

Expected:
- safely recognised as a likely `kad pengenalan` marker
- original OCR text preserved
- controlled matching method recorded


13. Implementation constraints
==============================

- Do not weaken the payslip safeguards introduced previously.
- Do not remove Laravel contradiction validation.
- Do not make IC number sufficient for IC classification.
- Do not make exact colour or visual artwork mandatory.
- Do not require every IC field to be extracted before classification can be high confidence.
- Do not silently select front because back evidence is absent.
- Do not use broad fuzzy matching.
- Do not break the existing provider response contract.
- Do not activate Gemini fallback.
- Keep the implementation deterministic and testable.


14. Required completion report
==============================

After implementing the refinement, provide:

1. Files changed
2. Existing logic that was preserved
3. New IC evidence rules
4. New controlled OCR normalization rules
5. Updated side-detection logic
6. Updated confidence calculation
7. Backward-compatibility handling
8. Contradiction checks added
9. Tests added or updated
10. Results for the current front and back samples
11. Results for the payslip-to-IC regression fixtures
12. Any remaining limitation

The main goal is:

A valid IC should be classified confidently when strong IC-specific evidence exists, even if OCR completeness is only medium.

At the same time, generic identity fields such as name and IC number must never be enough to classify a payslip or another document as an IC.
```
