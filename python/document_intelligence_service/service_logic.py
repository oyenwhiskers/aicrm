from __future__ import annotations

import base64
import binascii
import os
import re
import time
from datetime import date
from difflib import SequenceMatcher
from pathlib import Path
from typing import Optional

try:
    import pytesseract
except ImportError:  # pragma: no cover - optional dependency during early phases
    pytesseract = None

try:
    from PIL import Image
except ImportError:  # pragma: no cover - optional dependency during early phases
    Image = None

try:
    import fitz
except ImportError:  # pragma: no cover - optional dependency during early phases
    fitz = None

try:
    from pypdf import PdfReader
except ImportError:  # pragma: no cover - optional dependency during early phases
    PdfReader = None

from io import BytesIO


MONTH_MAP = {
    "jan": 1,
    "january": 1,
    "januari": 1,
    "feb": 2,
    "february": 2,
    "februari": 2,
    "mar": 3,
    "march": 3,
    "mac": 3,
    "apr": 4,
    "april": 4,
    "mei": 5,
    "may": 5,
    "jun": 6,
    "june": 6,
    "jul": 7,
    "july": 7,
    "ogos": 8,
    "aug": 8,
    "august": 8,
    "sep": 9,
    "sept": 9,
    "september": 9,
    "okt": 10,
    "oct": 10,
    "october": 10,
    "nov": 11,
    "november": 11,
    "dis": 12,
    "dec": 12,
    "december": 12,
}

CONTROLLED_OCR_PHRASES = [
    {
        "canonical": "kad pengenalan",
        "variants": [
            "kad pengenalan",
            "kad pengenalawn",
            "kad pengena1an",
            "kad pengena ian",
            "kadpengenalan",
            "kadpengenalawn",
        ],
        "threshold": 0.88,
    },
    {
        "canonical": "ketua pengarah pendaftaran negara",
        "variants": [
            "ketua pengarah pendaftaran negara",
            "ketua pengarahpendaftaran negara",
        ],
        "threshold": 0.9,
    },
    {
        "canonical": "pendaftaran negara",
        "variants": [
            "pendaftaran negara",
            "pendaftarannegara",
        ],
        "threshold": 0.9,
    },
]

IC_NAME_HEADER_BLACKLIST = {
    "kad pengenalan",
    "identity card",
    "malaysia",
    "mykad",
    "kad pengenalan malaysia",
    "malaysia identity card",
}

IC_METADATA_TERMS = {
    "warganegara",
    "citizen",
    "agama",
    "religion",
    "islam",
    "lelaki",
    "perempuan",
    "jantina",
    "gender",
    "alamat",
    "address",
    "touch n go",
    "touchngo",
    "ketua pengarah",
    "pendaftaran negara",
    "citizenship",
}

IC_ADDRESS_HINTS = {
    "no",
    "lot",
    "jalan",
    "lorong",
    "taman",
    "kampung",
    "bandar",
    "persiaran",
    "blok",
    "block",
    "unit",
    "tingkat",
    "flat",
    "pangsapuri",
    "apartment",
    "residence",
    "residensi",
    "kondo",
    "kondominium",
    "lebuh",
    "seksyen",
    "mukim",
    "daerah",
}

MALAYSIAN_STATE_TOKENS = {
    "johor",
    "selangor",
    "kedah",
    "kelantan",
    "melaka",
    "malacca",
    "negeri sembilan",
    "n. sembilan",
    "pahang",
    "perak",
    "perlis",
    "pulau pinang",
    "penang",
    "sabah",
    "sarawak",
    "terengganu",
    "wilayah persekutuan",
    "kuala lumpur",
    "labuan",
    "putrajaya",
}


def process_document(
    *,
    content_base64: str,
    mime_type: Optional[str] = None,
    filename: Optional[str] = None,
    source: Optional[str] = None,
) -> dict:
    started = time.perf_counter()
    try:
        payload = base64.b64decode(content_base64, validate=True)
    except (binascii.Error, ValueError):
        return technical_failure_result(
            summary="Document payload could not be decoded from base64.",
            method="invalid_base64",
            filename=filename,
            source=source,
            started=started,
        )

    try:
        raw_text, extraction_method = extract_text(payload, mime_type, filename)
        result = analyze_text(raw_text, filename=filename)
    except Exception as exc:  # pragma: no cover - protective service boundary
        return technical_failure_result(
            summary=f"Local document processing failed: {exc}",
            method="processing_exception",
            filename=filename,
            source=source,
            started=started,
        )

    elapsed_ms = int((time.perf_counter() - started) * 1000)

    diagnostics = result.pop("diagnostics", {})
    provider_meta = {
        "provider": "python_local",
        "method": extraction_method,
        "timing_ms": elapsed_ms,
        "source": source,
        "filename": filename,
        **diagnostics,
    }

    return {
        **result,
        "provider_meta": provider_meta,
    }


