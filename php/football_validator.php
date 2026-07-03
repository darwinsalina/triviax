<?php
/**
 * Validador liviano para tableros football_goal_race.
 */

require_once __DIR__ . '/football_engine.php';

function triviax_football_validate_board(array $board): array {
    $errors = [];
    if (($board['type'] ?? '') !== TRIVIAX_FOOTBALL_MODE) {
        $errors[] = 'El tablero debe tener type=football_goal_race.';
    }
    if (($board['goalPosition'] ?? null) !== TRIVIAX_FOOTBALL_GOAL) {
        $errors[] = 'goalPosition debe ser 30.';
    }
    foreach (['blue', 'red'] as $side) {
        $path = $board['paths'][$side] ?? null;
        if (!is_array($path) || count($path) !== TRIVIAX_FOOTBALL_GOAL + 1) {
            $errors[] = "El recorrido {$side} debe tener 31 posiciones.";
            continue;
        }
        foreach ($path as $i => $cell) {
            if (($cell['n'] ?? null) !== $i) {
                $errors[] = "El recorrido {$side} tiene n incorrecto en indice {$i}.";
            }
            $x = $cell['x'] ?? null;
            $y = $cell['y'] ?? null;
            if (!is_numeric($x) || !is_numeric($y) || $x < 0 || $x > 100 || $y < 0 || $y > 100) {
                $errors[] = "La posicion {$i} de {$side} debe usar coordenadas porcentuales 0-100.";
            }
        }
    }
    return $errors;
}
