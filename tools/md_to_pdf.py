# -*- coding: utf-8 -*-
"""
Convierte un manual Markdown de TRIVIAX a HTML estilizado y luego a PDF
usando Chrome/Edge en modo headless. Sin dependencias externas más allá de
la librería `markdown`. Uso:

    python md_to_pdf.py <entrada.md> <salida.pdf> "<Subtitulo opcional>"
"""
import sys
import os
import subprocess
import tempfile
import markdown

BRAND_CSS = """
:root {
  --azul:#3b82f6; --celeste:#38bdf8; --oro:#fcd360; --tinta:#1e293b;
  --gris:#475569; --gris-claro:#64748b; --linea:#e2e8f0;
  --fondo-suave:#f8fafc; --fondo-cita:#eff6ff;
}
* { box-sizing:border-box; }
@page { size:A4; margin:18mm 16mm 20mm 16mm; }
html { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
body {
  font-family:"Segoe UI","Helvetica Neue",Arial,sans-serif;
  color:var(--tinta); font-size:10.7pt; line-height:1.62; margin:0;
}
.portada {
  text-align:center; padding:46mm 0 30mm; page-break-after:always;
  background:
    radial-gradient(circle at 50% 18%, rgba(56,189,248,.16), transparent 60%),
    linear-gradient(180deg,#0f172a 0%, #1e293b 100%);
  color:#fff; border-radius:0; margin:-18mm -16mm 0; padding-left:16mm; padding-right:16mm;
  min-height:100vh; display:flex; flex-direction:column; justify-content:center; align-items:center;
}
.portada .logo { font-size:54pt; font-weight:800; letter-spacing:2px; line-height:1; margin-bottom:6mm; }
.portada .logo .tri { color:#fff;
  background:linear-gradient(180deg,#60a5fa,#38bdf8); -webkit-background-clip:text; background-clip:text; -webkit-text-fill-color:transparent; }
.portada .logo .equis { color:var(--oro); }
.portada h1 { font-size:24pt; font-weight:800; margin:2mm 0; color:#fff; border:0; }
.portada .sub { font-size:13pt; color:#cbd5e1; font-weight:400; max-width:120mm; }
.portada .tag {
  margin-top:14mm; display:inline-block; padding:3mm 9mm; border-radius:40px;
  background:rgba(99,102,241,.25); border:1px solid rgba(148,163,184,.4);
  color:#e0e7ff; font-size:11pt; font-weight:600; letter-spacing:.04em;
}
h1,h2,h3 { color:var(--tinta); line-height:1.25; font-weight:700; }
h2 {
  font-size:16.5pt; margin:11mm 0 4mm; padding-bottom:2.5mm;
  border-bottom:3px solid var(--azul); page-break-after:avoid;
}
h2::before { content:""; }
h3 { font-size:12.5pt; margin:7mm 0 2mm; color:var(--azul); page-break-after:avoid; }
p { margin:0 0 3mm; }
a { color:var(--azul); text-decoration:none; }
strong { color:var(--tinta); }
ul,ol { margin:0 0 4mm; padding-left:7mm; }
li { margin:1.4mm 0; }
hr { border:0; border-top:1px solid var(--linea); margin:7mm 0; }
code {
  font-family:"Cascadia Code",Consolas,monospace; font-size:9.3pt;
  background:#eef2ff; color:#4338ca; padding:.5mm 1.6mm; border-radius:3px;
}
pre {
  background:var(--fondo-suave); border:1px solid var(--linea); border-left:4px solid var(--celeste);
  border-radius:7px; padding:4mm 5mm; overflow:auto; font-size:9pt; line-height:1.5;
  page-break-inside:avoid; margin:0 0 4mm;
}
pre code { background:none; color:var(--tinta); padding:0; }
blockquote {
  margin:0 0 4mm; padding:3mm 5mm; background:var(--fondo-cita);
  border-left:4px solid var(--oro); border-radius:0 7px 7px 0; color:var(--gris);
  page-break-inside:avoid;
}
blockquote p { margin:0; }
table {
  border-collapse:collapse; width:100%; margin:0 0 5mm; font-size:9.8pt;
  page-break-inside:avoid;
}
th {
  background:linear-gradient(135deg,var(--azul),var(--celeste)); color:#fff;
  text-align:left; padding:2.6mm 3.5mm; font-weight:600;
}
td { padding:2.4mm 3.5mm; border-bottom:1px solid var(--linea); vertical-align:top; }
tr:nth-child(even) td { background:var(--fondo-suave); }
.toc-wrap { background:var(--fondo-suave); border:1px solid var(--linea); border-radius:9px; padding:3mm 6mm; }
.toc-wrap h2 { border:0; margin-top:2mm; }
.cierre {
  margin-top:9mm; padding-top:4mm; border-top:1px solid var(--linea);
  text-align:center; color:var(--gris-claro); font-style:italic; font-size:9.6pt;
}
img {
  display:block; margin:3mm auto 1mm; max-width:100%;
  border:1px solid var(--linea); border-radius:8px;
  box-shadow:0 4px 14px rgba(15,23,42,.10); page-break-inside:avoid;
}
/* Pie de figura: un párrafo cuyo único contenido es texto en cursiva. */
.figcap {
  text-align:center; color:var(--gris-claro);
  font-size:8.8pt; font-style:italic; margin:-1mm 0 5mm;
}
"""