def process_document_from_storage_reference(
    *,
    storage_disk: str,
    storage_path: str,
    shared_storage_roots: dict[str, str],
    allowed_storage_disks: list[str],
    mime_type: Optional[str] = None,
    filename: Optional[str] = None,
    source: Optional[str] = None,
) -> dict:
    started = time.perf_counter()

    try:
        resolved_path = resolve_storage_reference(
            storage_disk=storage_disk,
            storage_path=storage_path,
            shared_storage_roots=shared_storage_roots,
            allowed_storage_disks=allowed_storage_disks,
        )
    except SharedStorageResolutionError as exc:
        return technical_failure_result(
            summary=f"Local document processing failed: {exc}",
            method="shared_storage_resolution_failed",
            filename=filename,
            source=source,
            started=started,
            review_reasons=[exc.reason_code],
        )

    if not resolved_path.exists() or not resolved_path.is_file():
        return technical_failure_result(
            summary="Local document processing failed: shared storage file could not be opened.",
            method="shared_storage_unavailable",
            filename=filename,
            source=source,
            started=started,
            review_reasons=["shared_storage_unavailable"],
        )

    try:
        payload = resolved_path.read_bytes()
        raw_text, extraction_method = extract_text(payload, mime_type, filename)
        result = analyze_text(raw_text, filename=filename)
    except Exception as exc:  # pragma: no cover - protective service boundary
        return technical_failure_result(
            summary=f"Local document processing failed: {exc}",
            method="processing_exception",
            filename=filename,
            source=source,
            started=started,
        )

    elapsed_ms = int((time.perf_counter() - started) * 1000)
    diagnostics = result.pop("diagnostics", {})
    provider_meta = {
        "provider": "python_local",
        "method": extraction_method,
        "timing_ms": elapsed_ms,
        "source": source,
        "filename": filename,
        "input_source": "shared_storage",
        "storage_disk": storage_disk,
        "storage_path": storage_path,
        "shared_storage_open_method": "local_file_read",
        **diagnostics,
    }

    return {
        **result,
        "provider_meta": provider_meta,
    }


def extract_text(payload: bytes, mime_type: Optional[str], filename: Optional[str]) -> tuple[str, str]:
    mime = (mime_type or "").lower()
    lower_name = (filename or "").lower()

    if mime.startswith("text/") or lower_name.endswith(".txt"):
        return preserve_multiline_text(payload.decode("utf-8", errors="ignore")), "text_decode"

    if mime == "application/pdf" or lower_name.endswith(".pdf"):
        return extract_text_from_pdf(payload)

    if mime.startswith("image/") or lower_name.endswith((".jpg", ".jpeg", ".png", ".webp")):
        return extract_text_from_image(payload)

    return "", "unimplemented_ocr"


def extract_text_from_image(payload: bytes) -> tuple[str, str]:
    if Image is None or pytesseract is None:
        return "", "ocr_unavailable"

    image = Image.open(BytesIO(payload))
    # Basic normalization only. Later phases can add rotation correction,
    # grayscale, thresholding, and page splitting once real fixtures exist.
    ocr_image = image.convert("L")
    text = pytesseract.image_to_string(ocr_image, config="--psm 6")

    return preserve_multiline_text(text), "image_ocr"


def extract_text_from_pdf(payload: bytes) -> tuple[str, str]:
    direct_text = extract_text_from_pdf_reader(payload)
    direct_quality = score_text_quality(direct_text)

    if direct_quality >= 45:
        return direct_text, "pdf_text"

    ocr_text = extract_text_from_pdf_ocr(payload)
    ocr_quality = score_text_quality(ocr_text)

    if ocr_quality >= direct_quality and ocr_quality > 0:
        return ocr_text, "pdf_ocr"

    if direct_quality > 0:
        return direct_text, "pdf_text_weak"

    binary_text = extract_text_from_binary(payload)
    binary_quality = score_text_quality(binary_text)

    if binary_quality > 0:
        return binary_text, "binary_text_scan"

    return "", "pdf_unreadable"


def extract_text_from_pdf_reader(payload: bytes) -> str:
    if PdfReader is None:
        return ""

    reader = PdfReader(BytesIO(payload))
    page_text = []

    for page in reader.pages:
        extracted = page.extract_text() or ""
        if extracted:
            page_text.append(extracted)

    return preserve_multiline_text("\n".join(page_text))


def extract_text_from_pdf_ocr(payload: bytes) -> str:
    if fitz is None or Image is None or pytesseract is None:
        return ""

    document = fitz.open(stream=payload, filetype="pdf")
    page_text = []

    for page in document:
        pixmap = page.get_pixmap(matrix=fitz.Matrix(2, 2), alpha=False)
        image = Image.open(BytesIO(pixmap.tobytes("png")))
        ocr_image = image.convert("L")
        extracted = pytesseract.image_to_string(ocr_image, config="--psm 6")
        if extracted:
            page_text.append(extracted)

    return preserve_multiline_text("\n".join(page_text))


def extract_text_from_binary(payload: bytes) -> str:
    chunks = re.findall(rb"[A-Za-z0-9\/\-\:\,\.\(\)\@\&\+\s]{4,}", payload)
    text = " ".join(chunk.decode("latin1", errors="ignore") for chunk in chunks)

    return normalize_text(text)


def normalize_text(value: str) -> str:
    return re.sub(r"\s+", " ", value).strip()


def preserve_multiline_text(value: str) -> str:
    lines = [normalize_text(line) for line in re.split(r"[\r\n]+", value)]
    return "\n".join(line for line in lines if line)


