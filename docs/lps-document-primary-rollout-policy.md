LPS Python-Primary Rollout Policy

Purpose

This document defines the current rollout strategy for the local Python document-processing engine.

It explains:

1. What will be implemented during the current phase
2. What is intentionally postponed
3. How unresolved Python results will be handled
4. How the architecture remains ready for a future Gemini fallback
5. What conditions should be met before Gemini fallback is introduced

This document should be read together with:

- lps-document-classification.md
- lps-document-processing-policy.md


1. Confirmed Rollout Strategy

The current strategy is:

- Python is the primary document-processing provider
- Laravel remains the workflow and business-rule authority
- Gemini automatic fallback is postponed
- Unresolved Python results go to manual review during the current phase
- The architecture must remain ready for Gemini fallback later

The immediate objective is to make the Python processor stable, measurable, and easy to tune.

The current phase should not spend unnecessary effort integrating or optimizing Gemini fallback.


2. Why Gemini Fallback Is Postponed

Gemini fallback is postponed because the current priority is to understand the actual capability of the Python processor.

Introducing automatic Gemini fallback too early may hide weaknesses in Python.

For example:

- Python fails to identify a payslip period
- Gemini successfully extracts it
- The final workflow succeeds
- The Python weakness is not treated as a priority

This may result in continued dependence on Gemini.

The preferred approach is:

1. Let Python process the document
2. Record exactly where Python succeeds or fails
3. Route unresolved cases to manual review
4. Use those cases to improve Python
5. Introduce Gemini later only for the remaining difficult cases

This supports the long-term objective of reducing Gemini dependency.


3. Current Processing Flow

The current implementation flow should be:

1. User uploads a document
2. Laravel stores the original document
3. Laravel dispatches the document-processing job
4. Python processes the document
5. Laravel validates the Python result
6. Laravel decides whether the document is workflow-ready
7. Accepted results continue to normalization and checklist assignment
8. Unresolved results go to manual review
9. Technical failures follow the local retry policy

Gemini should not be called automatically during this phase.


4. Current Decision Outcomes

The current phase should use clear processing outcomes.


Accepted workflow-ready

Meaning:

- Python confidently identifies the document
- Critical workflow fields are available
- No major conflict exists
- The document may continue to Laravel normalization and checklist assignment

Examples:

- Payslip with a valid statement period and usable salary information
- EPF statement with a valid statement year
- IC with a confirmed front or back side
- Verified CTOS report
- Verified RAMCI or approved Experian report


Accepted not workflow-ready

Meaning:

- Python correctly identifies the document
- One or more critical workflow fields are missing
- The document type should remain accepted
- Automatic checklist assignment must be blocked

Example:

- Document is clearly a payslip
- Statement period cannot be extracted

Current action:

- Send to manual review
- Record which critical field is missing

Future possible action:

- Send to Gemini fallback if the missing field is considered recoverable


Manual review required

Meaning:

- Python completed processing but the result cannot safely enter the workflow

Examples:

- IC side remains uncertain
- Payslip and pension indicators conflict
- RAMCI or CTOS issuer is unclear
- File contains multiple document types
- User-selected type conflicts strongly with detected type
- Important extracted values conflict
- Document is readable but incomplete


Technical failure

Meaning:

- Python could not complete processing because of a technical problem

Examples:

- Python service unavailable
- Processing timeout
- OCR engine error
- Temporary file-access problem
- Internal Python error

Technical failures should follow the local retry policy before manual review.


Unsupported document

Meaning:

- The file is readable
- The document purpose is understood
- It is not one of the supported document types

Examples:

- Bank statement
- Employment letter
- Loan agreement
- Offer letter
- Financing form

Unsupported documents should not be treated as technical failures.


5. Fallback Candidate Concept

Even though Gemini fallback is not active, the system should record whether an unresolved result may be suitable for future fallback.

Examples of future fallback candidates:

- Statement period missing from a likely payslip
- Statement year missing from a clear EPF statement
- IC side uncertain because of weak image quality
- Payslip and pension classification conflict
- CTOS or RAMCI issuer branding is difficult to read
- OCR text is weak but the document is otherwise valid

Examples that should normally not become Gemini fallback candidates:

- Corrupted file
- Password-protected PDF
- Unsupported file format
- Clearly unsupported document
- Mixed-document file requiring splitting
- Document that appears incomplete or altered

