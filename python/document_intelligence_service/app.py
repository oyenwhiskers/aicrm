import base64
import os
from typing import Optional

from fastapi import FastAPI, Request
from pydantic import BaseModel

try:
    from python.document_intelligence_service.service_logic import (
        process_document,
        process_document_from_storage_reference,
    )
except ImportError:  # pragma: no cover - fallback for direct local uvicorn execution
    from service_logic import process_document, process_document_from_storage_reference


class ExtractRequest(BaseModel):
    document_id: Optional[int] = None
    lead_id: Optional[int] = None
    filename: Optional[str] = None
    mime_type: Optional[str] = None
    content_base64: Optional[str] = None
    source: Optional[str] = None
    mode: str = "primary"
    storage_disk: Optional[str] = None
    storage_path: Optional[str] = None
    shared_storage_roots: dict[str, str] = {}
    allowed_storage_disks: list[str] = []


app = FastAPI(title="LPS Python Document Intelligence Skeleton")


def default_storage_roots() -> dict[str, str]:
    return {
        "public": os.getenv(
            "PYTHON_DOCUMENT_INTELLIGENCE_SHARED_STORAGE_PUBLIC_ROOT",
            os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "..", "storage", "app", "public")),
        )
    }


def allowed_storage_disks() -> list[str]:
    value = os.getenv("PYTHON_DOCUMENT_INTELLIGENCE_SHARED_STORAGE_ENABLED_DISKS", "public")
    return [item.strip() for item in value.split(",") if item.strip()]


@app.get("/health")
def health() -> dict:
    return {"status": "ok"}


@app.post("/extract")
async def extract_document(request: Request) -> dict:
    content_type = request.headers.get("content-type", "")

    if "multipart/form-data" in content_type:
        form = await request.form()
        upload = form.get("file")
        file_bytes = b""

        if upload is not None:
            file_bytes = await upload.read()

        return process_document(
            content_base64=base64.b64encode(file_bytes).decode("ascii"),
            mime_type=form.get("mime_type"),
            filename=form.get("filename"),
            source=form.get("source"),
        )

    payload = ExtractRequest.model_validate(await request.json())

    if payload.storage_disk and payload.storage_path:
        return process_document_from_storage_reference(
            storage_disk=payload.storage_disk,
            storage_path=payload.storage_path,
            mime_type=payload.mime_type,
            filename=payload.filename,
            source=payload.source or "shared_storage",
            shared_storage_roots=payload.shared_storage_roots or default_storage_roots(),
            allowed_storage_disks=payload.allowed_storage_disks or allowed_storage_disks(),
        )

    return process_document(
        content_base64=payload.content_base64 or "",
        mime_type=payload.mime_type,
        filename=payload.filename,
        source=payload.source,
    )