def analyze_text(raw_text: str, filename: Optional[str] = None) -> dict:
    normalized = normalize_text(raw_text)
    lowered = normalized.lower()

    if not normalized:
        return build_result(
            document_type="other",
            confidence="low",
            needs_review=True,
            summary="No readable text could be extracted from the document.",
            fields={},
            ic_side=None,
            statement_year=None,
            statement_month=None,
            statement_period=None,
            raw_text=raw_text,
            review_reasons=["unreadable_document"],
            diagnostics={},
        )

    signals = collect_signal_matches(lowered)
    scores = score_document_types(signals, lowered)
    document_type, confidence, decision_reasons = choose_document_type(scores, signals)
    fields = extract_common_fields(normalized, lowered, document_type, raw_text=raw_text)
    statement_year, statement_month, statement_period = extract_statement_period(lowered, filename)
    ic_side = detect_ic_side(signals) if document_type == "ic" and signals["has_strong_ic_evidence"] else None
    contradictory_reasons = contradictory_document_reasons(document_type, signals, statement_period, fields)

    if document_type == "epf" and statement_year is None:
        statement_year = extract_year(lowered)

    if document_type == "pension_slip":
        fields.setdefault("employment_type", "Pensioner")

    review_reasons = determine_review_reasons(
        document_type=document_type,
        confidence=confidence,
        fields=fields,
        ic_side=ic_side,
        statement_year=statement_year,
        statement_period=statement_period,
        decision_reasons=decision_reasons + contradictory_reasons,
        signals=signals,
    )
    needs_review = should_require_review(document_type, review_reasons)
    diagnostics = build_diagnostics(
        signals=signals,
        scores=scores,
        document_type=document_type,
        confidence=confidence,
        ic_side=ic_side,
        raw_text=normalized,
        fields=fields,
    )

    summary = build_summary(document_type, confidence, needs_review, statement_period, ic_side)

    return build_result(
        document_type=document_type,
        confidence=confidence,
        needs_review=needs_review,
        summary=summary,
        fields=fields,
        ic_side=ic_side,
        statement_year=statement_year,
        statement_month=statement_month,
        statement_period=statement_period,
        raw_text=raw_text,
        review_reasons=review_reasons,
        diagnostics=diagnostics,
    )


def collect_signal_matches(text: str) -> dict[str, object]:
    compacted = compact_text(text)
    normalized_phrase_matches = find_controlled_phrase_matches(text)

    payslip_title = match_keywords(text, ["payslip", "pay slip", "salary slip", "slip gaji", "payroll", "penyata gaji"], compacted)
    payslip_amount = match_keywords(text, ["basic salary", "basic pay", "gross pay", "gross salary", "net pay", "gaji pokok", "gaji bersih"], compacted)
    payslip_structure = match_keywords(
        text,
        ["pendapatan", "potongan", "elaun", "jumlah", "jawatan", "majikan", "no pekerja", "taraf jawatan", "gaji"],
        compacted,
    )
    payslip_deduction = match_keywords(text, ["kwsp", "epf", "socso", "perkeso", "pcb", "deduction", "elaun", "allowance"], compacted)

    pension_markers = match_keywords(text, ["slip pencen", "penyata pencen", "bayaran pencen", "pesara", "pesaraan", "pension", "retirement"], compacted)
    epf_markers = match_keywords(text, ["kwsp", "employees provident fund", "kumpulan wang simpanan pekerja", "member statement", "penyata ahli", "caruman", "contribution statement"], compacted)
    ctos_markers = match_keywords(text, ["ctos", "myctos", "ctos data systems"], compacted)
    ramci_markers = match_keywords(text, ["ramci", "ram credit information", "experian", "experian information services malaysia"], compacted)
    credit_markers = match_keywords(text, ["credit report", "credit facilities", "outstanding", "repayment", "arrears", "enquiry", "legal", "bankruptcy"], compacted)

    normalized_front_markers = [
        match["normalized_marker"]
        for match in normalized_phrase_matches
        if match["normalized_marker"] == "kad pengenalan"
    ]
    normalized_back_markers = [
        match["normalized_marker"]
        for match in normalized_phrase_matches
        if match["normalized_marker"] in {"ketua pengarah pendaftaran negara", "pendaftaran negara"}
    ]

    ic_front_markers = deduplicate_preserve_order(
        match_keywords(text, ["mykad", "warganegara", "citizen", "identity card"], compacted)
        + normalized_front_markers
    )
    ic_back_markers = deduplicate_preserve_order(
        match_keywords(text, ["ketua pengarah pendaftaran negara", "pendaftaran negara", "alamat", "address"], compacted)
        + normalized_back_markers
    )
    incidental_ic_markers = match_keywords(text, ["touch n go", "touchngo", "chip", "80k chip"], compacted)
    identity_label_markers = match_keywords(text, ["nama", "name", "no kp", "nric", "ic no"], compacted)
    has_ic_number = find_ic_number(text)
    contradictory_payroll_markers = deduplicate_preserve_order(payslip_title + payslip_amount + payslip_structure + payslip_deduction)
    contradictory_credit_markers = deduplicate_preserve_order(ctos_markers + ramci_markers + credit_markers)
    contradictory_epf_markers = epf_markers[:]

    payroll_bundle_groups = sum(
        1
        for matches in [payslip_title, payslip_amount, payslip_structure, payslip_deduction]
        if matches
    )

    return {
        "payslip_title": payslip_title,
        "payslip_amount": payslip_amount,
        "payslip_structure": payslip_structure,
        "payslip_deduction": payslip_deduction,
        "pension_markers": pension_markers,
        "epf_markers": epf_markers,
        "ctos_markers": ctos_markers,
        "ramci_markers": ramci_markers,
        "credit_markers": credit_markers,
        "ic_front_markers": ic_front_markers,
        "ic_back_markers": ic_back_markers,
        "incidental_ic_markers": incidental_ic_markers,
        "identity_label_markers": identity_label_markers,
        "ic_number": has_ic_number,
        "normalized_phrase_matches": normalized_phrase_matches,
        "has_strong_ic_evidence": bool(ic_front_markers or ic_back_markers),
        "has_payroll_bundle": payroll_bundle_groups >= 2 or len(payslip_structure) >= 3,
        "payroll_bundle_groups": payroll_bundle_groups,
        "contradictory_payroll_markers": contradictory_payroll_markers,
        "contradictory_credit_markers": contradictory_credit_markers,
        "contradictory_epf_markers": contradictory_epf_markers,
    }