During the current phase, fallback candidates still go to manual review.

The fallback-candidate reason must be recorded so the team can later decide which cases Gemini should handle.


6. Structured Processing Reasons

The system should record structured reasons instead of relying only on free-text messages.

Suggested reason categories include:

Classification and document understanding:

- low_confidence_classification
- unsupported_document
- uncertain_document_type
- mixed_document
- user_type_conflict

IC:

- ic_side_uncertain
- missing_ic_identity_fields
- weak_front_side_evidence
- weak_back_side_evidence

Payslip and pension:

- missing_statement_period
- missing_salary_information
- pension_payslip_conflict
- unclear_payroll_structure

EPF:

- missing_statement_year
- unclear_epf_statement_purpose

Credit reports:

- issuer_uncertain
- unapproved_experian_report_family
- ctos_ramci_conflict
- missing_identifying_page

OCR and file quality:

- ocr_too_weak
- document_cropped
- document_incomplete
- image_quality_too_low

Technical processing:

- provider_timeout
- provider_unavailable
- provider_internal_error
- file_read_failure
- password_protected_pdf
- corrupted_file

These reasons will be used to:

- Improve Python rules
- Build test fixtures
- Review recurring failures
- Decide future Gemini fallback scope
- Explain manual-review cases


7. Provider Architecture Requirement

Laravel should use a provider abstraction even though Python is currently the only active provider.

The intended architecture is:

Extraction workflow
then document intelligence provider
then Python implementation

Laravel should not tightly couple workflow logic directly to Python-specific implementation details.

The provider boundary should allow another provider to be introduced later without redesigning:

- Upload handling
- Queue processing
- Extraction orchestration
- Normalization
- Persistence
- Checklist assignment

The existing Gemini service should remain isolated and available in the repository, but automatic fallback should remain disabled.


8. Current Configuration Intent

The system should clearly represent that Python is primary and Gemini fallback is disabled.

Conceptually:

- Primary provider is Python
- Fallback is disabled
- Unresolved results go to manual review

The exact configuration names should follow the existing repository conventions.

The implementation should avoid deleting or rewriting the existing Gemini provider unnecessarily.

The goal is to disable automatic fallback, not remove future fallback capability.


9. Gemini Concurrency Rule

Python processing must not acquire a Gemini concurrency slot.

Local Python retries must not acquire a Gemini concurrency slot.

During the current phase, no Gemini slot should be acquired because automatic fallback is disabled.

In the future:

1. Python processes the document
2. Laravel decides that Gemini fallback is required
3. Laravel acquires a Gemini concurrency slot
4. Laravel calls Gemini
5. Laravel releases the slot

The Gemini concurrency mechanism must remain limited to actual Gemini calls.


10. Local Retry Policy

Temporary Python technical failures should be retried locally.

Examples:

- Connection failure
- Temporary timeout
- Python service unavailable
- Temporary OCR engine failure
- Temporary file-access failure

Local retries should use:

- A limited retry count
- Increasing delay between retries
- Clear failure logging
- No Gemini call during local retry

The exact retry count and delay should be configurable.

After local retries are exhausted:

- Recoverable document-understanding cases go to manual review
- Permanent technical failures are recorded as technical failure
- Administrators should be able to inspect the reason


11. Manual Review During the Current Phase

Manual review is the current safety layer for unresolved Python results.

Manual review should receive enough information to explain the problem.

Useful review information includes:

- Python-detected document type
- Detected IC side where relevant
- Classification confidence
- Extracted fields
- Missing critical fields
- Conflicting signals
- OCR quality
- Processing reason codes
- User-provided type
- Original document
- Extracted text where appropriate

Manual review should not erase the original Python result.

The reviewer’s correction should be stored separately from the original detected result for audit and tuning purposes.


12. Manual Correction Priority

Manual corrections should take priority over automatic classification for checklist assignment.

However, the original Python result should remain recorded.

Example:

- Python detected payslip
- Reviewer corrected it to pension_slip

The system should retain:

- Original detected type: payslip
- Final corrected type: pension_slip
- Correction source: manual review
- Correction reason

This allows the failed case to be used later for Python improvement.


13. Metadata and Observability

For every processed document, record enough information to understand what happened.

Minimum useful information includes:

- Primary provider
- Processing outcome
- Detected document type
- Detected IC side where relevant
- Classification confidence
- Workflow-ready status
- Missing critical fields
- Processing reason codes
- Manual review required or not
- Manual review reason
- Processing duration
- OCR method used
- Pipeline version
- User-provided document type
- Type conflict if any

Fallback-related fields may also be reserved for future use:

- Fallback candidate
- Fallback reason
- Fallback attempted
- Fallback provider
- Final accepted provider

During the current phase:

- Fallback attempted should remain false
- Fallback provider should remain empty
- Fallback candidate may still be recorded


14. Fixture Dataset Requirement

Python should be tuned using a verified document fixture dataset.

Each fixture should define:

- Expected document type
- Expected IC side where relevant
- Expected statement period or year where relevant
- Expected critical fields
- Whether the result should be workflow-ready
- Expected processing outcome
- Expected reason codes
- Known confusion type
- File-quality characteristics

Useful quality labels include:

- machine_generated
- scanned
- photographed
- rotated
- compressed
- blurred
- cropped
- multi_page
- mixed_document

Every production misclassification should become a regression fixture where legally and operationally permitted.


15. Current Development Scope

The current phase should implement and stabilize:

- Python provider integration
- PDF text extraction
- OCR
- Image preprocessing
- Document classification
- Critical field extraction
- Result validation
- Workflow-readiness decisions
- Manual review state
- Structured processing reasons
- Local retry behavior
- Metadata and observability
- Fixture-based tests

The current phase should not implement:

- Automatic Gemini fallback calls
- Gemini fallback prompt refinement
- Gemini fallback queue orchestration
- Gemini quota-reset scheduling
- Gemini fallback result comparison
- Automatic fallback concurrency handling beyond preserving the existing mechanism
- Bank eligibility calculations
- DSR calculations
- Bank matching


16. Conditions Before Enabling Gemini Fallback

Gemini fallback should only be considered after Python has been tested against enough verified documents.

Before enabling fallback, the team should understand:

1. Python local acceptance rate
2. Python classification accuracy
3. Critical-field extraction accuracy
4. Manual-review rate
5. Most common unresolved reason codes
6. Which unresolved cases Gemini can realistically improve
7. Expected Gemini request volume
8. Whether Gemini quota and cost are acceptable
9. Which cases should never be sent to Gemini
10. How sensitive document data will be handled

Gemini fallback should be introduced based on measured failure categories, not as a general default.


17. Future Gemini Fallback Phase

When Python is sufficiently stable, a separate fallback implementation phase may be introduced.

The future flow may become:

1. Python processes the document
2. Laravel validates the result
3. Workflow-ready result is accepted
4. Recoverable unresolved result becomes a fallback candidate
5. Laravel checks whether Gemini fallback is enabled
6. Laravel acquires a Gemini slot
7. Gemini processes the document
8. Laravel validates the Gemini result
9. Accepted fallback result continues to the workflow
10. Unresolved result goes to manual review

This future phase should be documented separately before activation.


18. Success Criteria for the Current Phase

The Python-primary phase may be considered stable when:

- Supported document types are classified reliably
- IC front and back are distinguished accurately
- Payslip and pension slip are separated reliably
- Payslip statement periods are extracted consistently
- EPF statement years are extracted consistently
- CTOS and approved RAMCI or Experian report families are distinguished
- Mixed files are detected
- Unsupported files are not forced into supported types
- Manual-review reasons are clear
- Technical retries behave predictably
- Every major failure category has fixture coverage
- Python results do not depend on Gemini availability


19. Relationship to the Processing Policy

The document-processing policy defines the permanent business decisions:

- What makes a document workflow-ready
- What requires review
- Which fields are critical
- How checklist assignment is protected

This rollout policy defines the temporary implementation strategy:

- Python is primary
- Gemini fallback is postponed
- Manual review handles unresolved cases
- Fallback-ready architecture is preserved

When Gemini fallback is eventually enabled, this rollout document should be revised or replaced without rewriting the main document-processing policy.


20. Final Principle

The current objective is not to make every document succeed automatically.

The objective is to:

1. Build a reliable local Python processor
2. Understand exactly where it fails
3. Preserve unresolved cases for tuning
4. Avoid hiding Python weaknesses behind Gemini
5. Keep the architecture ready for targeted fallback later

Current strategy:

Python first
Laravel validates
Manual review handles unresolved cases
Gemini fallback remains postponed but architecturally possible