def build_html(md_path, subtitulo):
    with open(md_path, encoding="utf-8") as f:
        text = f.read()

    lines = text.splitlines()
    # El primer "# ..." es el título; lo extraemos para la portada.
    titulo = "Manual TRIVIAX"
    body_lines = []
    grabando = False
    primer_quote_saltado = False
    for ln in lines:
        if ln.startswith("# ") and titulo == "Manual TRIVIAX" and not grabando:
            titulo = ln[2:].strip()
            # El logo ya dice TRIVIAX: evitamos repetirlo en el título grande.
            for sep in (" — TRIVIAX", " - TRIVIAX", "— TRIVIAX", "TRIVIAX"):
                titulo = titulo.replace(sep, "").strip(" —-")
            grabando = True
            continue
        # Saltar el blockquote de subtítulo que sigue al H1 (va a la portada)
        if grabando and not primer_quote_saltado:
            if ln.strip().startswith(">") or ln.strip() == "":
                continue
            primer_quote_saltado = True
        body_lines.append(ln)

    body_md = "\n".join(body_lines)
    html_body = markdown.markdown(
        body_md,
        extensions=["tables", "fenced_code", "toc", "sane_lists", "attr_list"],
    )

    # Las imágenes se referencian relativas al .md (ej. img/foo.png). Como el
    # HTML se renderiza desde una carpeta temporal, convertimos cada src a una
    # ruta file:// absoluta basada en la ubicación del .md.
    base_dir = os.path.dirname(os.path.abspath(md_path)).replace("\\", "/")
    import re

    def _abs_img(m):
        src = m.group(1)
        if src.startswith(("http://", "https://", "file://", "data:")):
            return m.group(0)
        full = src if os.path.isabs(src) else f"{base_dir}/{src}"
        return f'src="file:///{full.lstrip("/")}"'

    html_body = re.sub(r'src="([^"]+)"', _abs_img, html_body)

    # Un párrafo cuyo único contenido es cursiva se trata como pie de figura.
    html_body = re.sub(r'<p><em>(.*?)</em></p>',
                       r'<p class="figcap">\1</p>', html_body, flags=re.S)
    # Envolver el índice en una caja con salto de página después.
    html_body = html_body.replace(
        "<h2>Índice</h2>", '<div class="toc-wrap"><h2>Índice</h2>'
    )
    # cerrar la caja del índice antes del primer h2 que sigue al índice
    # (la lista del índice). Buscamos el cierre tras la primera lista.
    marca = '</div>'
    idx = html_body.find('<div class="toc-wrap">')
    if idx != -1:
        # cerrar tras el primer </ul> o </ol> posterior
        fin_ul = html_body.find('</ul>', idx)
        fin_ol = html_body.find('</ol>', idx)
        cands = [c for c in (fin_ul, fin_ol) if c != -1]
        if cands:
            corte = min(cands) + 5
            html_body = (html_body[:corte] + marca +
                         '<div style="page-break-after:always"></div>' +
                         html_body[corte:])

    logo = '<span class="tri">TRIVIA</span><span class="equis">X</span>'
    portada = f"""
    <div class="portada">
      <div class="logo">{logo}</div>
      <h1>{titulo}</h1>
      <div class="sub">{subtitulo}</div>
      <div class="tag">El Camino del Conocimiento</div>
    </div>
    """

    return f"""<!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8">
<style>{BRAND_CSS}</style></head>
<body>{portada}{html_body}</body></html>"""


def find_browser():
    candidates = [
        r"C:\Program Files\Google\Chrome\Application\chrome.exe",
        r"C:\Program Files (x86)\Google\Chrome\Application\chrome.exe",
        os.path.expandvars(r"%LOCALAPPDATA%\Google\Chrome\Application\chrome.exe"),
        r"C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe",
        r"C:\Program Files\Microsoft\Edge\Application\msedge.exe",
    ]
    for c in candidates:
        if os.path.isfile(c):
            return c
    raise RuntimeError("No se encontró Chrome ni Edge.")


def main():
    md_path, pdf_path = sys.argv[1], sys.argv[2]
    subtitulo = sys.argv[3] if len(sys.argv) > 3 else ""
    html = build_html(md_path, subtitulo)

    tmp_html = tempfile.NamedTemporaryFile(
        mode="w", suffix=".html", delete=False, encoding="utf-8")
    tmp_html.write(html)
    tmp_html.close()

    profile = tempfile.mkdtemp(prefix="chrome_pdf_")
    browser = find_browser()
    url = "file:///" + tmp_html.name.replace("\\", "/")
    cmd = [
        browser, "--headless", "--disable-gpu", "--no-pdf-header-footer",
        "--no-margins", f"--user-data-dir={profile}",
        f"--print-to-pdf={pdf_path}", url,
    ]
    subprocess.run(cmd, check=True, timeout=120)
    os.unlink(tmp_html.name)
    print("OK ->", pdf_path)


if __name__ == "__main__":
    main()