def score_document_types(signals: dict[str, object], text: str) -> dict[str, int]:
    payslip_title = len(signals["payslip_title"])
    payslip_amount = len(signals["payslip_amount"])
    payslip_structure = len(signals["payslip_structure"])
    payslip_deduction = len(signals["payslip_deduction"])

    pension_markers = len(signals["pension_markers"])
    epf_markers = len(signals["epf_markers"])
    ctos_markers = len(signals["ctos_markers"])
    ramci_markers = len(signals["ramci_markers"])
    credit_markers = len(signals["credit_markers"])

    ic_front_markers = len(signals["ic_front_markers"])
    ic_back_markers = len(signals["ic_back_markers"])
    identity_label_markers = len(signals["identity_label_markers"])
    has_ic_number = 1 if signals["ic_number"] else 0
    has_strong_ic_evidence = bool(signals["has_strong_ic_evidence"])

    ic_score = (ic_front_markers + ic_back_markers) * 3
    if has_strong_ic_evidence:
        ic_score += min(identity_label_markers, 1)
        ic_score += has_ic_number

    payslip_score = payslip_title * 4 + payslip_amount * 2 + payslip_structure + payslip_deduction
    if signals["has_payroll_bundle"]:
        payslip_score += 3

    return {
        "ic": ic_score,
        "pension_slip": pension_markers * 4 + payslip_amount,
        "payslip": payslip_score,
        "epf": epf_markers * 3 + len(match_keywords(text, ["balance", "dividend", "withdrawal", "akaun", "member", "employer contribution"], compact_text(text))),
        "ctos": ctos_markers * 4 + credit_markers,
        "ramci": ramci_markers * 4 + credit_markers,
        "other": 0,
    }


def score_text_quality(text: str) -> int:
    normalized = normalize_text(text)

    if not normalized:
        return 0

    letters = sum(1 for char in normalized if char.isalpha())
    digits = sum(1 for char in normalized if char.isdigit())
    length_score = min(len(normalized), 400) // 10
    alpha_ratio_bonus = 15 if letters >= max(20, digits) else 0
    keyword_bonus = sum(
        8
        for keyword in [
            "payslip",
            "salary",
            "gross pay",
            "gross salary",
            "net pay",
            "kwsp",
            "epf",
            "ctos",
            "ramci",
            "experian",
            "kumpulan wang simpanan pekerja",
            "member statement",
        ]
        if keyword in normalized.lower()
    )
    garbage_penalty = 20 if normalized.lower().count("endobj") >= 3 or normalized.lower().count("stream") >= 3 else 0

    return max(0, length_score + alpha_ratio_bonus + keyword_bonus - garbage_penalty)


def choose_document_type(scores: dict[str, int], signals: dict[str, object]) -> tuple[str, str, list[str]]:
    ranked = sorted(scores.items(), key=lambda item: item[1], reverse=True)
    best_type, best_score = ranked[0]
    second_score = ranked[1][1] if len(ranked) > 1 else 0
    score_margin = best_score - second_score

    if (
        signals["has_payroll_bundle"]
        and scores["payslip"] >= 3
        and scores["payslip"] >= scores["ic"] + 2
    ):
        confidence = "high" if scores["payslip"] >= 8 else "medium"

        return "payslip", confidence, []

    if best_score <= 1:
        if signals["ic_number"] or signals["identity_label_markers"] or signals["incidental_ic_markers"]:
            return "other", "low", ["weak_ic_evidence", "uncertain_document_type"]

        return "other", "low", ["unsupported_document"]

    if best_type == "ic" and not signals["has_strong_ic_evidence"]:
        return "other", "low", ["weak_ic_evidence", "uncertain_document_type"]

    if score_margin <= 1:
        if best_score >= 3 and second_score >= 3:
            return "other", "low", ["low_margin_conflict", "mixed_document"]

        return "other", "low", ["low_margin_conflict", "uncertain_document_type"]

    if best_type == "ic" and qualifies_for_high_ic_confidence(signals, score_margin):
        return "ic", "high", []

    if best_score >= 8 and best_score - second_score >= 2:
        return best_type, "high", []

    return best_type, "medium", []


def extract_common_fields(text: str, lowered: str, document_type: str, raw_text: Optional[str] = None) -> dict:
    fields = {
        "full_name": extract_name(text),
        "ic_number": find_ic_number(text),
        "date_of_birth": extract_date_of_birth(text),
        "address": extract_address(text),
        "gender": None,
        "employer": extract_labeled_value(text, ["employer", "company", "majikan"]),
        "employment_type": extract_labeled_value(text, ["employment type", "employment status", "jenis pekerjaan"]),
        "basic_salary": extract_amount(text, ["basic salary", "basic pay", "gaji pokok"]),
        "gross_income": extract_amount(text, ["gross income", "gross pay", "gross salary", "pendapatan kasar"]),
        "net_pay": extract_amount(text, ["net pay", "net salary", "gaji bersih"]),
        "total_deductions": extract_amount(text, ["total deductions", "jumlah potongan", "deduction"]),
    }

    if document_type == "pension_slip" and not fields["employment_type"]:
        fields["employment_type"] = "Pensioner"

    if document_type == "ic":
        ic_fields = extract_ic_fields(text, raw_text or text)
        fields.update(ic_fields)

    return fields


def extract_ic_fields(text: str, raw_text: str) -> dict:
    lines = extract_candidate_lines(raw_text or text)
    ic_number = find_ic_number(text)
    derived_birth_date = derive_birth_date_from_ic_number(ic_number)
    derived_gender = derive_gender_from_ic_number(ic_number)
    address_lines = extract_ic_address_lines(lines)

    return {
        "full_name": extract_ic_name(lines, ic_number),
        "ic_number": ic_number,
        "date_of_birth": derived_birth_date or extract_date_of_birth(text),
        "address": " ".join(address_lines) if address_lines else None,
        "gender": derived_gender,
    }


