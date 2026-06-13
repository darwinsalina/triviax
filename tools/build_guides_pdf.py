from __future__ import annotations

import html
import re
from pathlib import Path

from reportlab.lib import colors
from reportlab.lib.enums import TA_CENTER
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.units import mm
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.platypus import (
    HRFlowable,
    ListFlowable,
    ListItem,
    PageBreak,
    Paragraph,
    Preformatted,
    SimpleDocTemplate,
    Spacer,
)


ROOT = Path(__file__).resolve().parents[1]
FONT_DIR = Path("C:/Windows/Fonts")

GUIDES = [
    {
        "title": "Guia Docente TRIVIAX",
        "source": ROOT / "docs" / "GUIA_DOCENTE_TRIVIAX.md",
        "output": ROOT / "docs" / "GUIA_DOCENTE_TRIVIAX.pdf",
    },
    {
        "title": "Guia Jugadores TRIVIAX",
        "source": ROOT / "docs" / "GUIA_JUGADORES_TRIVIAX.md",
        "output": ROOT / "docs" / "GUIA_JUGADORES_TRIVIAX.pdf",
    },
]


def register_fonts() -> tuple[str, str, str]:
    regular = FONT_DIR / "DejaVuSans.ttf"
    bold = FONT_DIR / "DejaVuSans-Bold.ttf"
    mono = FONT_DIR / "DejaVuSansMono.ttf"
    if regular.exists() and bold.exists() and mono.exists():
        pdfmetrics.registerFont(TTFont("GuideSans", str(regular)))
        pdfmetrics.registerFont(TTFont("GuideSans-Bold", str(bold)))
        pdfmetrics.registerFont(TTFont("GuideMono", str(mono)))
        return "GuideSans", "GuideSans-Bold", "GuideMono"
    return "Helvetica", "Helvetica-Bold", "Courier"


BASE_FONT, BOLD_FONT, MONO_FONT = register_fonts()


def build_styles():
    styles = getSampleStyleSheet()
    styles.add(
        ParagraphStyle(
            name="GuideTitle",
            fontName=BOLD_FONT,
            fontSize=24,
            leading=29,
            textColor=colors.HexColor("#312e81"),
            spaceAfter=14,
        )
    )
    styles.add(
        ParagraphStyle(
            name="GuideH2",
            fontName=BOLD_FONT,
            fontSize=16,
            leading=20,
            textColor=colors.HexColor("#312e81"),
            spaceBefore=15,
            spaceAfter=7,
        )
    )
    styles.add(
        ParagraphStyle(
            name="GuideH3",
            fontName=BOLD_FONT,
            fontSize=12.5,
            leading=16,
            textColor=colors.HexColor("#3730a3"),
            spaceBefore=10,
            spaceAfter=5,
        )
    )
    styles.add(
        ParagraphStyle(
            name="GuideH4",
            fontName=BOLD_FONT,
            fontSize=10.5,
            leading=14,
            textColor=colors.HexColor("#111827"),
            spaceBefore=8,
            spaceAfter=4,
        )
    )
    styles.add(
        ParagraphStyle(
            name="GuideBody",
            fontName=BASE_FONT,
            fontSize=9.5,
            leading=13.4,
            textColor=colors.HexColor("#172033"),
            spaceAfter=6,
        )
    )
    styles.add(
        ParagraphStyle(
            name="GuideList",
            parent=styles["GuideBody"],
            leftIndent=8,
            firstLineIndent=0,
            spaceAfter=2,
        )
    )
    styles.add(
        ParagraphStyle(
            name="GuideCode",
            fontName=MONO_FONT,
            fontSize=8.2,
            leading=10.5,
            textColor=colors.HexColor("#111827"),
            backColor=colors.HexColor("#f8fafc"),
            borderColor=colors.HexColor("#d1d5db"),
            borderWidth=0.5,
            borderPadding=6,
            spaceBefore=5,
            spaceAfter=8,
        )
    )
    styles.add(
        ParagraphStyle(
            name="GuideFooter",
            fontName=BASE_FONT,
            fontSize=8,
            leading=10,
            alignment=TA_CENTER,
            textColor=colors.HexColor("#6b7280"),
        )
    )
    return styles


STYLES = build_styles()


