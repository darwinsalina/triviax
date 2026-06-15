<?php
/**
 * TRIVIAX — Validador de la modalidad "Puzle" (jigsaw).
 *
 * Normaliza y valida las opciones que envía el docente al crear un puzle.
 * No toca la imagen (eso lo hace php/image_upload.php); aquí solo opciones.
 */

/**
 * Valida y normaliza las opciones de creación recibidas por POST.
 *
 * @param array $in  Datos crudos ($_POST).
 * @return array ['ok'=>bool, 'errors'=>string[], 'values'=>array]
 */
function triviax_jigsaw_validate_options(array $in): array {
    $errors = [];

    $titulo = trim((string)($in['name'] ?? $in['titulo'] ?? ''));
    if ($titulo === '') {
        $errors[] = 'Indicá un nombre para la actividad.';
    } elseif (mb_strlen($titulo) > 200) {
        $titulo = mb_substr($titulo, 0, 200);
    }

    $descripcion = trim((string)($in['descripcion'] ?? $in['description'] ?? ''));
    if (mb_strlen($descripcion) > 1000) {
        $descripcion = mb_substr($descripcion, 0, 1000);
    }

    $difficultyMode = ($in['difficulty_mode'] ?? 'free') === 'fixed' ? 'fixed' : 'free';

    $fixedGrid = (int)($in['fixed_grid'] ?? 4);
    if ($fixedGrid < 2 || $fixedGrid > 40) {
        if ($difficultyMode === 'fixed') {
            $errors[] = 'La cuadrícula fija debe estar entre 2 y 40.';
        }
        $fixedGrid = max(2, min(40, $fixedGrid));
    }

    $playMode = in_array($in['play_mode'] ?? '', ['tray', 'scatter', 'choose'], true)
        ? $in['play_mode'] : 'tray';

    $pieceShape = in_array($in['piece_shape'] ?? '', ['classic', 'jigsaw'], true)
        ? $in['piece_shape'] : 'classic';

    $showHint = !empty($in['show_hint']) && $in['show_hint'] !== 'false' && $in['show_hint'] !== '0';
    $hintOpacity = (int)($in['hint_opacity'] ?? 60);
    $hintOpacity = max(0, min(100, $hintOpacity));

    return [
        'ok' => count($errors) === 0,
        'errors' => $errors,
        'values' => [
            'titulo' => $titulo,
            'descripcion' => $descripcion,
            'difficulty_mode' => $difficultyMode,
            'fixed_grid' => $fixedGrid,
            'play_mode' => $playMode,
            'piece_shape' => $pieceShape,
            'show_hint' => $showHint ? 1 : 0,
            'hint_opacity' => $hintOpacity,
        ],
    ];
}
