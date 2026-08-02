import base64
import tempfile
import unittest
from pathlib import Path
from unittest.mock import Mock, patch

from python.document_intelligence_service.service_logic import (
    process_document,
    process_document_from_storage_reference,
)


def encode_text(value: str) -> str:
    return base64.b64encode(value.encode("utf-8")).decode("ascii")


class DocumentIntelligenceLogicTest(unittest.TestCase):
    def test_detects_payslip_with_statement_period_and_income(self) -> None:
        result = process_document(
            content_base64=encode_text(
                "PAYSLIP Employee Name: JOHN DOE Gross Pay: 3200.50 Net Pay: 2800.00 Salary Month APR 2026 KWSP"
            ),
            mime_type="text/plain",
            filename="payslip.txt",
            source="original",
        )

        self.assertEqual("payslip", result["classification"]["document_type"])
        self.assertEqual("2026-04", result["classification"]["statement_period"])
        self.assertEqual(3200.50, result["fields"]["gross_income"])
        self.assertFalse(result["needs_review"])

    def test_detects_pension_slip_separately_from_payslip(self) -> None:
        result = process_document(
            content_base64=encode_text(
                "SLIP PENCEN Nama: NASMAWATI BINTI CHE OM Bayaran Pencen APR 2026 Net Pay: 1500.00"
            ),
            mime_type="text/plain",
            filename="pension.txt",
            source="original",
        )

        self.assertEqual("pension_slip", result["classification"]["document_type"])
        self.assertEqual("Pensioner", result["fields"]["employment_type"])

    def test_detects_epf_with_statement_year(self) -> None:
        result = process_document(
            content_base64=encode_text(
                "Kumpulan Wang Simpanan Pekerja Member Statement 2026 Employer Contribution Balance Dividend"
            ),
            mime_type="text/plain",
            filename="epf.txt",
            source="original",
        )

        self.assertEqual("epf", result["classification"]["document_type"])
        self.assertEqual(2026, result["classification"]["statement_year"])

    def test_detects_ic_front(self) -> None:
        result = process_document(
            content_base64=encode_text(
                "\n".join(
                    [
                        "KAD PENGENALAN",
                        "Name: JANE DOE",
                        "900101-10-1234",
                        "NO 5 JALAN MAWAR",
                        "43000 KAJANG",
                        "SELANGOR",
                        "WARGANEGARA",
                    ]
                )
            ),
            mime_type="text/plain",
            filename="ic-front.txt",
            source="original",
        )

        self.assertEqual("ic", result["classification"]["document_type"])
        self.assertEqual("front", result["classification"]["ic_side"])
        self.assertEqual("900101-10-1234", result["fields"]["ic_number"])
        self.assertFalse(result["needs_review"])
        self.assertEqual("high", result["provider_meta"]["classification_confidence"])

    def test_identity_fields_alone_do_not_make_document_an_ic(self) -> None:
        result = process_document(
            content_base64=encode_text(
                "NAMA : JOHN DOE NO KP : 900101-10-1234 GAJI BERSIH 2800.00 ELAUN 300.00 PENYATA GAJI APRIL 2026"
            ),
            mime_type="text/plain",
            filename="MOHAMMAD IQBAL BIN MAHADI APR 26.pdf",
            source="original",
        )

        self.assertEqual("payslip", result["classification"]["document_type"])
        self.assertEqual("2026-04", result["classification"]["statement_period"])
        self.assertFalse(result["needs_review"])

    def test_weak_ic_tie_triggers_review_instead_of_silent_ic_win(self) -> None:
        result = process_document(
            content_base64=encode_text(
                "NAMA : JOHN DOE NO KP : 900101-10-1234"
            ),
            mime_type="text/plain",
            filename="uncertain-document.txt",
            source="original",
        )

        self.assertEqual("other", result["classification"]["document_type"])
        self.assertTrue(result["needs_review"])
        self.assertIn("uncertain_document_type", result["review_reasons"])

    def test_controlled_phrase_normalization_is_auditable(self) -> None:
        result = process_document(
            content_base64=encode_text(
                "KAD PENGENALAWN 920324-01-6167 WARGANEGARA"
            ),
            mime_type="text/plain",
            filename="ic-front-ocr.txt",
            source="original",
        )

        self.assertEqual("ic", result["classification"]["document_type"])
        matches = result["provider_meta"]["decision_evidence"]["normalized_phrase_matches"]
        self.assertTrue(any(match["normalized_marker"] == "kad pengenalan" for match in matches))
        self.assertTrue(any(match["match_method"] == "controlled_fuzzy_match" for match in matches))
        self.assertEqual("medium", result["confidence"])

    def test_front_ic_promotion_bundle_reaches_high_confidence(self) -> None:
        result = process_document(
            content_base64=encode_text(
                "MyKad KAD PENGENALAWN 920324-01-6167 WARGANEGARA"
            ),
            mime_type="text/plain",
            filename="ic-front-promoted.txt",
            source="original",
        )

        self.assertEqual("ic", result["classification"]["document_type"])
        self.assertEqual("front", result["classification"]["ic_side"])
        self.assertEqual("high", result["confidence"])

    def test_chip_wording_is_not_material_ic_back_evidence(self) -> None:
        result = process_document(
            content_base64=encode_text(
                "Touch n Go 80K chip 920324-01-6167"
            ),
            mime_type="text/plain",
            filename="uncertain-identity.txt",
            source="original",
        )

        self.assertEqual("other", result["classification"]["document_type"])
        self.assertTrue(result["needs_review"])
        self.assertIn("uncertain_document_type", result["review_reasons"])

    def test_low_margin_conflict_does_not_silently_choose_ic(self) -> None:
        result = process_document(
            content_base64=encode_text(
                "KAD PENGENALAN Gross Pay 3200.50"
            ),
            mime_type="text/plain",
            filename="conflicted.txt",
            source="original",
        )

        self.assertEqual("other", result["classification"]["document_type"])
        self.assertTrue(result["needs_review"])
        self.assertIn("low_margin_conflict", result["review_reasons"])

    def test_readable_unsupported_document_does_not_force_review(self) -> None:
        result = process_document(
            content_base64=encode_text(
                "Meeting notes for branch operations and customer follow up next week."
            ),
            mime_type="text/plain",
            filename="notes.txt",
            source="original",
        )

        self.assertEqual("other", result["classification"]["document_type"])
        self.assertFalse(result["needs_review"])
        self.assertEqual(["unsupported_document"], result["review_reasons"])

    def test_conflicting_ic_and_payroll_evidence_does_not_auto_accept_ic(self) -> None:
        result = process_document(
            content_base64=encode_text(
                "MyKad 920324-01-6167 Gross Pay 3200.50 Net Pay 2800.00 APR 2026 KWSP"
            ),
            mime_type="text/plain",
            filename="ic-with-payroll.txt",
            source="original",
        )

        self.assertNotEqual("ic", result["classification"]["document_type"])

    def test_genuine_ic_back_uses_authority_wording_for_side_detection(self) -> None:
        result = process_document(
            content_base64=encode_text(
                "KETUA PENGARAH PENDAFTARAN NEGARA 920324-01-6167-05-01 PENDAFTARAN NEGARA"
            ),
            mime_type="text/plain",
            filename="ic-back.txt",
            source="original",
        )

        self.assertEqual("ic", result["classification"]["document_type"])
        self.assertEqual("back", result["classification"]["ic_side"])
        self.assertIn("pendaftaran negara", result["provider_meta"]["decision_evidence"]["back_markers"])
        self.assertEqual("high", result["confidence"])

    def test_ic_front_extraction_prefers_real_name_and_multiline_address(self) -> None:
        result = process_document(
            content_base64=encode_text(
                "\n".join(
                    [
                        "KAD PENGENALAN",
                        "MALAYSIA",
                        "IDENTITY CARD",
                        "920324-01-6167",
                        "MOHAMMAD IQBAL BIN MAHADI",
                        "NO 22 JALAN PERMAI",
                        "81930 BANDAR PENAWAR",
                        "JOHOR",
                        "WARGANEGARA ISLAM LELAKI",
                    ]
                )
            ),
            mime_type="text/plain",
            filename="ic-front-detail.txt",
            source="original",
        )

        self.assertEqual("ic", result["classification"]["document_type"])
        self.assertEqual("front", result["classification"]["ic_side"])
        self.assertEqual("MOHAMMAD IQBAL BIN MAHADI", result["fields"]["full_name"])
        self.assertEqual("NO 22 JALAN PERMAI 81930 BANDAR PENAWAR JOHOR", result["fields"]["address"])
        self.assertEqual("1992-03-24", result["fields"]["date_of_birth"])
        self.assertEqual("male", result["fields"]["gender"])
        self.assertNotEqual("KAD PENGENALAN", result["fields"]["full_name"])

    def test_ic_front_without_strong_address_keeps_address_blank_and_reviewable(self) -> None:
        result = process_document(
            content_base64=encode_text(
                "\n".join(
                    [
                        "KAD PENGENALAN",
                        "MYKAD",
                        "920324-01-6167",
                        "MOHAMMAD IQBAL BIN MAHADI",
                        "WARGANEGARA ISLAM LELAKI",
                    ]
                )
            ),
            mime_type="text/plain",
            filename="ic-front-no-address.txt",
            source="original",
        )

        self.assertEqual("ic", result["classification"]["document_type"])
        self.assertIsNone(result["fields"]["address"])
        self.assertTrue(result["needs_review"])
        self.assertIn("missing_ic_address", result["review_reasons"])

    def test_invalid_ic_number_does_not_derive_birth_date_or_gender(self) -> None:
        result = process_document(
            content_base64=encode_text(
                "\n".join(
                    [
                        "KAD PENGENALAN",
                        "MYKAD",
                        "991332-01-6168",
                        "NUR AIN BINTI OSMAN",
                        "NO 10 JALAN MAWAR",
                        "43000 KAJANG",
                        "SELANGOR",
                    ]
                )
            ),
            mime_type="text/plain",
            filename="ic-front-invalid-dob.txt",
            source="original",
        )

        self.assertEqual("ic", result["classification"]["document_type"])
        self.assertIsNone(result["fields"]["date_of_birth"])
        self.assertIsNone(result["fields"]["gender"])
        self.assertIn("invalid_ic_birth_date", result["review_reasons"])

    def test_ic_back_does_not_invent_front_only_identity_fields(self) -> None:
        result = process_document(
            content_base64=encode_text(
                "\n".join(
                    [
                        "KETUA PENGARAH PENDAFTARAN NEGARA",
                        "920324-01-6167-05-01",
                        "PENDAFTARAN NEGARA",
                        "Touch n Go",
                    ]
                )
            ),
            mime_type="text/plain",
            filename="ic-back-detail.txt",
            source="original",
        )

        self.assertEqual("ic", result["classification"]["document_type"])
        self.assertEqual("back", result["classification"]["ic_side"])
        self.assertIsNone(result["fields"]["full_name"])
        self.assertIsNone(result["fields"]["address"])
        self.assertEqual("1992-03-24", result["fields"]["date_of_birth"])
        self.assertEqual("male", result["fields"]["gender"])

    def test_detects_ctos_with_high_confidence(self) -> None:
        result = process_document(
            content_base64=encode_text(
                "CTOS Data Systems Credit Report Outstanding Balance Repayment Legal Information"
            ),
            mime_type="text/plain",
            filename="ctos.txt",
            source="original",
        )

        self.assertEqual("ctos", result["classification"]["document_type"])
        self.assertEqual("high", result["confidence"])

    @patch("python.document_intelligence_service.service_logic.PdfReader")
    def test_uses_direct_pdf_text_extraction_when_pdf_contains_readable_text(self, mock_pdf_reader) -> None:
        page = Mock()
        page.extract_text.return_value = (
            "PAYSLIP Employee Name: JOHN DOE Gross Pay: 3200.50 Net Pay: 2800.00 APR 2026 KWSP"
        )
        mock_pdf_reader.return_value.pages = [page]

        payload = base64.b64encode(b"fake-pdf-binary").decode("ascii")
        result = process_document(
            content_base64=payload,
            mime_type="application/pdf",
            filename="payslip-apr.pdf",
            source="original",
        )

        self.assertEqual("payslip", result["classification"]["document_type"])
        self.assertEqual("2026-04", result["classification"]["statement_period"])
        self.assertEqual("pdf_text", result["provider_meta"]["method"])
        self.assertFalse(result["needs_review"])

    @patch("python.document_intelligence_service.service_logic.pytesseract")
    @patch("python.document_intelligence_service.service_logic.Image")
    @patch("python.document_intelligence_service.service_logic.fitz")
    @patch("python.document_intelligence_service.service_logic.PdfReader")
    def test_falls_back_to_pdf_ocr_when_direct_pdf_text_is_too_weak(
        self,
        mock_pdf_reader,
        mock_fitz,
        mock_image,
        mock_tesseract,
    ) -> None:
        weak_page = Mock()
        weak_page.extract_text.return_value = "PDF 1.4 stream endobj"
        mock_pdf_reader.return_value.pages = [weak_page]

        fake_page = Mock()
        fake_pixmap = Mock()
        fake_pixmap.tobytes.return_value = b"png-binary"
        fake_page.get_pixmap.return_value = fake_pixmap
        mock_fitz.open.return_value = [fake_page]
        mock_fitz.Matrix.return_value = object()

        fake_image = Mock()
        grayscale_image = Mock()
        mock_image.open.return_value = fake_image
        fake_image.convert.return_value = grayscale_image
        mock_tesseract.image_to_string.return_value = (
            "PAYSLIP Gross Pay: 3200.50 Net Pay: 2800.00 APR 2026 KWSP"
        )

        payload = base64.b64encode(b"fake-pdf-binary").decode("ascii")
        result = process_document(
            content_base64=payload,
            mime_type="application/pdf",
            filename="payslip-apr.pdf",
            source="original",
        )

        self.assertEqual("payslip", result["classification"]["document_type"])
        self.assertEqual("pdf_ocr", result["provider_meta"]["method"])
        self.assertEqual("2026-04", result["classification"]["statement_period"])

    @patch("python.document_intelligence_service.service_logic.PdfReader")
    def test_uses_filename_as_supporting_period_hint_when_pdf_text_lacks_month(
        self,
        mock_pdf_reader,
    ) -> None:
        page = Mock()
        page.extract_text.return_value = "PAYSLIP Gross Pay: 3200.50 Net Pay: 2800.00 KWSP"
        mock_pdf_reader.return_value.pages = [page]

        payload = base64.b64encode(b"fake-pdf-binary").decode("ascii")
        result = process_document(
            content_base64=payload,
            mime_type="application/pdf",
            filename="MOHAMMAD IQBAL BIN MAHADI APR 26.pdf",
            source="original",
        )

        self.assertEqual("payslip", result["classification"]["document_type"])
        self.assertEqual("2026-04", result["classification"]["statement_period"])
        self.assertFalse(result["needs_review"])

    @patch("python.document_intelligence_service.service_logic.PdfReader")
    def test_realistic_malay_payslip_pdf_does_not_fall_back_to_ic(
        self,
        mock_pdf_reader,
    ) -> None:
        page = Mock()
        page.extract_text.return_value = (
            "PENY ATA GAJI APRIL / 2026 NAMA : MOHAMMAD IQBAL BIN MAHADI "
            "NO. KP . : 920324016167 JAWATAN : PEMBANTU AWAM NO PEKERJA : 1228 "
            "PENDAP ATAN RM GAJI 2431.86 ELAUN 300.00 POTONGAN RM JUMLAH 4433.20 "
            "GAJI BERSIH : 2734.56"
        )
        mock_pdf_reader.return_value.pages = [page]

        payload = base64.b64encode(b"fake-pdf-binary").decode("ascii")
        result = process_document(
            content_base64=payload,
            mime_type="application/pdf",
            filename="MOHAMMAD IQBAL BIN MAHADI APR 26.pdf",
            source="original",
        )

        self.assertEqual("payslip", result["classification"]["document_type"])
        self.assertEqual("2026-04", result["classification"]["statement_period"])
        self.assertFalse(result["needs_review"])

    def test_returns_controlled_result_for_invalid_base64_payload(self) -> None:
        result = process_document(
            content_base64="this-is-not-valid-base64",
            mime_type="text/plain",
            filename="broken.txt",
            source="original",
        )

        self.assertEqual("other", result["classification"]["document_type"])
        self.assertTrue(result["needs_review"])
        self.assertTrue(result["provider_meta"]["technical_failure"])
        self.assertEqual("invalid_base64", result["provider_meta"]["method"])
        self.assertIn("technical_failure", result["review_reasons"])

    @patch("python.document_intelligence_service.service_logic.pytesseract")
    @patch("python.document_intelligence_service.service_logic.Image")
    def test_uses_image_ocr_for_image_payloads(self, mock_image, mock_tesseract) -> None:
        fake_image = Mock()
        grayscale_image = Mock()
        mock_image.open.return_value = fake_image
        fake_image.convert.return_value = grayscale_image
        mock_tesseract.image_to_string.return_value = (
            "PAYSLIP Gross Pay: 3200.50 Net Pay: 2800.00 APR 2026"
        )

        payload = base64.b64encode(b"fake-image-binary").decode("ascii")
        result = process_document(
            content_base64=payload,
            mime_type="image/jpeg",
            filename="payslip.jpg",
            source="original",
        )

        self.assertEqual("payslip", result["classification"]["document_type"])
        self.assertEqual("image_ocr", result["provider_meta"]["method"])
        self.assertEqual(3200.50, result["fields"]["gross_income"])

    def test_image_payload_without_ocr_dependency_stays_review_required(self) -> None:
        with patch("python.document_intelligence_service.service_logic.Image", None), patch(
            "python.document_intelligence_service.service_logic.pytesseract", None
        ):
            payload = base64.b64encode(b"fake-image-binary").decode("ascii")
            result = process_document(
                content_base64=payload,
                mime_type="image/jpeg",
                filename="unknown.jpg",
                source="original",
            )

        self.assertEqual("other", result["classification"]["document_type"])
        self.assertTrue(result["needs_review"])
        self.assertEqual("ocr_unavailable", result["provider_meta"]["method"])

    def test_processes_document_from_shared_storage_reference(self) -> None:
        with tempfile.TemporaryDirectory() as tmpdir:
            root = Path(tmpdir)
            stored = root / "leads" / "10" / "documents" / "payslip.txt"
            stored.parent.mkdir(parents=True, exist_ok=True)
            stored.write_text("PAYSLIP Gross Pay: 3200.50 Net Pay: 2800.00 APR 2026", encoding="utf-8")

            result = process_document_from_storage_reference(
                storage_disk="public",
                storage_path="leads/10/documents/payslip.txt",
                shared_storage_roots={"public": str(root)},
                allowed_storage_disks=["public"],
                mime_type="text/plain",
                filename="payslip.txt",
                source="shared_storage",
            )

        self.assertEqual("payslip", result["classification"]["document_type"])
        self.assertEqual("shared_storage", result["provider_meta"]["input_source"])
        self.assertEqual("local_file_read", result["provider_meta"]["shared_storage_open_method"])

    def test_rejects_disallowed_shared_storage_disk(self) -> None:
        result = process_document_from_storage_reference(
            storage_disk="private",
            storage_path="leads/10/documents/file.txt",
            shared_storage_roots={"public": "/tmp/public"},
            allowed_storage_disks=["public"],
            mime_type="text/plain",
            filename="file.txt",
            source="shared_storage",
        )

        self.assertTrue(result["needs_review"])
        self.assertEqual("shared_storage_resolution_failed", result["provider_meta"]["method"])
        self.assertIn("shared_storage_disk_not_allowed", result["review_reasons"])

    def test_rejects_shared_storage_path_traversal(self) -> None:
        with tempfile.TemporaryDirectory() as tmpdir:
            result = process_document_from_storage_reference(
                storage_disk="public",
                storage_path="../escape.txt",
                shared_storage_roots={"public": tmpdir},
                allowed_storage_disks=["public"],
                mime_type="text/plain",
                filename="escape.txt",
                source="shared_storage",
            )

        self.assertTrue(result["needs_review"])
        self.assertEqual("shared_storage_resolution_failed", result["provider_meta"]["method"])
        self.assertIn("shared_storage_resolution_failed", result["review_reasons"])

    def test_missing_shared_storage_file_returns_controlled_failure(self) -> None:
        with tempfile.TemporaryDirectory() as tmpdir:
            result = process_document_from_storage_reference(
                storage_disk="public",
                storage_path="leads/10/documents/missing.txt",
                shared_storage_roots={"public": tmpdir},
                allowed_storage_disks=["public"],
                mime_type="text/plain",
                filename="missing.txt",
                source="shared_storage",
            )

        self.assertTrue(result["needs_review"])
        self.assertEqual("shared_storage_unavailable", result["provider_meta"]["method"])
        self.assertIn("shared_storage_unavailable", result["review_reasons"])


if __name__ == "__main__":
    unittest.main()
