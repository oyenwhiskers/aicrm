# Document Intelligence Fixture Dataset

This folder is the canonical location for the manually verified document
fixtures used to tune and regression-test the Python-first document
classification and extraction pipeline.

## Purpose

The fixture dataset is used for:

- document classification tuning
- OCR and PDF extraction validation
- extraction regression checks
- acceptance-rule verification
- future Gemini-fallback targeting

## Required Dataset Coverage

The verified dataset should eventually include:

- IC front
- IC back
- payslip
- pension slip
- EPF statement
- CTOS report
- RAMCI or approved Experian report family
- unsupported documents
- mixed-document files

The dataset should also cover quality variations such as:

- machine-generated PDF
- scanned PDF
- photographed image
- rotated image
- compressed image
- blurred image
- cropped image
- multi-page file

## Structure

Use this folder structure:

```text
tests/Fixtures/document_intelligence/
  manifest.json
  README.md
  files/
    ic/
    payslip/
    pension_slip/
    epf/
    ctos/
    ramci/
    other/
    mixed/
```

The `manifest.json` file is the source of truth for fixture metadata.

## Manifest Rules

Every fixture entry must define:

- unique `id`
- relative `file_path`
- `document_type`
- expected outcome
- quality tags
- expected critical fields
- expected workflow readiness
- notes or reasoning where useful

## Verification Rule

Do not add a fixture entry unless:

- the file has been manually reviewed
- the expected type is confirmed
- the expected critical fields are agreed
- the expected workflow outcome is agreed

## Privacy Reminder

These documents may contain sensitive personal data.

Before adding any real file to this dataset:

- confirm it is legally and operationally permitted
- prefer redacted examples where possible
- avoid committing unnecessary personal information
- keep the manifest useful even when real binaries must be stored privately

## Current State

The repository now contains the dataset baseline:

- manifest location
- validation helper
- validation test
- fixture directory structure

Real verified files and real manifest entries should be added incrementally in
later tuning work.
