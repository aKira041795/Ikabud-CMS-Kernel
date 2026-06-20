"""
Capability: reporting.ledger.daily@1

Generates a PDF daily ledger report. Demonstrates the polyglot
ServiceProxy wire protocol end-to-end.
"""

import base64
import os
from datetime import datetime

from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import getSampleStyleSheet
from reportlab.lib.units import mm
from reportlab.platypus import Paragraph, SimpleDocTemplate, Spacer, Table, TableStyle


def handle_ledger_daily(payload: dict, caller: dict) -> dict:
    """Generate a daily ledger PDF report."""
    date_str = str(payload.get("date", datetime.now().strftime("%Y-%m-%d")))
    store_id = int(payload.get("store_id", 0))
    entries = payload.get("entries", [])
    summary = payload.get("summary", {})

    # Generate PDF
    storage_dir = os.environ.get("REPORT_STORAGE_DIR", "./storage/reports")
    os.makedirs(storage_dir, exist_ok=True)

    filename = f"ledger_daily_{date_str}_store{store_id}.pdf"
    filepath = os.path.join(storage_dir, filename)

    pdf_bytes = _generate_pdf(date_str, store_id, entries, summary)

    with open(filepath, "wb") as f:
        f.write(pdf_bytes)

    return {
        "ok": True,
        "data": {
            "pdf_base64": base64.b64encode(pdf_bytes).decode("ascii"),
            "filename": filename,
            "filepath": filepath,
            "generated_at": datetime.now().isoformat(),
            "report_date": date_str,
            "store_id": store_id,
        },
    }


def _generate_pdf(
    date_str: str, store_id: int, entries: list, summary: dict
) -> bytes:
    """Build the PDF document using ReportLab."""
    from io import BytesIO

    buffer = BytesIO()
    doc = SimpleDocTemplate(buffer, pagesize=A4, topMargin=20 * mm, bottomMargin=20 * mm)
    styles = getSampleStyleSheet()
    story = []

    # Title
    story.append(Paragraph(f"Daily Ledger Report", styles["Title"]))
    story.append(Paragraph(f"Date: {date_str} | Store: {store_id}", styles["Normal"]))
    story.append(Spacer(1, 10 * mm))

    # Summary section
    if summary:
        story.append(Paragraph("Summary", styles["Heading2"]))
        summary_data = [[k.replace("_", " ").title(), str(v)] for k, v in summary.items()]
        summary_table = Table(summary_data, colWidths=[100 * mm, 60 * mm])
        summary_table.setStyle(
            TableStyle([
                ("GRID", (0, 0), (-1, -1), 0.5, (0, 0, 0)),
                ("BACKGROUND", (0, 0), (0, -1), (0.9, 0.9, 0.9)),
                ("FONTSIZE", (0, 0), (-1, -1), 9),
            ])
        )
        story.append(summary_table)
        story.append(Spacer(1, 10 * mm))

    # Entries table
    if entries:
        story.append(Paragraph("Entries", styles["Heading2"]))
        if isinstance(entries[0], dict):
            cols = list(entries[0].keys())
            table_data = [cols] + [[str(e.get(c, "")) for c in cols] for e in entries]
        else:
            table_data = [["Entry"]] + [[str(e)] for e in entries]

        entries_table = Table(table_data)
        entries_table.setStyle(
            TableStyle([
                ("GRID", (0, 0), (-1, -1), 0.5, (0, 0, 0)),
                ("BACKGROUND", (0, 0), (-1, 0), (0.2, 0.2, 0.4)),
                ("TEXTCOLOR", (0, 0), (-1, 0), (1, 1, 1)),
                ("FONTSIZE", (0, 0), (-1, -1), 8),
            ])
        )
        story.append(entries_table)

    doc.build(story)
    return buffer.getvalue()
