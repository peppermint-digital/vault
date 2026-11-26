#!/usr/bin/env python3
"""PDF annotation helper for AI Brain's MCP tools.

Invoked by App\\Services\\PdfAnnotationService via the configured venv python.
Communication is JSON over stdin/stdout — NO shell arguments carry user data,
so there is no shell-injection surface. The subcommand is the single argv:

    python pdf_annotations.py highlight  < request.json
    python pdf_annotations.py comment    < request.json
    python pdf_annotations.py extract    < request.json

Request (stdin) and response (stdout) are both a single JSON object. On success
the response has {"ok": true, ...}; on any handled error {"ok": false, "error": ...}.
The PHP side resolves and validates the absolute file path before calling us, so
`path` is trusted to live inside the vault.
"""

import json
import os
import sys
import tempfile

try:
    import pymupdf  # PyMuPDF >= 1.24 exposes the top-level `pymupdf` module
except ImportError:  # pragma: no cover - fallback for older PyMuPDF builds
    import fitz as pymupdf


# Named colours → RGB tuples in PyMuPDF's 0..1 float space.
COLORS = {
    "yellow": (1.0, 0.92, 0.23),
    "green": (0.5, 0.85, 0.4),
    "blue": (0.4, 0.7, 1.0),
    "red": (1.0, 0.45, 0.45),
    "orange": (1.0, 0.7, 0.3),
    "pink": (1.0, 0.6, 0.85),
    "purple": (0.75, 0.6, 0.95),
    "cyan": (0.5, 0.9, 0.9),
}


def fail(message):
    print(json.dumps({"ok": False, "error": message}))
    sys.exit(0)


def open_doc(req):
    path = req.get("path")
    if not path or not os.path.isfile(path):
        fail(f"PDF nicht gefunden: {path}")
    try:
        return pymupdf.open(path)
    except Exception as exc:  # noqa: BLE001
        fail(f"PDF konnte nicht geöffnet werden: {exc}")


def resolve_page(doc, req):
    page_no = int(req.get("page", 1))
    if page_no < 1 or page_no > doc.page_count:
        fail(f"Seite {page_no} außerhalb des Bereichs (1–{doc.page_count}).")
    return doc[page_no - 1], page_no


def save_doc(doc, path):
    """Incremental save to preserve existing content; fall back to atomic rewrite."""
    try:
        doc.save(path, incremental=True, encryption=pymupdf.PDF_ENCRYPT_KEEP)
        return
    except Exception:  # noqa: BLE001 - incremental not always possible (linearized, etc.)
        pass

    fd, tmp = tempfile.mkstemp(suffix=".pdf", dir=os.path.dirname(path))
    os.close(fd)
    try:
        doc.save(tmp, garbage=3, deflate=True)
        os.replace(tmp, path)
    except Exception as exc:  # noqa: BLE001
        if os.path.exists(tmp):
            os.unlink(tmp)
        fail(f"PDF konnte nicht gespeichert werden: {exc}")


def color_to_name(rgb):
    if not rgb:
        return None
    best, best_dist = None, None
    for name, ref in COLORS.items():
        dist = sum((a - b) ** 2 for a, b in zip(rgb, ref))
        if best_dist is None or dist < best_dist:
            best, best_dist = name, dist
    return best


def cmd_highlight(req):
    doc = open_doc(req)
    page, page_no = resolve_page(doc, req)
    text = (req.get("text") or "").strip()
    if not text:
        fail("`text` ist erforderlich, um eine Stelle zu markieren.")

    rects = page.search_for(text)
    if not rects:
        fail(f"Text auf Seite {page_no} nicht gefunden: \"{text[:80]}\"")

    color_name = (req.get("color") or "yellow").lower()
    if color_name not in COLORS:
        fail(f"Unbekannte Farbe \"{color_name}\". Erlaubt: {', '.join(COLORS)}.")

    annot = page.add_highlight_annot(rects)
    annot.set_colors(stroke=COLORS[color_name])
    info = {"title": req.get("author") or "AI Brain"}
    comment = (req.get("comment") or "").strip()
    if comment:
        info["content"] = comment
    annot.set_info(info)
    annot.update()
    save_doc(doc, req["path"])

    print(json.dumps({
        "ok": True,
        "action": "highlight",
        "page": page_no,
        "matches": len(rects),
        "color": color_name,
        "comment": comment or None,
    }))


