<?php
/**
 * TRIVIAX — Validador de la modalidad "Etiquetar" (etiquetar).
 *
 * Valida el nombre, la caja de etiquetas y la lista de etiquetas con
 * coordenadas normalizadas 0..1. Espeja sanitizeLabels() del experimento.
 */

if (!defined('TRIVIAX_ETIQUETAR_MAX_LABELS')) {
    define('TRIVIAX_ETIQUETAR_MAX_LABELS', 40);
}

/**
 * Normaliza y valida las etiquetas recibidas (JSON string o array).
 * Cada etiqueta del cliente: { text, ax, ay, bx, by } (0..1).
 * Devuelve filas para BD usando lx/ly (= bx/by) para evitar la palabra
 * reservada `by` en MySQL.
 *
 * @return array|null  Lista de ['text','ax','ay','lx','ly'] o null si vacía.
 */
function triviax_etiquetar_sanitize_labels($raw): ?array {
    $arr = is_array($raw) ? $raw : json_decode((string)$raw, true);
    if (!is_array($arr) || count($arr) === 0) {
        return null;
    }
    $clamp = fn($v) => max(0.0, min(1.0, (float)$v));
    $out = [];
    foreach ($arr as $l) {
        if (!is_array($l)) continue;
        $text = trim((string)($l['text'] ?? ''));
        $text = preg_replace('/\s+/u', ' ', strip_tags($text));
        if ($text === '') continue;
        $text = function_exists('mb_substr') ? mb_substr($text, 0, 60, 'UTF-8') : substr($text, 0, 60);
        $out[] = [
            'text' => $text,
            'ax' => $clamp($l['ax'] ?? 0.5),
            'ay' => $clamp($l['ay'] ?? 0.5),
            'lx' => $clamp($l['bx'] ?? 0.5),
            'ly' => $clamp($l['by'] ?? 0.5),
        ];
        if (count($out) >= TRIVIAX_ETIQUETAR_MAX_LABELS) break;
    }
    return count($out) ? $out : null;
}

/** Normaliza la caja de etiquetas {x,y} (0..1) con valores por defecto. */
function triviax_etiquetar_normalize_box($raw): array {
    $box = is_array($raw) ? $raw : json_decode((string)$raw, true);
    $clamp = fn($v, $d) => is_numeric($v) ? max(0.0, min(1.0, (float)$v)) : $d;
    return [
        'x' => $clamp($box['x'] ?? null, 0.62),
        'y' => $clamp($box['y'] ?? null, 0.04),
    ];
}

/**
 * Valida el conjunto del proyecto de etiquetado (sin la imagen).
 *
 * @return array ['ok'=>bool, 'errors'=>string[], 'values'=>array]
 */
function triviax_etiquetar_validate(array $in): array {
    $errors = [];

    $titulo = trim((string)($in['name'] ?? $in['titulo'] ?? ''));
    if ($titulo === '') {
        $errors[] = 'Indicá un nombre para la actividad.';
    } elseif (mb_strlen($titulo) > 200) {
        $titulo = mb_substr($titulo, 0, 200);
    }

    $descripcion = trim((string)($in['descripcion'] ?? ''));
    if (mb_strlen($descripcion) > 1000) {
        $descripcion = mb_substr($descripcion, 0, 1000);
    }

    $labels = triviax_etiquetar_sanitize_labels($in['labels'] ?? '');
    if ($labels === null) {
        $errors[] = 'Colocá al menos una etiqueta sobre la imagen antes de crear la actividad.';
    }

    $box = triviax_etiquetar_normalize_box($in['box'] ?? '');

    return [
        'ok' => count($errors) === 0,
        'errors' => $errors,
        'values' => [
            'titulo' => $titulo,
            'descripcion' => $descripcion,
            'box' => $box,
            'labels' => $labels ?? [],
        ],
    ];
}
