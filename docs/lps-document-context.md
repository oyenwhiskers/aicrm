# LPS Document Context Preparation Guide

## Objective

This document defines what context should be prepared before building or tuning the Python-first document classification and extraction engine.

The goal is to help the system identify document types more accurately, extract the right fields consistently, and decide when Gemini fallback or manual review is needed.

This guide is preparation material for:

- local Python document classification
- OCR and PDF extraction tuning
- Laravel acceptance rules
- Gemini fallback policy
- fixture dataset design
- manual review feedback loops

## Why This Preparation Is Needed

A document engine works better when it is given a clear rulebook.

Without document context, the system is forced to rely only on:

- generic OCR
- generic AI interpretation
- broad keyword guessing

That usually causes:

- wrong document type classification
- missed critical fields
- confusion between similar document types
- unnecessary Gemini fallback
- weak checklist assignment

With document context prepared properly, the engine can:

- identify common document types faster
- know what fields matter for each type
- recognize confusion cases
- reject weak results safely
- improve over time using reviewed failures

## What Must Be Prepared

For every supported document type, prepare:

1. document purpose
2. identification indicators
3. required extraction fields
4. optional extraction fields
5. acceptance rules
6. fallback triggers
7. confusion cases
8. real fixture examples

This should be done for:

- IC front
- IC back
- payslip
- pension slip
- EPF statement
- CTOS report
- RAMCI report
- unsupported or unknown documents

## Required Reference Structure Per Document Type

For each type, the preparation should use this structure.

### 1. Document Name

Example:

- Payslip
- Pension Slip
- IC Front

### 2. Business Purpose

Why the system needs this document.

Example:

- Payslip: used for income verification
- Pension Slip: used to identify pension-based income, but should not occupy normal payslip checklist slots
- IC Front: used to identify the borrower

### 3. Identification Indicators

How the system can recognize the document.

These may include:

- keywords
- layout signals
- branding
- expected headings
- expected amount patterns
- expected date or month patterns

Indicators should be split into:

- strong indicators
- supporting indicators
- weak indicators

### 4. Required Extraction Fields

Fields that must be extracted for the result to be considered acceptable.

These are the fields Laravel will depend on for checklist assignment or business logic.

### 5. Optional Extraction Fields

Useful fields that improve completeness but are not mandatory for acceptance.

### 6. Acceptance Rules

What minimum conditions must be met before Laravel accepts the local result without Gemini fallback.

### 7. Fallback Triggers

What missing or weak signals should cause:

- Gemini fallback
- or direct manual review if Gemini is unavailable

### 8. Confusion Cases

What similar document types may be mistaken for this type.

Examples:

- payslip vs pension slip
- IC front vs IC back
- CTOS vs RAMCI
- EPF statement vs generic financial statement

### 9. Fixture Examples

Each type should have verified examples covering:

- clean digital PDF
- scanned PDF
- mobile photo
- compressed or low-quality image
- rotated image
- partial crop if common

## Canonical Fields To Standardize

The local Python service should still aim to return the same normalized structure Laravel currently expects.

Core classification fields:

- `document_type`
- `ic_side`
- `statement_year`
- `statement_month`
- `statement_period`
- `confidence`
- `needs_review`

Core extracted fields:

- `full_name`
- `ic_number`
- `date_of_birth`
- `address`
- `employer`
- `employment_type`
- `basic_salary`
- `gross_income`
- `net_pay`
- `total_deductions`

If a document type needs extra local-only fields later, they can be returned under additional metadata, but the current normalized Laravel-compatible shape should remain the baseline.

## Document-Specific Context To Prepare

## IC Front

### Business Purpose

- identify borrower
- extract identity fields

### Strong Identification Indicators

- person’s full name clearly visible
- national ID / IC number visible
- date of birth visible
- front-side identity-card layout

### Supporting Indicators

- gender / identity details
- portrait-side layout

### Required Extraction Fields

- `document_type = ic`
- `ic_side = front`
- `full_name` or `ic_number`

### Optional Fields

- `date_of_birth`
- `address`

### Acceptance Rules

Accept local result when:

- type is `ic`
- side is confidently `front`
- at least one strong identity field exists

### Fallback Triggers

- side unclear
- both `full_name` and `ic_number` missing
- front/back conflict

### Confusion Cases

- IC back
- poor crop that only shows partial card details

## IC Back

### Business Purpose

- identify reverse side of IC
- support IC completeness

### Strong Identification Indicators

- Touch 'n Go markers
- chip wording
- “Pendaftaran Negara”
- “Ketua Pengarah Pendaftaran Negara”
- reverse-side layout

### Supporting Indicators

- address field without normal front identity signals

### Required Extraction Fields

- `document_type = ic`
- `ic_side = back`

### Optional Fields

- `address`

### Acceptance Rules

Accept local result when:

- type is `ic`
- side is confidently `back`
- text/layout supports back-side markers

### Fallback Triggers

- side unclear
- front identity features dominate
- weak OCR with no back markers

### Confusion Cases

- IC front
- partial or low-quality reverse images

## Payslip

### Business Purpose

- verify monthly salary
- drive payslip checklist assignment
- support salary extraction for calculations

### Strong Identification Indicators

- payroll or salary-slip wording
- statement month or pay period
- employer name
- salary breakdown
- amounts such as:
  - gross income
  - basic salary
  - net pay
  - deductions

### Supporting Indicators

- employee name
- employee number
- fixed payroll layout

### Required Extraction Fields