def inline_markdown(value: str) -> str:
    text = html.escape(value)
    text = re.sub(r"`([^`]+)`", rf'<font name="{MONO_FONT}">\1</font>', text)
    text = re.sub(r"\*\*(.+?)\*\*", rf'<font name="{BOLD_FONT}">\1</font>', text)
    text = re.sub(r"\[([^\]]+)\]\(([^)]+)\)", r"\1", text)
    return text


def flush_list(story, items, ordered):
    if not items:
        return
    bullet_type = "1" if ordered else "bullet"
    flowable_items = [
        ListItem(Paragraph(inline_markdown(item), STYLES["GuideList"]), leftIndent=4)
        for item in items
    ]
    story.append(
        ListFlowable(
            flowable_items,
            bulletType=bullet_type,
            start="1",
            leftIndent=14,
            bulletFontName=BASE_FONT,
            bulletFontSize=8.5,
            spaceAfter=6,
        )
    )
    items.clear()


def markdown_to_story(markdown: str):
    story = []
    list_items = []
    list_ordered = False
    in_code = False
    code_lines = []

    for raw_line in markdown.splitlines():
        line = raw_line.rstrip()

        if line.startswith("```"):
            if in_code:
                story.append(Preformatted("\n".join(code_lines), STYLES["GuideCode"]))
                code_lines = []
                in_code = False
            else:
                flush_list(story, list_items, list_ordered)
                in_code = True
            continue

        if in_code:
            code_lines.append(line)
            continue

        if not line.strip():
            flush_list(story, list_items, list_ordered)
            continue

        if re.match(r"^\s*---+\s*$", line):
            flush_list(story, list_items, list_ordered)
            story.append(HRFlowable(width="100%", thickness=0.8, color=colors.HexColor("#e5e7eb")))
            story.append(Spacer(1, 5))
            continue

        heading = re.match(r"^(#{1,4})\s+(.*)$", line)
        if heading:
            flush_list(story, list_items, list_ordered)
            level = len(heading.group(1))
            text = inline_markdown(heading.group(2))
            style_name = {1: "GuideTitle", 2: "GuideH2", 3: "GuideH3"}.get(level, "GuideH4")
            story.append(Paragraph(text, STYLES[style_name]))
            if level == 1:
                story.append(HRFlowable(width="100%", thickness=1.2, color=colors.HexColor("#6366f1")))
                story.append(Spacer(1, 8))
            continue

        bullet = re.match(r"^\s*[-*]\s+(.*)$", line)
        ordered = re.match(r"^\s*\d+\.\s+(.*)$", line)
        if bullet or ordered:
            is_ordered = bool(ordered)
            if list_items and list_ordered != is_ordered:
                flush_list(story, list_items, list_ordered)
            list_ordered = is_ordered
            list_items.append((ordered or bullet).group(1))
            continue

        flush_list(story, list_items, list_ordered)
        story.append(Paragraph(inline_markdown(line), STYLES["GuideBody"]))

    if in_code:
        story.append(Preformatted("\n".join(code_lines), STYLES["GuideCode"]))
    flush_list(story, list_items, list_ordered)
    return story


def make_footer(title: str):
    def footer(canvas, doc):
        canvas.saveState()
        canvas.setFont(BASE_FONT, 8)
        canvas.setFillColor(colors.HexColor("#6b7280"))
        canvas.drawCentredString(A4[0] / 2, 10 * mm, f"{title} - pagina {doc.page}")
        canvas.restoreState()

    return footer


def build_pdf(title: str, source: Path, output: Path) -> None:
    markdown = source.read_text(encoding="utf-8")
    doc = SimpleDocTemplate(
        str(output),
        pagesize=A4,
        rightMargin=16 * mm,
        leftMargin=16 * mm,
        topMargin=18 * mm,
        bottomMargin=18 * mm,
        title=title,
        author="TRIVIAX",
    )
    story = markdown_to_story(markdown)
    doc.build(story, onFirstPage=make_footer(title), onLaterPages=make_footer(title))
    print(f"Generated {output.relative_to(ROOT)}")


def main() -> None:
    for guide in GUIDES:
        build_pdf(guide["title"], guide["source"], guide["output"])


if __name__ == "__main__":
    main()