def extract_candidate_lines(raw_text: str) -> list[str]:
    normalized_lines = [
        normalize_text(line)
        for line in re.split(r"[\r\n]+", raw_text or "")
    ]
    lines = [line for line in normalized_lines if line]

    if lines:
        return lines

    normalized = normalize_text(raw_text)
    if not normalized:
        return []

    # Fallback for flattened OCR text when no line breaks survive.
    synthetic = re.split(r"(?<=\d{6}-\d{2}-\d{4})\s+|(?<=\b(?:LELAKI|PEREMPUAN|WARGANEGARA|ISLAM))\s+", normalized, flags=re.IGNORECASE)
    return [normalize_text(line) for line in synthetic if normalize_text(line)]


def extract_ic_name(lines: list[str], ic_number: Optional[str]) -> Optional[str]:
    candidates: list[tuple[int, str]] = []

    for index, line in enumerate(lines):
        lowered = line.lower()
        if is_header_or_title_line(lowered):
            continue
        if ic_number and compact_digits(line) == compact_digits(ic_number):
            continue
        if is_non_name_metadata_line(lowered):
            continue
        if is_address_like_line(lowered):
            continue

        cleaned = clean_name(line)
        if not cleaned:
            continue

        score = 0
        if looks_like_person_name(cleaned):
            score += 4
        if cleaned.isupper():
            score += 1
        if 2 <= len(cleaned.split()) <= 6:
            score += 2
        if any(token in lowered for token in ["bin", "binti", "a/l", "a/p"]):
            score += 2
        if index <= 4:
            score += 1

        if score >= 5:
            candidates.append((score, cleaned))

    if not candidates:
        return None

    candidates.sort(key=lambda item: item[0], reverse=True)
    return candidates[0][1][:120]


def extract_ic_address_lines(lines: list[str]) -> list[str]:
    best_block: list[str] = []
    current_block: list[str] = []

    for line in lines:
        lowered = line.lower()
        if is_address_like_line(lowered):
            current_block.append(normalize_address_line(line))
            continue

        if current_block:
            if address_signal_count(current_block) > address_signal_count(best_block):
                best_block = current_block[:]
            current_block = []

    if current_block and address_signal_count(current_block) > address_signal_count(best_block):
        best_block = current_block[:]

    if address_signal_count(best_block) < 2:
        return []

    return deduplicate_preserve_order(best_block)


def extract_name(text: str) -> Optional[str]:
    for label in ["full name", "name", "nama", "employee name", "member name"]:
        value = extract_labeled_value(text, [label])
        if value:
            return clean_name(value)

    uppercase_match = re.search(r"\b([A-Z][A-Z\s]{6,})\b", text)
    if uppercase_match:
        return clean_name(uppercase_match.group(1))

    return None


def clean_name(value: str) -> Optional[str]:
    cleaned = normalize_text(re.sub(r"[^A-Za-z@\.\-\/\s]", " ", value))
    if len(cleaned) < 4:
        return None

    return cleaned[:120]


def looks_like_person_name(value: str) -> bool:
    tokens = [token for token in re.split(r"\s+", value) if token]
    if not (2 <= len(tokens) <= 8):
        return False

    if any(token.isdigit() for token in tokens):
        return False

    return all(re.fullmatch(r"[A-Za-z@.\-\/]+", token) for token in tokens)


def extract_labeled_value(text: str, labels: list[str]) -> Optional[str]:
    for label in labels:
        pattern = re.compile(rf"{re.escape(label)}\s*[:\-]?\s*([A-Za-z0-9\/\-\.,\(\)\s]{{3,80}})", re.IGNORECASE)
        match = pattern.search(text)
        if match:
            return normalize_text(match.group(1))

    return None


def extract_amount(text: str, labels: list[str]) -> Optional[float]:
    for label in labels:
        pattern = re.compile(
            rf"{re.escape(label)}\s*[:\-]?\s*(?:rm\s*)?([0-9]+(?:,[0-9]{{3}})*(?:\.[0-9]{{2}})?)",
            re.IGNORECASE,
        )
        match = pattern.search(text)
        if match:
            return parse_amount(match.group(1))

    return None


def parse_amount(value: str) -> Optional[float]:
    try:
        return float(value.replace(",", ""))
    except ValueError:
        return None


def find_ic_number(text: str) -> Optional[str]:
    match = re.search(r"\b(\d{6}-?\d{2}-?\d{4})\b", text)
    if match:
        return match.group(1)

    return None


def extract_date_of_birth(text: str) -> Optional[str]:
    match = re.search(r"\b(\d{4}-\d{2}-\d{2})\b", text)
    if match:
        return match.group(1)

    return None


def extract_address(text: str) -> Optional[str]:
    value = extract_labeled_value(text, ["address", "alamat"])
    if value:
        return value

    return None


def compact_digits(value: str) -> str:
    return re.sub(r"\D+", "", value or "")


def is_header_or_title_line(lowered_line: str) -> bool:
    compacted = compact_text(lowered_line)
    return any(compact_text(phrase) in compacted for phrase in IC_NAME_HEADER_BLACKLIST)


def is_non_name_metadata_line(lowered_line: str) -> bool:
    if any(term in lowered_line for term in IC_METADATA_TERMS):
        return True
    return bool(re.search(r"\b\d{6}-?\d{2}-?\d{4}\b", lowered_line))


def is_address_like_line(lowered_line: str) -> bool:
    if not lowered_line or is_header_or_title_line(lowered_line):
        return False

    if any(term in lowered_line for term in {"warganegara", "agama", "religion", "islam", "lelaki", "perempuan"}):
        return False

    return address_line_score(lowered_line) >= 2


