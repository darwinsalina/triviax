<?php
/**
 * TRIVIAX+ — Diagnóstico pedagógico predictivo (Épica 4).
 *
 * Analiza las matrices de acierto-error de `intentos`/`stats_desafios` y
 * agrupa a los estudiantes en tres perfiles pedagógicos mediante umbrales
 * estrictos (opción explícitamente habilitada por el plan TRIVIAX+ frente a
 * K-means: con cohortes chicas los umbrales son deterministas y estables):
 *
 *   1. comprension_critica       — bloqueados en conceptos núcleo.
 *   2. inconsistencia_aplicacion — bien en teoría, caen en desafíos complejos
 *                                  (código, drag & drop, secuencias…).
 *   3. dominio_avanzado          — dominio sólido y consistente.
 */

/** Tipos de desafío considerados "complejos" (aplicación, no reconocimiento). */
function triviax_diagnostico_tipos_complejos(): array {
    return ['drag_drop', 'code_challenge', 'sequence_order', 'matching_pairs', 'image_hotspot', 'fill_blank'];
}

/** Metadatos de presentación de los tres perfiles. */
function triviax_diagnostico_perfiles_info(): array {
    return [
        'comprension_critica' => [
            'nombre' => 'Comprensión Crítica',
            'icono'  => '🔴',
            'descripcion' => 'Estudiantes bloqueados en conceptos núcleo: su tasa de acierto general es baja.',
            'recomendacion' => 'Reforzar los conceptos base con actividades de reconocimiento (opción múltiple, verdadero/falso) antes de pasar a aplicación.',
        ],
        'inconsistencia_aplicacion' => [
            'nombre' => 'Inconsistencia de Aplicación',
            'icono'  => '🟡',
            'descripcion' => 'Comprenden la teoría pero fallan al aplicarla en desafíos complejos (código, arrastrar y soltar, secuencias).',
            'recomendacion' => 'Practicar con desafíos de aplicación graduados: secuencias cortas, luego drag & drop y código.',
        ],
        'dominio_avanzado' => [
            'nombre' => 'Dominio Avanzado',
            'icono'  => '🟢',
            'descripcion' => 'Dominio sólido y consistente en todos los tipos de desafío.',
            'recomendacion' => 'Ofrecer retos de mayor dificultad o proponerles roles de tutoría entre pares.',
        ],
    ];
}

/**
 * Clasifica a UN estudiante en uno de los tres perfiles.
 * $m: ['total' => int, 'correctas' => int, 'complejos' => int, 'complejos_ok' => int]
 * Reglas (en orden):
 *   A. Con ≥3 intentos complejos, acierto general aceptable (≥0.5) pero una
 *      brecha de ≥0.2 frente al acierto complejo (o complejo <0.5) →
 *      inconsistencia_aplicacion.
 *   B. Acierto general < 0.675 → comprension_critica.
 *   C. Resto → dominio_avanzado.
 */
function triviax_diagnostico_perfil(array $m): string {
    $total = max(0, (int)($m['total'] ?? 0));
    if ($total === 0) {
        return 'comprension_critica'; // sin datos: prioridad de atención
    }
    $acc = ((int)($m['correctas'] ?? 0)) / $total;

    $complejos = (int)($m['complejos'] ?? 0);
    if ($complejos >= 3) {
        $accComplejo = ((int)($m['complejos_ok'] ?? 0)) / $complejos;
        if ($acc >= 0.5 && ($acc - $accComplejo >= 0.2 || $accComplejo < 0.5)) {
            return 'inconsistencia_aplicacion';
        }
    }
    if ($acc < 0.675) {
        return 'comprension_critica';
    }
    return 'dominio_avanzado';
}

/**
 * Agrupa una lista de estudiantes con métricas en los tres perfiles.
 * Devuelve [perfil => ['info' => …, 'estudiantes' => [ [nombre, métricas…] ]]].
 */
function triviax_diagnostico_clasificar(array $estudiantes): array {
    $info = triviax_diagnostico_perfiles_info();
    $out = [];
    foreach ($info as $clave => $meta) {
        $out[$clave] = ['info' => $meta, 'estudiantes' => []];
    }
    foreach ($estudiantes as $est) {
        $clave = triviax_diagnostico_perfil($est);
        $total = max(1, (int)($est['total'] ?? 0));
        $complejos = (int)($est['complejos'] ?? 0);
        $est['acierto'] = (int)round(((int)($est['correctas'] ?? 0)) / $total * 100);
        $est['acierto_complejo'] = $complejos > 0
            ? (int)round(((int)($est['complejos_ok'] ?? 0)) / $complejos * 100)
            : null;
        $out[$clave]['estudiantes'][] = $est;
    }
    foreach ($out as &$grupo) {
        usort($grupo['estudiantes'], static fn($a, $b) => $a['acierto'] <=> $b['acierto']);
    }
    return $out;
}

/**
 * Métricas por estudiante de un proyecto desde `intentos` (v4.0+).
 * Agrupa por jugador; usa el nombre visible de la sesión.
 */
