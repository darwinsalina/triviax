# -*- coding: utf-8 -*-
"""
build_manuals.py — Regenera los PDF de los manuales TRIVIAX desde sus
fuentes Markdown. Fuente única de verdad de los subtítulos de portada.

Uso:
    python tools/build_manuals.py
"""
import os
import sys
import subprocess

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
DOCS = os.path.join(ROOT, "docs")
CONV = os.path.join(HERE, "md_to_pdf.py")

MANUALES = [
    ("MANUAL_DOCENTE_TRIVIAX",
     "Plataforma educativa para gamificar el aula, crear actividades y evaluar aprendizajes"),
    ("MANUAL_ESTUDIANTE_TRIVIAX",
     "Aprende jugando: tableros, fichas de estudio y desafios en vivo"),
]


def main():
    errores = 0
    for nombre, subtitulo in MANUALES:
        src = os.path.join(DOCS, nombre + ".md")
        out = os.path.join(DOCS, nombre + ".pdf")
        if not os.path.isfile(src):
            print(f"  aviso: no existe {src}", file=sys.stderr)
            continue
        r = subprocess.run([sys.executable, CONV, src, out, subtitulo])
        if r.returncode != 0:
            print(f"  error al generar {out}", file=sys.stderr)
            errores += 1
    if errores:
        sys.exit(1)
    print(f"Manuales PDF actualizados en {DOCS}")


if __name__ == "__main__":
    main()