def address_line_score(lowered_line: str) -> int:
    score = 0

    if re.search(r"\b\d{5}\b", lowered_line):
        score += 2
    if any(hint in lowered_line for hint in IC_ADDRESS_HINTS):
        score += 2
    if any(state in lowered_line for state in MALAYSIAN_STATE_TOKENS):
        score += 2
    if re.search(r"\b[a-z]{2,}\d+[a-z0-9\-\/]*\b", lowered_line):
        score += 1
    if re.search(r"\b\d+\b", lowered_line):
        score += 1

    return score


def address_signal_count(lines: list[str]) -> int:
    return sum(address_line_score(line.lower()) for line in lines)


def normalize_address_line(line: str) -> str:
    return normalize_text(re.sub(r"\s+", " ", line)).upper()


def derive_birth_date_from_ic_number(ic_number: Optional[str]) -> Optional[str]:
    digits = compact_digits(ic_number)
    if len(digits) != 12:
        return None

    yy = int(digits[0:2])
    mm = int(digits[2:4])
    dd = int(digits[4:6])
    today = date.today()
    candidate_years = [1900 + yy, 2000 + yy]

    valid_dates: list[date] = []
    for year in candidate_years:
        try:
            candidate = date(year, mm, dd)
        except ValueError:
            continue

        age = today.year - candidate.year - ((today.month, today.day) < (candidate.month, candidate.day))
        if 0 <= age <= 120 and candidate <= today:
            valid_dates.append(candidate)

    if not valid_dates:
        return None

    chosen = min(valid_dates, key=lambda candidate: abs((today.year - candidate.year) - 40))
    return chosen.isoformat()


def derive_gender_from_ic_number(ic_number: Optional[str]) -> Optional[str]:
    digits = compact_digits(ic_number)
    if len(digits) != 12 or derive_birth_date_from_ic_number(ic_number) is None:
        return None

    return "male" if int(digits[-1]) % 2 == 1 else "female"


def extract_statement_period(text: str, filename: Optional[str] = None) -> tuple[Optional[int], Optional[int], Optional[str]]:
    direct_period = re.search(r"\b(20\d{2})[-\/](0[1-9]|1[0-2])\b", text)
    if direct_period:
        year = int(direct_period.group(1))
        month = int(direct_period.group(2))
        return year, month, f"{year:04d}-{month:02d}"

    month_name_pattern = re.compile(
        r"\b(" + "|".join(sorted(MONTH_MAP.keys(), key=len, reverse=True)) + r")\b[\s\-\/,]*(20\d{2})",
        re.IGNORECASE,
    )
    month_match = month_name_pattern.search(text)
    if month_match:
        month = MONTH_MAP[month_match.group(1).lower()]
        year = int(month_match.group(2))
        return year, month, f"{year:04d}-{month:02d}"

    reverse_pattern = re.compile(
        r"\b(20\d{2})[\s\-\/,]*(" + "|".join(sorted(MONTH_MAP.keys(), key=len, reverse=True)) + r")\b",
        re.IGNORECASE,
    )
    reverse_match = reverse_pattern.search(text)
    if reverse_match:
        year = int(reverse_match.group(1))
        month = MONTH_MAP[reverse_match.group(2).lower()]
        return year, month, f"{year:04d}-{month:02d}"

    if filename:
        filename_period = extract_statement_period_from_filename(filename)
        if filename_period != (None, None, None):
            return filename_period

    return None, None, None


def extract_statement_period_from_filename(filename: str) -> tuple[Optional[int], Optional[int], Optional[str]]:
    lowered = filename.lower()

    month_name_pattern = re.compile(
        r"\b(" + "|".join(sorted(MONTH_MAP.keys(), key=len, reverse=True)) + r")\b[\s\-_]*(\d{2}|\d{4})",
        re.IGNORECASE,
    )
    match = month_name_pattern.search(lowered)
    if not match:
        return None, None, None

    month = MONTH_MAP[match.group(1).lower()]
    raw_year = match.group(2)
    year = int(raw_year)

    if year < 100:
        year += 2000

    if year < 2000 or year > 2099:
        return None, None, None

    return year, month, f"{year:04d}-{month:02d}"


def extract_year(text: str) -> Optional[int]:
    match = re.search(r"\b(20\d{2})\b", text)
    if match:
        return int(match.group(1))

    return None


def detect_ic_side(signals: dict[str, object]) -> Optional[str]:
    front_score = len(signals["ic_front_markers"])
    back_score = len(signals["ic_back_markers"])

    if back_score >= front_score + 1:
        return "back"
    if front_score >= back_score + 1:
        return "front"

    return "uncertain"


def determine_review_reasons(
    *,
    document_type: str,
    confidence: str,
    fields: dict,
    ic_side: Optional[str],
    statement_year: Optional[int],
    statement_period: Optional[str],
    decision_reasons: list[str],
    signals: dict[str, object],
) -> list[str]:
    reasons = list(dict.fromkeys(decision_reasons))

    if document_type == "other":
        if "unsupported_document" in reasons and len(reasons) == 1:
            return reasons

        if not any(reason in reasons for reason in ["uncertain_document_type", "mixed_document"]):
            reasons.append("uncertain_document_type")

        return reasons

    if confidence == "low" and "low_confidence_classification" not in reasons:
        reasons.append("low_confidence_classification")

    if document_type == "ic":
        if not signals["has_strong_ic_evidence"] and "weak_ic_evidence" not in reasons:
            reasons.append("weak_ic_evidence")

        if ic_side not in {"front", "back"}:
            reasons.append("ic_side_uncertain")

        if not fields.get("ic_number"):
            reasons.append("missing_ic_identity_fields")

        if not fields.get("full_name"):
            reasons.append("missing_ic_name")

        if not fields.get("address") and ic_side == "front":
            reasons.append("missing_ic_address")

        if fields.get("ic_number") and not fields.get("date_of_birth"):
            reasons.append("invalid_ic_birth_date")

        return list(dict.fromkeys(reasons))

    if document_type == "payslip":
        if statement_period is None:
            reasons.append("missing_statement_period")

        if not (
            fields.get("basic_salary") is not None
            or fields.get("gross_income") is not None
            or fields.get("net_pay") is not None
        ):
            reasons.append("missing_payslip_income_fields")

        return list(dict.fromkeys(reasons))

    if document_type == "pension_slip":
        pension_identity = any(
            keyword in (fields.get("employment_type") or "").lower()
            for keyword in ["pension", "pensioner"]
        )
        if not pension_identity:
            reasons.append("pension_identity_uncertain")

        return list(dict.fromkeys(reasons))

    if document_type == "epf" and statement_year is None:
        reasons.append("missing_epf_statement_year")

    if document_type in {"ctos", "ramci"} and confidence != "high":
        reasons.append("issuer_confidence_uncertain")

    return list(dict.fromkeys(reasons))


