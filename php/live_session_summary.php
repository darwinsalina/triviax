<?php
/**
 * Helpers compartidos para el monitor docente de partidas en vivo.
 */

function triviax_live_session_load_for_docente(PDO $pdo, int $sessionId, int $docenteId): ?array {
    $stmt = $pdo->prepare('
        SELECT s.id, s.nombre, s.codigo_acceso, s.estado, s.max_jugadores,
               p.title AS proyecto_title
        FROM sesiones s
        JOIN proyectos p ON p.id = s.proyecto_id
        WHERE s.id = ? AND s.docente_id = ?
    ');
    $stmt->execute([$sessionId, $docenteId]);
    $sesion = $stmt->fetch(PDO::FETCH_ASSOC);
    return $sesion ?: null;
}

function triviax_live_session_summary(PDO $pdo, array $sesion): array {
    $sessionId = (int)$sesion['id'];

    $stmtPreguntas = $pdo->prepare('
        SELECT
            i.challenge_key,
            COALESCE(NULLIF(MAX(i.prompt_text), \'\'), CONCAT(\'Pregunta \', i.challenge_key)) AS prompt_text,
            SUM(CASE WHEN i.resultado = \'correct\' THEN 1 ELSE 0 END) AS correctas,
            SUM(CASE WHEN i.resultado <> \'correct\' THEN 1 ELSE 0 END) AS errores,
            COUNT(*) AS total,
            MAX(i.id) AS ultimo_intento_id
        FROM intentos i
        WHERE i.sesion_id = ?
        GROUP BY i.challenge_key
        HAVING total > 0
        ORDER BY correctas DESC, errores ASC, ultimo_intento_id DESC
    ');
    $stmtPreguntas->execute([$sessionId]);

    $stmtTotales = $pdo->prepare('
        SELECT
            (SELECT COUNT(*) FROM sesion_jugadores sj WHERE sj.sesion_id = s.id) AS jugadores,
            (SELECT COUNT(*) FROM intentos i WHERE i.sesion_id = s.id) AS respuestas,
            (SELECT COUNT(*) FROM intentos i WHERE i.sesion_id = s.id AND i.resultado = \'correct\') AS correctas,
            (SELECT COUNT(*) FROM intentos i WHERE i.sesion_id = s.id AND i.resultado <> \'correct\') AS errores
        FROM sesiones s
        WHERE s.id = ?
    ');
    $stmtTotales->execute([$sessionId]);
    $totales = $stmtTotales->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'success' => true,
        'session' => [
            'id' => $sessionId,
            'nombre' => (string)$sesion['nombre'],
            'estado' => (string)$sesion['estado'],
            'codigo' => (string)$sesion['codigo_acceso'],
            'proyecto' => (string)$sesion['proyecto_title'],
        ],
        'totals' => [
            'jugadores' => (int)($totales['jugadores'] ?? 0),
            'respuestas' => (int)($totales['respuestas'] ?? 0),
            'correctas' => (int)($totales['correctas'] ?? 0),
            'errores' => (int)($totales['errores'] ?? 0),
        ],
        'questions' => array_map(static function (array $row): array {
            return [
                'challenge_key' => (string)$row['challenge_key'],
                'prompt_text' => (string)$row['prompt_text'],
                'correctas' => (int)$row['correctas'],
                'errores' => (int)$row['errores'],
                'total' => (int)$row['total'],
            ];
        }, $stmtPreguntas->fetchAll(PDO::FETCH_ASSOC)),
        'updated_at' => date('c'),
    ];
}
