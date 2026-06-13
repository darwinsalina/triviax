<?php
/**
 * TRIVIAX — Utilidades de la Guía del Docente.
 * Convierte docs/GUIA_DOCENTE_TRIVIAX.md a HTML para el visor del panel
 * y para el email de bienvenida. Conversor Markdown mínimo: cubre solo la
 * sintaxis que usa la guía (encabezados, negrita, código inline y en bloque,
 * enlaces, listas, separadores). No es un parser Markdown general.
 */

function triviax_guia_docente_path(): string {
    return __DIR__ . '/../docs/GUIA_DOCENTE_TRIVIAX.md';
}

function triviax_guia_docente_pdf_path(): string {
    return __DIR__ . '/../docs/GUIA_DOCENTE_TRIVIAX.pdf';
}

function triviax_guia_docente_md(): string {
    $path = triviax_guia_docente_path();
    $md = is_readable($path) ? file_get_contents($path) : false;
    return $md === false ? '' : $md;
}

/**
 * Genera un id de anclaje al estilo GitHub para los enlaces internos del índice.
 */
function _triviax_md_slug(string $texto): string {
    $slug = mb_strtolower(trim($texto), 'UTF-8');
    $slug = preg_replace('/[^\p{L}\p{N}\s-]/u', '', $slug);
    $slug = preg_replace('/\s+/u', '-', $slug);
    return $slug;
}

function _triviax_md_inline(string $linea): string {
    // El texto ya viene escapado con htmlspecialchars.
    $linea = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $linea);
    $linea = preg_replace('/`([^`]+)`/', '<code>$1</code>', $linea);
    $linea = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', static function ($m) {
        $href = $m[2];
        // Solo anclas internas y http(s): cualquier otro esquema se neutraliza
        if (!preg_match('#^(\#|https?://)#', $href)) {
            return $m[1];
        }
        return '<a href="' . $href . '">' . $m[1] . '</a>';
    }, $linea);
    return $linea;
}

function triviax_md_to_html(string $md): string {
    $lineas = preg_split('/\r\n|\r|\n/', $md);
    $html = [];
    $enCodigo = false;
    $listaAbierta = '';   // '', 'ul' u 'ol'

    $cerrarLista = static function () use (&$listaAbierta, &$html) {
        if ($listaAbierta !== '') {
            $html[] = "</{$listaAbierta}>";
            $listaAbierta = '';
        }
    };

    foreach ($lineas as $linea) {
        // ── Bloques de código ────────────────────────────────────────
        if (preg_match('/^```/', $linea)) {
            $cerrarLista();
            $html[] = $enCodigo ? '</code></pre>' : '<pre><code>';
            $enCodigo = !$enCodigo;
            continue;
        }
        if ($enCodigo) {
            $html[] = htmlspecialchars($linea, ENT_QUOTES, 'UTF-8');
            continue;
        }

        $esc = htmlspecialchars($linea, ENT_QUOTES, 'UTF-8');

        // ── Separador ────────────────────────────────────────────────
        if (preg_match('/^\s*---+\s*$/', $linea)) {
            $cerrarLista();
            $html[] = '<hr>';
            continue;
        }

        // ── Encabezados ──────────────────────────────────────────────
        if (preg_match('/^(#{1,4})\s+(.*)$/', $linea, $m)) {
            $cerrarLista();
            $nivel = strlen($m[1]);
            $texto = htmlspecialchars($m[2], ENT_QUOTES, 'UTF-8');
            $id    = _triviax_md_slug($m[2]);
            $html[] = "<h{$nivel} id=\"{$id}\">" . _triviax_md_inline($texto) . "</h{$nivel}>";
            continue;
        }

        // ── Listas ───────────────────────────────────────────────────
        if (preg_match('/^\s*[-*]\s+(.*)$/', $linea, $m)) {
            if ($listaAbierta !== 'ul') { $cerrarLista(); $html[] = '<ul>'; $listaAbierta = 'ul'; }
            $html[] = '<li>' . _triviax_md_inline(htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8')) . '</li>';
            continue;
        }
        if (preg_match('/^\s*\d+\.\s+(.*)$/', $linea, $m)) {
            if ($listaAbierta !== 'ol') { $cerrarLista(); $html[] = '<ol>'; $listaAbierta = 'ol'; }
            $html[] = '<li>' . _triviax_md_inline(htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8')) . '</li>';
            continue;
        }

        // ── Párrafos / líneas vacías ─────────────────────────────────
        if (trim($linea) === '') {
            $cerrarLista();
            continue;
        }
        $cerrarLista();
        $html[] = '<p>' . _triviax_md_inline($esc) . '</p>';
    }

    if ($enCodigo)   { $html[] = '</code></pre>'; }
    if ($listaAbierta !== '') { $html[] = "</{$listaAbierta}>"; }

    return implode("\n", $html);
}

function triviax_guia_docente_html(): string {
    return triviax_md_to_html(triviax_guia_docente_md());
}