function triviax_diagnostico_metricas_proyecto(PDO $pdo, string $proyectoId): array {
    $tipos = triviax_diagnostico_tipos_complejos();
    $ph = implode(',', array_fill(0, count($tipos), '?'));
    $stmt = $pdo->prepare("
        SELECT COALESCE(NULLIF(sj.nombre_display, ''), NULLIF(i.nombre_jugador, ''), CONCAT('Jugador ', i.jugador_id)) AS nombre,
               sj.usuario_id,
               COUNT(*) AS total,
               SUM(i.resultado = 'correct') AS correctas,
               SUM(i.challenge_type IN ({$ph})) AS complejos,
               SUM(i.resultado = 'correct' AND i.challenge_type IN ({$ph})) AS complejos_ok,
               ROUND(AVG(i.time_ms) / 1000, 1) AS tiempo_medio_s
        FROM intentos i
        JOIN sesiones s ON s.id = i.sesion_id
        LEFT JOIN sesion_jugadores sj ON sj.id = i.jugador_id
        WHERE s.proyecto_id = ?
        GROUP BY i.jugador_id, nombre, sj.usuario_id
        ORDER BY nombre
    ");
    $stmt->execute(array_merge($tipos, $tipos, [$proyectoId]));
    return array_map(static function (array $r): array {
        foreach (['total', 'correctas', 'complejos', 'complejos_ok'] as $k) {
            $r[$k] = (int)$r[$k];
        }
        $r['usuario_id'] = $r['usuario_id'] !== null ? (int)$r['usuario_id'] : null;
        $r['tiempo_medio_s'] = $r['tiempo_medio_s'] !== null ? (float)$r['tiempo_medio_s'] : null;
        return $r;
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * Desafíos más débiles del proyecto (peor acierto acumulado con ≥3 muestras)
 * desde `stats_desafios`. Base de la sugerencia de refuerzo con IA.
 */
function triviax_diagnostico_desafios_debiles(PDO $pdo, string $proyectoId, int $limite = 8): array {
    $stmt = $pdo->prepare('
        SELECT challenge_key, challenge_type, shown, correct, incorrect,
               ROUND(correct / shown * 100) AS acierto
        FROM stats_desafios
        WHERE proyecto_id = ? AND shown >= 3
        ORDER BY (correct / shown) ASC, shown DESC
        LIMIT ' . (int)$limite);
    $stmt->execute([$proyectoId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Prompt para que la IA (configurada o el chatbot del docente) genere un
 * sub-proyecto remedial en el formato canónico de TRIVIAX.
 */
function triviax_diagnostico_prompt_refuerzo(string $titulo, array $debiles, array $conteoPerfiles): string {
    $lineas = [];
    foreach ($debiles as $d) {
        $lineas[] = "- Desafío «{$d['challenge_key']}» (tipo {$d['challenge_type']}): "
            . "{$d['acierto']}% de acierto en {$d['shown']} respuestas.";
    }
    $listaDebiles = $lineas ? implode("\n", $lineas) : '- (sin datos suficientes por desafío)';

    return <<<PROMPT
Eres un asistente pedagógico de TRIVIAX. Diseña un SUB-PROYECTO DE REFUERZO
para la actividad «{$titulo}» a partir de estas debilidades detectadas:

Desafíos con peor rendimiento:
{$listaDebiles}

Distribución de perfiles del grupo:
- Comprensión Crítica (bloqueados en conceptos núcleo): {$conteoPerfiles['comprension_critica']} estudiantes.
- Inconsistencia de Aplicación (fallan en desafíos complejos): {$conteoPerfiles['inconsistencia_aplicacion']} estudiantes.
- Dominio Avanzado: {$conteoPerfiles['dominio_avanzado']} estudiantes.

Genera EXACTAMENTE un JSON válido (sin texto adicional ni markdown) con esta estructura:
{
  "metadata": {
    "title": "Refuerzo — {$titulo}",
    "author": "Asistente TRIVIAX",
    "nivel": "Refuerzo",
    "obs": "Sub-proyecto remedial generado a partir del diagnóstico pedagógico."
  },
  "challenges": [
    {
      "id": 1,
      "type": "multiple_choice",
      "prompt": { "text": "…" },
      "options": [
        { "id": "a", "text": "…", "correct": true },
        { "id": "b", "text": "…", "correct": false },
        { "id": "c", "text": "…", "correct": false }
      ],
      "difficulty": 1,
      "points": 10
    }
  ]
}

Reglas:
- 8 a 12 desafíos en español, centrados en los temas de los desafíos débiles.
- Mayoría de tipos simples (multiple_choice, true_false) para el perfil de
  Comprensión Crítica, y 2-3 desafíos de aplicación (sequence_order o
  fill_blank) para trabajar la Inconsistencia de Aplicación.
- Una sola opción correcta por desafío; dificultad entre 1 y 3.
PROMPT;
}