- `document_type = payslip`
- `statement_period`
- one of:
  - `gross_income`
  - `basic_salary`

### Optional Fields

- `net_pay`
- `total_deductions`
- `employer`
- `employment_type`
- `ic_number`

### Acceptance Rules

Accept local result when:

- type is `payslip`
- `statement_period` is present in `YYYY-MM`
- at least one salary amount field exists

### Fallback Triggers

- missing `statement_period`
- both `gross_income` and `basic_salary` missing
- pension indicators detected
- OCR too weak to distinguish statement month

### Confusion Cases

- pension slip
- generic payroll-related letters
- low-quality screenshots of salary statements

## Pension Slip

### Business Purpose

- identify pension-based income documents
- keep them separate from normal salary payslip checklist logic

### Strong Identification Indicators

- `pencen`
- `slip pencen`
- `penyata pencen`
- `pesara`
- `pesaraan`
- `retirement pension`
- pension issuing authority wording

### Supporting Indicators

- `employment_type` suggests pensioner
- payment amount present without normal active-employment salary layout

### Required Extraction Fields

- `document_type = pension_slip`
- pension wording or pension-style context

### Optional Fields

- `statement_period`
- `full_name`
- pension payment amount
- issuing authority
- `employment_type`

### Acceptance Rules

Accept local result when:

- type is `pension_slip`
- pension wording is clear enough
- document does not look like a standard active-employment payslip

### Fallback Triggers

- pension vs payslip ambiguity
- weak pension keywords
- missing context on blurry scan

### Confusion Cases

- standard payslip
- government payroll document

## EPF Statement

### Business Purpose

- satisfy EPF checklist requirements
- identify statement year

### Strong Identification Indicators

- EPF / KWSP wording
- contribution-statement style layout
- statement year

### Supporting Indicators

- member contribution sections
- account-related yearly figures

### Required Extraction Fields

- `document_type = epf`
- `statement_year`

### Optional Fields

- `full_name`
- contribution-related totals

### Acceptance Rules

Accept local result when:

- type is `epf`
- `statement_year` exists

### Fallback Triggers

- year missing
- EPF wording weak
- OCR text incomplete

### Confusion Cases

- generic statement
- retirement-related statements

## CTOS Report

### Business Purpose

- satisfy CTOS checklist requirement

### Strong Identification Indicators

- CTOS branding
- credit-report style layout

### Supporting Indicators

- report sections and headings consistent with CTOS output

### Required Extraction Fields

- `document_type = ctos`

### Optional Fields

- `full_name`
- `ic_number`
- report reference information

### Acceptance Rules

Accept local result when:

- type is `ctos`
- branding or report structure is clear

### Fallback Triggers

- CTOS vs RAMCI ambiguity
- weak OCR
- unsupported report variation

### Confusion Cases

- RAMCI report
- other credit bureau documents

## RAMCI Report

### Business Purpose

- satisfy RAMCI checklist requirement

### Strong Identification Indicators

- RAMCI branding
- RAMCI report structure

### Supporting Indicators

- report headings and scoring sections

### Required Extraction Fields

- `document_type = ramci`

### Optional Fields

- `full_name`
- `ic_number`
- report reference information

### Acceptance Rules

Accept local result when:

- type is `ramci`
- branding or report structure is clear

### Fallback Triggers

- RAMCI vs CTOS ambiguity
- weak OCR
- unsupported report variation

### Confusion Cases

- CTOS report
- generic credit summary

## Unsupported / Unknown Documents

### Business Purpose

- identify files that should not be trusted for auto-classification

### Indicators

- no clear supported type
- mixed or low-quality content
- personal photos
- irrelevant documents

### Required Extraction Fields

- `document_type = other`

### Acceptance Rules

- not accepted as a usable checklist document

### Fallback Triggers

- Gemini fallback if potentially recoverable
- manual review if fallback unavailable or still unclear

## What To Prepare In The Verified Fixture Dataset

For every supported document type, prepare:

- at least a few clean digital examples
- scanned PDF examples
- camera-photo examples
- compressed examples
- rotated examples
- poor-quality examples
- confusion-case examples

The fixture set should be manually verified and labeled with:

- correct document type
- expected side where relevant
- expected statement period/year where relevant
- expected critical extracted fields
- whether the file should be accepted locally
- whether the file should trigger fallback
- whether the file should end in manual review if Gemini is unavailable

## What To Record During Tuning And Production

When testing or running the engine, record:

- local predicted document type
- local confidence
- local extracted fields
- acceptance decision
- fallback reason
- Gemini result when fallback occurs
- final outcome:
  - accepted locally
  - accepted by Gemini fallback
  - manual review

This helps improve Python over time using real reviewed failures.

## Suggested Working Template

Use this template for each document family:

```text
Document Type:
Business Purpose:

Strong Identification Indicators:
- ...

Supporting Indicators:
- ...

Required Fields:
- ...

Optional Fields:
- ...

Acceptance Rules:
- ...

Fallback Triggers:
- ...

Confusion Cases:
- ...

Fixture Examples Needed:
- clean PDF
- scanned PDF
- mobile photo
- rotated image
- compressed image
- unsupported lookalike
```

## Recommendation

Before implementation or Python tuning begins, prepare:

1. a document-by-document reference using the structure above
2. a verified fixture dataset
3. clear acceptance rules for each type
4. known confusion cases
5. a list of critical fields per type

This preparation will make the future Python-first engine:

- more accurate
- cheaper than Gemini-first processing
- easier to debug
- easier to tune over time
- less likely to break checklist assignment
