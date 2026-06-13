<#
  build_manuals.ps1 — Atajo para Windows. Regenera los PDF de los manuales
  TRIVIAX llamando al build en Python (que tiene los subtítulos de portada).

  Uso:
    powershell -File tools\build_manuals.ps1
#>
$ErrorActionPreference = 'Stop'
python (Join-Path $PSScriptRoot 'build_manuals.py')