def build_summary(
    document_type: str,
    confidence: str,
    needs_review: bool,
    statement_period: Optional[str],
    ic_side: Optional[str],
) -> str:
    if document_type == "ic":
        side = ic_side or "uncertain side"
        return f"Detected Malaysian IC document with {side} classification at {confidence} confidence."

    if document_type in {"payslip", "pension_slip"} and statement_period:
        return f"Detected {document_type.replace('_', ' ')} for period {statement_period} at {confidence} confidence."

    if needs_review:
        return f"Detected {document_type.replace('_', ' ')} with unresolved fields requiring review."

    return f"Detected {document_type.replace('_', ' ')} at {confidence} confidence."


def build_result(
    *,
    document_type: str,
    confidence: str,
    needs_review: bool,
    summary: str,
    fields: dict,
    ic_side: Optional[str],
    statement_year: Optional[int],
    statement_month: Optional[int],
    statement_period: Optional[str],
    raw_text: str,
    review_reasons: list[str],
    diagnostics: dict,
) -> dict:
    return {
        "summary": summary,
        "confidence": confidence,
        "needs_review": needs_review,
        "review_reasons": review_reasons,
        "classification": {
            "document_type": document_type,
            "ic_side": ic_side,
            "statement_year": statement_year,
            "statement_month": statement_month,
            "statement_period": statement_period,
        },
        "fields": fields,
        "raw_text": raw_text,
        "diagnostics": diagnostics,
    }


def compact_text(value: str) -> str:
    return re.sub(r"\s+", "", value.lower())


def keyword_in_text(text: str, keyword: str) -> bool:
    escaped = re.escape(keyword).replace(r"\ ", r"\s+")
    pattern = re.compile(rf"(?<!\w){escaped}(?!\w)", re.IGNORECASE)

    return bool(pattern.search(text))


def match_keywords(text: str, keywords: list[str], compacted: Optional[str] = None) -> list[str]:
    matched = []
    compacted = compacted or compact_text(text)

    for keyword in keywords:
        if keyword_in_text(text, keyword) or compact_text(keyword) in compacted:
            matched.append(keyword)

    return matched


def deduplicate_preserve_order(values: list[str]) -> list[str]:
    return list(dict.fromkeys(values))


def find_controlled_phrase_matches(text: str) -> list[dict[str, object]]:
    words = text.split()
    matches: list[dict[str, object]] = []

    for phrase in CONTROLLED_OCR_PHRASES:
        canonical = phrase["canonical"]
        if canonical in text:
            matches.append(
                {
                    "normalized_marker": canonical,
                    "original_text": canonical,
                    "match_method": "exact_phrase",
                    "score": 1.0,
                }
            )
            continue

        variants = phrase["variants"]
        token_count = len(canonical.split())
        candidates = build_phrase_candidates(words, token_count)

        best_match = None
        best_score = 0.0

        for candidate in candidates:
            candidate_compacted = compact_text(candidate)
            for variant in variants:
                score = SequenceMatcher(None, candidate_compacted, compact_text(variant)).ratio()
                if score >= phrase["threshold"] and score > best_score:
                    best_score = score
                    best_match = {
                        "normalized_marker": canonical,
                        "original_text": candidate,
                        "match_method": "controlled_fuzzy_match",
                        "score": round(score, 3),
                    }

        if best_match is not None:
            matches.append(best_match)

    return matches


def build_phrase_candidates(words: list[str], token_count: int) -> list[str]:
    candidates: list[str] = []

    for width in {max(1, token_count - 1), token_count, token_count + 1}:
        for index in range(0, max(len(words) - width + 1, 0)):
            candidates.append(" ".join(words[index:index + width]))

    return deduplicate_preserve_order(candidates)


class SharedStorageResolutionError(Exception):
    def __init__(self, message: str, reason_code: str) -> None:
        super().__init__(message)
        self.reason_code = reason_code


def resolve_storage_reference(
    *,
    storage_disk: str,
    storage_path: str,
    shared_storage_roots: dict[str, str],
    allowed_storage_disks: list[str],
) -> Path:
    if storage_disk not in allowed_storage_disks:
        raise SharedStorageResolutionError(
            f"storage disk [{storage_disk}] is not enabled for shared document processing.",
            "shared_storage_disk_not_allowed",
        )

    root = shared_storage_roots.get(storage_disk)

    if not root:
        raise SharedStorageResolutionError(
            f"shared storage root is not configured for disk [{storage_disk}].",
            "shared_storage_root_not_configured",
        )

    normalized_root = Path(root).resolve()
    candidate_path = (normalized_root / storage_path).resolve()

    try:
        candidate_path.relative_to(normalized_root)
    except ValueError as exc:
        raise SharedStorageResolutionError(
            "shared storage path resolution escaped the configured root.",
            "shared_storage_resolution_failed",
        ) from exc

    return candidate_path


