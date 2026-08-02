# Python Document Intelligence Service

This folder contains the Phase 2 skeleton for the future Python-first document
processing service.

Current purpose:

- define the HTTP contract Laravel will call
- provide a minimal local service skeleton
- prepare a clean integration seam before Python becomes the live primary provider

Expected endpoint:

- `POST /extract`

Expected request payload:

```json
{
  "document_id": 123,
  "lead_id": 10,
  "filename": "payslip-apr.pdf",
  "mime_type": "application/pdf",
  "storage_disk": "public",
  "storage_path": "leads/10/documents/payslip/payslip-apr.pdf",
  "shared_storage_roots": {
    "public": "/srv/lps/storage/app/public"
  },
  "allowed_storage_disks": ["public"],
  "source": "original",
  "mode": "primary"
}
```

Backward-compatible request during rollout:

```json
{
  "document_id": 123,
  "lead_id": 10,
  "filename": "payslip-apr.pdf",
  "mime_type": "application/pdf",
  "content_base64": "...",
  "source": "original",
  "mode": "primary"
}
```

Expected response shape:

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
  "fields": {},
  "raw_text": null,
  "provider_meta": {
    "provider": "python_local",
    "method": "stub",
    "timing_ms": 0
  }
}
```

Current implementation status:

- text and machine-readable binary content can be scanned locally
- shared-storage file references can now be opened locally by Python when Laravel passes a controlled storage disk/path
- PDFs now try proper text extraction first
- if PDF text is too weak, PDFs can fall back to page-level OCR when `PyMuPDF`, `Pillow`, and `pytesseract` are available
- first-pass rule-based classification is implemented
- image inputs now attempt OCR when `Pillow` and `pytesseract` are available
- invalid payloads and processing exceptions now return controlled review-required responses
- first-pass extraction is implemented for:
  - IC side and identity hints
  - payslip period and income fields
  - pension-slip detection
  - EPF year detection
  - CTOS and RAMCI issuer detection
- OCR is dependency-based and still basic
- PDF extraction is improved but still early-stage
- filename month/year may be used as a supporting hint when document text is weak

This means the current Python engine is intentionally conservative:

- text-heavy inputs can be classified locally
- image-heavy inputs can now be OCR-processed when dependencies exist, but accuracy will still need tuning
- PDFs should behave better than the earlier raw binary scan, but more tuning is still expected on real fixtures
- future phases should add OCR, richer PDF extraction, and tuning from real fixtures

## Local run note

This service can be started from either:

- the repository root using a package path, or
- the service folder directly

The import path in `app.py` is written to support both patterns safely.

## Shared storage configuration

For the Python-primary document flow, Laravel now passes a storage reference instead of reuploading the file bytes from worker memory.

Default environment variables:

```bash
PYTHON_DOCUMENT_INTELLIGENCE_SHARED_STORAGE_ENABLED_DISKS=public
PYTHON_DOCUMENT_INTELLIGENCE_SHARED_STORAGE_PUBLIC_ROOT=/srv/lps/storage/app/public
```

The Python service only resolves files inside the configured shared-storage root for allowed disks.