def cmd_comment(req):
    doc = open_doc(req)
    page, page_no = resolve_page(doc, req)
    comment = (req.get("comment") or "").strip()
    if not comment:
        fail("`comment` ist erforderlich.")

    text = (req.get("text") or "").strip()
    if text:
        rects = page.search_for(text)
        if not rects:
            fail(f"Text auf Seite {page_no} nicht gefunden: \"{text[:80]}\"")
        point = pymupdf.Point(rects[0].x0, rects[0].y0)
        anchored_to = text
    else:
        # No anchor text → drop the note at the top-left of the page.
        point = pymupdf.Point(36, 36)
        anchored_to = None

    annot = page.add_text_annot(point, comment)
    annot.set_info({"title": req.get("author") or "AI Brain", "content": comment})
    annot.update()
    save_doc(doc, req["path"])

    print(json.dumps({
        "ok": True,
        "action": "comment",
        "page": page_no,
        "anchored_to": anchored_to,
        "comment": comment,
    }))


def cmd_extract(req):
    doc = open_doc(req)
    out = []
    for page_index in range(doc.page_count):
        page = doc[page_index]
        for idx, annot in enumerate(page.annots() or []):
            info = annot.info or {}
            rect = annot.rect
            entry = {
                "page": page_index + 1,
                "index": idx,  # per-page, 0-based — used to address removals
                "type": annot.type[1] if annot.type else "Unknown",
                "author": info.get("title") or None,
                "comment": (info.get("content") or "").strip() or None,
                "color": color_to_name(
                    (annot.colors or {}).get("stroke")
                    or (annot.colors or {}).get("fill")
                ),
                "rect": [round(rect.x0, 1), round(rect.y0, 1),
                         round(rect.x1, 1), round(rect.y1, 1)],
            }
            # For highlights, pull the underlying text so the caller sees WHAT was marked.
            if entry["type"] in ("Highlight", "Underline", "StrikeOut", "Squiggly"):
                try:
                    entry["text"] = page.get_textbox(rect).strip() or None
                except Exception:  # noqa: BLE001
                    entry["text"] = None
            out.append(entry)

    print(json.dumps({"ok": True, "action": "extract",
                      "count": len(out), "annotations": out}))


def cmd_remove(req):
    doc = open_doc(req)
    page, page_no = resolve_page(doc, req)
    index = int(req.get("index", -1))
    if index < 0:
        fail("`index` ist erforderlich (0-basiert, wie in extract gelistet).")

    annots = list(page.annots() or [])
    if index >= len(annots):
        fail(f"Kein Annotation-Index {index} auf Seite {page_no} (nur {len(annots)} vorhanden).")

    target = annots[index]
    info = target.info or {}
    removed = {
        "type": target.type[1] if target.type else "Unknown",
        "comment": (info.get("content") or "").strip() or None,
    }
    page.delete_annot(target)
    save_doc(doc, req["path"])

    print(json.dumps({
        "ok": True,
        "action": "remove",
        "page": page_no,
        "index": index,
        "removed": removed,
    }))


def cmd_setnote(req):
    doc = open_doc(req)
    page, page_no = resolve_page(doc, req)
    index = int(req.get("index", -1))
    if index < 0:
        fail("`index` ist erforderlich (0-basiert, wie in extract gelistet).")

    annots = list(page.annots() or [])
    if index >= len(annots):
        fail(f"Kein Annotation-Index {index} auf Seite {page_no} (nur {len(annots)} vorhanden).")

    note = (req.get("note") or "").strip()
    target = annots[index]
    info = target.info or {}
    info["content"] = note  # empty string clears the note
    target.set_info(info)
    target.update()
    save_doc(doc, req["path"])

    print(json.dumps({
        "ok": True,
        "action": "setnote",
        "page": page_no,
        "index": index,
        "note": note or None,
    }))


COMMANDS = {
    "highlight": cmd_highlight,
    "comment": cmd_comment,
    "extract": cmd_extract,
    "remove": cmd_remove,
    "setnote": cmd_setnote,
}


def main():
    if len(sys.argv) < 2 or sys.argv[1] not in COMMANDS:
        fail(f"Unbekanntes Kommando. Erlaubt: {', '.join(COMMANDS)}.")
    try:
        req = json.load(sys.stdin)
    except json.JSONDecodeError as exc:
        fail(f"Ungültiges JSON auf stdin: {exc}")
    COMMANDS[sys.argv[1]](req)


if __name__ == "__main__":
    main()