def contradictory_document_reasons(
    document_type: str,
    signals: dict[str, object],
    statement_period: Optional[str],
    fields: dict,
) -> list[str]:
    reasons: list[str] = []

    if document_type != "ic":
        return reasons

    has_payroll_fields = any(
        fields.get(key) is not None
        for key in ["basic_salary", "gross_income", "net_pay", "total_deductions", "employer"]
    )

    if statement_period is not None:
        reasons.append("conflicting_document_evidence")
    elif signals["has_payroll_bundle"] or signals["contradictory_payroll_markers"] or has_payroll_fields:
        reasons.append("conflicting_document_evidence")
    elif signals["contradictory_epf_markers"] or signals["contradictory_credit_markers"]:
        reasons.append("conflicting_document_evidence")

    return reasons


def qualifies_for_high_ic_confidence(signals: dict[str, object], score_margin: int) -> bool:
    if score_margin < 2:
        return False

    if has_strong_contradictory_evidence(signals):
        return False

    has_ic_number = bool(signals["ic_number"])
    front_markers = set(signals["ic_front_markers"])
    back_markers = set(signals["ic_back_markers"])

    front_bundle = (
        "mykad" in front_markers
        and "kad pengenalan" in front_markers
        and has_ic_number
        and not back_markers
    )

    back_bundle = (
        has_ic_number
        and (
            "ketua pengarah pendaftaran negara" in back_markers
            or "pendaftaran negara" in back_markers
        )
        and "mykad" not in front_markers
    )

    return front_bundle or back_bundle


def has_strong_contradictory_evidence(signals: dict[str, object]) -> bool:
    return bool(
        signals["has_payroll_bundle"]
        or signals["contradictory_payroll_markers"]
        or signals["contradictory_epf_markers"]
        or signals["contradictory_credit_markers"]
    )


def should_require_review(document_type: str, review_reasons: list[str]) -> bool:
    if document_type == "other" and review_reasons == ["unsupported_document"]:
        return False

    return len(review_reasons) > 0


def build_diagnostics(
    *,
    signals: dict[str, object],
    scores: dict[str, int],
    document_type: str,
    confidence: str,
    ic_side: Optional[str],
    raw_text: str,
    fields: dict,
) -> dict:
    best_score = scores.get(document_type, 0)
    second_score = max(
        [score for kind, score in scores.items() if kind != document_type],
        default=0,
    )
    contradictory_evidence = deduplicate_preserve_order(
        list(signals["contradictory_payroll_markers"])
        + list(signals["contradictory_epf_markers"])
        + list(signals["contradictory_credit_markers"])
    )

    return {
        "classification_confidence": confidence,
        "side_confidence": classify_side_confidence(signals, ic_side),
        "ocr_quality": classify_ocr_quality(raw_text),
        "field_completeness": classify_field_completeness(document_type, fields),
        "decision_evidence": {
            "strong_ic_markers": deduplicate_preserve_order(
                list(signals["ic_front_markers"]) + list(signals["ic_back_markers"])
            ),
            "front_markers": list(signals["ic_front_markers"]),
            "back_markers": list(signals["ic_back_markers"]),
            "supporting_identity_evidence": {
                "has_ic_number": bool(signals["ic_number"]),
                "identity_labels": list(signals["identity_label_markers"]),
            },
            "contradictory_evidence": contradictory_evidence,
            "normalized_phrase_matches": list(signals["normalized_phrase_matches"]),
            "incidental_ic_markers": list(signals["incidental_ic_markers"]),
            "scores": scores,
            "score_margin": best_score - second_score,
        },
    }


def classify_side_confidence(signals: dict[str, object], ic_side: Optional[str]) -> str:
    if ic_side not in {"front", "back"}:
        return "low"

    front_score = len(signals["ic_front_markers"])
    back_score = len(signals["ic_back_markers"])
    margin = abs(front_score - back_score)

    if margin >= 2:
        return "high"
    if margin >= 1:
        return "medium"

    return "low"


def classify_ocr_quality(text: str) -> str:
    score = score_text_quality(text)

    if score >= 60:
        return "high"
    if score >= 25:
        return "medium"

    return "low"


def classify_field_completeness(document_type: str, fields: dict) -> str:
    if document_type == "ic":
        completed = sum(1 for key in ["full_name", "ic_number", "date_of_birth", "address"] if fields.get(key))
        if completed >= 3:
            return "high"
        if completed >= 1:
            return "medium"
        return "low"

    if document_type in {"payslip", "pension_slip"}:
        completed = sum(
            1 for key in ["statement_period", "gross_income", "basic_salary", "net_pay", "employer"]
            if fields.get(key)
        )
        if completed >= 3:
            return "high"
        if completed >= 1:
            return "medium"
        return "low"

    return "medium"


def technical_failure_result(
    *,
    summary: str,
    method: str,
    filename: Optional[str],
    source: Optional[str],
    started: float,
    review_reasons: Optional[list[str]] = None,
) -> dict:
    elapsed_ms = int((time.perf_counter() - started) * 1000)

    return {
        "summary": summary,
        "confidence": "low",
        "needs_review": True,
        "review_reasons": review_reasons or ["technical_failure"],
        "classification": {
            "document_type": "other",
            "ic_side": None,
            "statement_year": None,
            "statement_month": None,
            "statement_period": None,
        },
        "fields": {},
        "raw_text": None,
        "provider_meta": {
            "provider": "python_local",
            "method": method,
            "timing_ms": elapsed_ms,
            "source": source,
            "filename": filename,
            "technical_failure": True,
        },
    }
