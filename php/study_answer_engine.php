<?php
/**
 * TRIVIAX — Motor server-side de la modalidad "Estudia y responde" (study_answer).
 *
 * REGLAS NO NEGOCIABLES (docs/ESTUDIA_Y_RESPONDE.md §4 y §24):
 *  - El servidor es la única autoridad: corrige answer_payload, calcula puntos,
 *    actualiza dominio y decide la próxima carta.
 *  - El payload público de carta NUNCA incluye answer/correct/solution/expected
 *    ni feedback que revele la respuesta antes de responder.
 *
 * Dependencias: php/db.php, php/study_answer_validator.php
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/study_answer_validator.php';

// ─────────────────────────────────────────────
// CARGA DE MAZOS Y CARTAS
// ─────────────────────────────────────────────

function triviax_study_load_deck(int $deckId): ?array {
    $pdo = triviax_db();
    $stmt = $pdo->prepare('SELECT * FROM study_decks WHERE id = ?');
    $stmt->execute([$deckId]);
    $deck = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$deck) {
        return null;
    }
    $deck['settings'] = json_decode($deck['settings_json'] ?? '{}', true) ?: [];
    return $deck;
}

function triviax_study_load_deck_by_slug(string $slug): ?array {
    $pdo = triviax_db();
    $stmt = $pdo->prepare('SELECT * FROM study_decks WHERE slug = ?');
    $stmt->execute([$slug]);
    $deck = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$deck) {
        return null;
    }
    $deck['settings'] = json_decode($deck['settings_json'] ?? '{}', true) ?: [];
    return $deck;
}

function triviax_study_load_card(int $deckId, int $cardId): ?array {
    $pdo = triviax_db();
    $stmt = $pdo->prepare('SELECT * FROM study_cards WHERE id = ? AND deck_id = ?');
    $stmt->execute([$cardId, $deckId]);
    $card = $stmt->fetch(PDO::FETCH_ASSOC);
    return $card ?: null;
}

function triviax_study_load_cards(int $deckId): array {
    $pdo = triviax_db();
    $stmt = $pdo->prepare('SELECT * FROM study_cards WHERE deck_id = ? ORDER BY orden ASC, id ASC');
    $stmt->execute([$deckId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ─────────────────────────────────────────────
// PAYLOAD PÚBLICO (SIN RESPUESTAS)
// ─────────────────────────────────────────────

/**
 * Devuelve la versión "pública" del assessment: solo lo necesario para
 * renderizar la pregunta. Elimina answer/correct/solution/expected y,
 * en matching/sequence/classification, entrega los elementos BARAJADOS
 * sin el orden/asociación correcta.
 */
function triviax_study_public_assessment(array $assessment): array {
    $type = (string)($assessment['type'] ?? 'multiple_choice');
    $pub = [
        'type' => $type,
        'prompt' => (string)($assessment['prompt'] ?? ''),
    ];

    switch ($type) {
        case 'multiple_choice':
            $pub['options'] = array_values(array_map('strval', $assessment['options'] ?? []));
            break;

        case 'true_false':
            // No necesita opciones: el cliente muestra Verdadero/Falso.
            break;

        case 'fill_blank':
        case 'short_answer':
            // Campo de texto libre; no se publica nada extra.
            break;

        case 'matching_pairs': {
            $pairs = is_array($assessment['pairs'] ?? null) ? $assessment['pairs'] : [];
            $lefts = [];
            $rights = [];
            foreach ($pairs as $p) {
                $lefts[] = (string)($p['left'] ?? '');
                $rights[] = (string)($p['right'] ?? '');
            }
            shuffle($rights); // las derechas barajadas: la asociación correcta no viaja
            $pub['lefts'] = $lefts;
            $pub['rights'] = $rights;
            break;
        }

        case 'classification': {
            $cats = is_array($assessment['categories'] ?? null) ? $assessment['categories'] : [];
            $items = is_array($assessment['items'] ?? null) ? $assessment['items'] : [];
            $pub['categories'] = array_values(array_map(function ($c) {
                if (is_array($c)) {
                    $id = (string)($c['id'] ?? $c['label'] ?? '');
                    $label = (string)($c['label'] ?? $c['id'] ?? '');
                    return ['id' => $id, 'label' => $label];
                }
                return ['id' => (string)$c, 'label' => (string)$c];
            }, $cats));
            $texts = array_values(array_map(function ($it) {
                return is_array($it) ? (string)($it['text'] ?? $it['label'] ?? '') : (string)$it;
            }, $items));
            shuffle($texts); // sin categoryId: la clasificación correcta no viaja
            $pub['items'] = $texts;
            break;
        }

        case 'sequence_order': {
            $items = array_values(array_map('strval', $assessment['items'] ?? []));
            $shuffled = $items;
            // Garantizar que no llegue en el orden correcto (si hay más de 1 elemento)
            $tries = 0;
            while ($shuffled === $items && count($items) > 1 && $tries < 10) {
                shuffle($shuffled);
                $tries++;
            }
            $pub['items'] = $shuffled;
            break;
        }
    }

    return $pub;
}

/**
 * Payload público completo de una carta (fila de study_cards).
 * No incluye answer ni feedback (el feedback llega solo tras responder).
 */
function triviax_study_public_card_payload(array $cardRow): array {
    $assessment = json_decode($cardRow['assessment_json'] ?? '{}', true) ?: [];
    return [
        'card_id' => (int)$cardRow['id'],
        'card_key' => (string)$cardRow['card_key'],
        'title' => (string)$cardRow['titulo'],
        'order' => (int)$cardRow['orden'],
        'learningObjective' => (string)($cardRow['learning_objective'] ?? ''),
        'studyText' => (string)$cardRow['study_text'],
        'keyIdea' => (string)($cardRow['key_idea'] ?? ''),
        'vocabulary' => json_decode($cardRow['vocabulary_json'] ?? '[]', true) ?: [],
        'assessment' => triviax_study_public_assessment($assessment),
        'difficulty' => (string)$cardRow['difficulty'],
        'cognitiveLevel' => (string)$cardRow['cognitive_level'],
        'tags' => json_decode($cardRow['tags_json'] ?? '[]', true) ?: [],
        'estimatedReadTime' => $cardRow['estimated_read_time'] !== null ? (int)$cardRow['estimated_read_time'] : null,
        'estimatedAnswerTime' => $cardRow['estimated_answer_time'] !== null ? (int)$cardRow['estimated_answer_time'] : null,
        'points' => (int)$cardRow['points'],
    ];
}

// ─────────────────────────────────────────────
// EVALUACIÓN SERVER-SIDE
// ─────────────────────────────────────────────

function _study_norm_text($v): string {
    $v = mb_strtolower(trim((string)$v));
    // quitar tildes para comparación tolerante
    $map = ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'ñ'];
    $v = strtr($v, $map);
    // colapsar espacios
    return preg_replace('/\s+/u', ' ', $v);
}

/**
 * Evalúa la respuesta cruda del estudiante contra la carta.
 *
 * @param array $cardRow fila de study_cards (incluye assessment_json con answer)
 * @param mixed $answerPayload payload crudo enviado por el cliente
 * @return array ['is_correct'=>bool, 'partial_score'=>float 0..1, 'detail'=>array]
 */
function triviax_study_evaluate_answer(array $cardRow, $answerPayload): array {
    $assessment = json_decode($cardRow['assessment_json'] ?? '{}', true) ?: [];
    $type = (string)($assessment['type'] ?? 'multiple_choice');
    $payload = is_array($answerPayload) ? $answerPayload : [];

    $isCorrect = false;
    $partial = 0.0;
    $detail = [];

    switch ($type) {
        case 'multiple_choice': {
            $options = array_values(array_map('strval', $assessment['options'] ?? []));
            $answer = $assessment['answer'] ?? null;
            $correctText = is_int($answer)
                ? ($options[$answer] ?? '')
                : (string)$answer;
            $selected = $payload['selectedOption'] ?? $payload['selected'] ?? null;
            if (is_int($selected) || (is_string($selected) && ctype_digit($selected))) {
                $selIdx = (int)$selected;
                $selectedText = $options[$selIdx] ?? '';
            } else {
                $selectedText = (string)$selected;
            }
            $isCorrect = $selectedText !== '' && _study_norm_text($selectedText) === _study_norm_text($correctText);
            $partial = $isCorrect ? 1.0 : 0.0;
            break;
        }

        case 'true_false': {
            $answer = (bool)($assessment['answer'] ?? false);
            $given = $payload['value'] ?? $payload['selectedOption'] ?? null;
            if (is_string($given)) {
                $g = _study_norm_text($given);
                $given = in_array($g, ['true', 'verdadero', 'v', 'si', 'sí', '1'], true);
            }
            $isCorrect = is_bool($given) && $given === $answer;
            $partial = $isCorrect ? 1.0 : 0.0;
            break;
        }

        case 'fill_blank':
        case 'short_answer': {
            $answer = $assessment['answer'] ?? [];
            $accepted = is_array($answer) ? $answer : [$answer];
            $accepted = array_map('_study_norm_text', array_map('strval', $accepted));
            $given = _study_norm_text((string)($payload['text'] ?? $payload['value'] ?? ''));
            if ($given !== '') {
                if (in_array($given, $accepted, true)) {
                    $isCorrect = true;
                } else {
                    // Tolerancia a errores tipográficos leves: 1 edición por cada 8
                    // caracteres. En palabras cortas (<8) se exige coincidencia exacta,
                    // porque una sola letra puede cambiar el concepto (RAM vs ROM).
                    foreach ($accepted as $acc) {
                        $tolerance = (int)floor(mb_strlen($acc) / 8);
                        if ($tolerance > 0 && $acc !== '' && levenshtein($given, $acc) <= $tolerance) {
                            $isCorrect = true;
                            break;
                        }
                    }
                }
            }
            $partial = $isCorrect ? 1.0 : 0.0;
            break;
        }

        case 'matching_pairs': {
            $pairs = is_array($assessment['pairs'] ?? null) ? $assessment['pairs'] : [];
            $correctMap = [];
            foreach ($pairs as $p) {
                $correctMap[_study_norm_text($p['left'] ?? '')] = _study_norm_text($p['right'] ?? '');
            }
            $given = is_array($payload['pairs'] ?? null) ? $payload['pairs'] : [];
            $total = count($correctMap);
            $hits = 0;
            foreach ($given as $g) {
                $l = _study_norm_text($g['left'] ?? '');
                $r = _study_norm_text($g['right'] ?? '');
                if ($l !== '' && isset($correctMap[$l]) && $correctMap[$l] === $r) {
                    $hits++;
                }
            }
            $partial = $total > 0 ? $hits / $total : 0.0;
            $isCorrect = $total > 0 && $hits === $total;
            $detail = ['hits' => $hits, 'total' => $total];
            break;
        }

        case 'classification': {
            $items = is_array($assessment['items'] ?? null) ? $assessment['items'] : [];
            $correctMap = [];
            foreach ($items as $it) {
                if (is_array($it)) {
                    $correctMap[_study_norm_text($it['text'] ?? $it['label'] ?? '')] =
                        _study_norm_text($it['categoryId'] ?? $it['category'] ?? '');
                }
            }
            $given = is_array($payload['placements'] ?? null) ? $payload['placements'] : [];
            $total = count($correctMap);
            $hits = 0;
            foreach ($given as $g) {
                $txt = _study_norm_text($g['item'] ?? $g['text'] ?? '');
                $cat = _study_norm_text($g['categoryId'] ?? $g['category'] ?? '');
                if ($txt !== '' && isset($correctMap[$txt]) && $correctMap[$txt] === $cat) {
                    $hits++;
                }
            }
            $partial = $total > 0 ? $hits / $total : 0.0;
            $isCorrect = $total > 0 && $hits === $total;
            $detail = ['hits' => $hits, 'total' => $total];
            break;
        }

        case 'sequence_order': {
            $correct = array_values(array_map('_study_norm_text', array_map('strval', $assessment['items'] ?? [])));
            $given = array_values(array_map('_study_norm_text', array_map('strval', $payload['order'] ?? [])));
            $total = count($correct);
            $hits = 0;
            for ($i = 0; $i < $total; $i++) {
                if (isset($given[$i]) && $given[$i] === $correct[$i]) {
                    $hits++;
                }
            }
            $partial = $total > 0 ? $hits / $total : 0.0;
            $isCorrect = $total > 0 && $hits === $total;
            $detail = ['hits' => $hits, 'total' => $total];
            break;
        }

        default:
            $detail = ['error' => 'Tipo no soportado: ' . $type];
            break;
    }

    return ['is_correct' => $isCorrect, 'partial_score' => round($partial, 2), 'detail' => $detail];
}

/**
 * Calcula los puntos del intento. Autoridad server-side.
 * Bono de velocidad: +25 % si respondió en menos del 40 % del tiempo límite.
 * El time_ms del cliente solo puede REDUCIR el bono, nunca aumentar el puntaje base.
 */
function triviax_study_calculate_points(array $cardRow, array $evaluation, ?int $timeMs, array $settings): int {
    if (!$evaluation['is_correct']) {
        return 0; // sin penalizaciones negativas en v1
    }
    $base = (int)$cardRow['points'];
    $points = $base;
    $timeLimit = (int)($settings['timeLimit'] ?? 0);
    if ($timeLimit > 0 && $timeMs !== null && $timeMs > 0 && $timeMs < ($timeLimit * 1000 * 0.4)) {
        $points += (int)round($base * 0.25);
    }
    return $points;
}

// ─────────────────────────────────────────────
// ESTADO DE CARTAS Y SESIÓN (Leitner v1)
// ─────────────────────────────────────────────

/**
 * Actualiza el estado de una carta tras un intento evaluado.
 * Reglas Leitner v1: acierto => box+1; fallo => box=0 y vuelve al mazo.
 * Devuelve la fila actualizada de study_session_cards.
 */
function triviax_study_update_card_state(int $studySessionId, int $cardId, array $evaluation, array $settings): array {
    $pdo = triviax_db();

    $stmt = $pdo->prepare('SELECT * FROM study_session_cards WHERE study_session_id = ? AND card_id = ? FOR UPDATE');
    $stmt->execute([$studySessionId, $cardId]);
    $state = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$state) {
        throw new RuntimeException('CARD_STATE_NOT_FOUND');
    }

    $mastery = is_array($settings['mastery'] ?? null) ? $settings['mastery'] : [];
    $masteryBox = max(1, (int)($mastery['masteryBox'] ?? 3));
    $resetOnWrong = array_key_exists('resetOnWrong', $mastery) ? (bool)$mastery['resetOnWrong'] : true;

    $repeat = is_array($settings['repeatPolicy'] ?? null) ? $settings['repeatPolicy'] : [];
    $afterCards = max(1, (int)($repeat['afterCards'] ?? 3));
    $maxAttempts = max(1, (int)($repeat['maxAttempts'] ?? 3));

    $box = (int)$state['box'];
    $attempts = (int)$state['attempts'] + 1;
    $correct = (int)$state['correct_count'];
    $wrong = (int)$state['wrong_count'];
    $status = $state['status'];
    $dueIndex = null;
    $masteredAt = $state['mastered_at'];

    if ($evaluation['is_correct']) {
        $correct++;
        $box = min($box + 1, $masteryBox);
        if ($box >= $masteryBox) {
            $status = 'mastered';
            $masteredAt = date('Y-m-d H:i:s');
        } else {
            $status = 'learning';
            $dueIndex = $afterCards + 1; // volverá, pero con menos prioridad que las falladas
        }
    } else {
        $wrong++;
        if ($resetOnWrong) {
            $box = 0;
        }
        if ($wrong >= $maxAttempts) {
            // agotó los intentos: queda marcada como fallada y no vuelve a entrar
            $status = 'failed';
        } else {
            $status = 'review';
            $dueIndex = $afterCards; // vuelve luego de N cartas
        }
    }

    $upd = $pdo->prepare('
        UPDATE study_session_cards
           SET status = ?, box = ?, attempts = ?, correct_count = ?, wrong_count = ?,
               due_index = ?, last_seen_at = NOW(),
               first_seen_at = COALESCE(first_seen_at, NOW()),
               mastered_at = ?
         WHERE id = ?
    ');
    $upd->execute([$status, $box, $attempts, $correct, $wrong, $dueIndex, $masteredAt, $state['id']]);

    $state['status'] = $status;
    $state['box'] = $box;
    $state['attempts'] = $attempts;
    $state['correct_count'] = $correct;
    $state['wrong_count'] = $wrong;
    $state['due_index'] = $dueIndex;
    $state['mastered_at'] = $masteredAt;
    return $state;
}

/**
 * Decrementa due_index de las cartas en repaso (se llama una vez por carta mostrada).
 */
function triviax_study_tick_due(int $studySessionId): void {
    $pdo = triviax_db();
    $pdo->prepare('
        UPDATE study_session_cards
           SET due_index = GREATEST(0, due_index - 1)
         WHERE study_session_id = ? AND due_index IS NOT NULL AND due_index > 0
           AND status IN (\'review\', \'learning\')
    ')->execute([$studySessionId]);
}

/**
 * Selecciona la próxima carta a mostrar para la sesión.
 * Prioridad: (1) review/learning vencidas (due_index=0), (2) nuevas según
 * el modo de orden, (3) review/learning aún no vencidas (se adelantan si
 * no queda nada más). Devuelve la fila de study_cards o null si no quedan.
 */
function triviax_study_select_next_card(int $studySessionId): ?array {
    $pdo = triviax_db();

    $stmtS = $pdo->prepare('SELECT * FROM study_sessions WHERE id = ?');
    $stmtS->execute([$studySessionId]);
    $session = $stmtS->fetch(PDO::FETCH_ASSOC);
    if (!$session) {
        return null;
    }
    $orderMode = (string)$session['order_mode'];

    // 1) Cartas en repaso vencidas (due_index = 0 o NULL con estado review)
    $stmt = $pdo->prepare('
        SELECT c.* FROM study_session_cards sc
        JOIN study_cards c ON c.id = sc.card_id
        WHERE sc.study_session_id = ?
          AND sc.status IN (\'review\', \'learning\')
          AND COALESCE(sc.due_index, 0) = 0
        ORDER BY sc.wrong_count DESC, sc.last_seen_at ASC
        LIMIT 1
    ');
    $stmt->execute([$studySessionId]);
    $card = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($card) {
        return $card;
    }

    // 2) Cartas nuevas
    $orderSql = 'c.orden ASC, c.id ASC';
    if ($orderMode === 'random') {
        $orderSql = 'RAND()';
    } elseif ($orderMode === 'adaptive') {
        // v1: prioriza dificultad baja primero para construir base; preparado para mejorar
        $orderSql = "FIELD(c.difficulty,'baja','media','alta') ASC, c.orden ASC";
    }
    $stmt = $pdo->prepare("
        SELECT c.* FROM study_session_cards sc
        JOIN study_cards c ON c.id = sc.card_id
        WHERE sc.study_session_id = ? AND sc.status = 'new'
        ORDER BY {$orderSql}
        LIMIT 1
    ");
    $stmt->execute([$studySessionId]);
    $card = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($card) {
        return $card;
    }

    // 3) Repasos pendientes aún no vencidos (adelantar)
    $stmt = $pdo->prepare('
        SELECT c.* FROM study_session_cards sc
        JOIN study_cards c ON c.id = sc.card_id
        WHERE sc.study_session_id = ?
          AND sc.status IN (\'review\', \'learning\')
        ORDER BY COALESCE(sc.due_index, 0) ASC, sc.wrong_count DESC
        LIMIT 1
    ');
    $stmt->execute([$studySessionId]);
    $card = $stmt->fetch(PDO::FETCH_ASSOC);
    return $card ?: null;
}

/**
 * Resumen de progreso de una sesión (conteos por estado + métricas).
 */
function triviax_study_session_progress(int $studySessionId): array {
    $pdo = triviax_db();
    $stmt = $pdo->prepare('
        SELECT status, COUNT(*) AS n
        FROM study_session_cards
        WHERE study_session_id = ?
        GROUP BY status
    ');
    $stmt->execute([$studySessionId]);
    $byStatus = ['new' => 0, 'learning' => 0, 'review' => 0, 'failed' => 0, 'mastered' => 0, 'skipped' => 0];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $byStatus[$row['status']] = (int)$row['n'];
    }

    $stmtA = $pdo->prepare('
        SELECT COUNT(*) AS attempts,
               COALESCE(SUM(is_correct), 0) AS correct,
               COALESCE(SUM(points_delta), 0) AS points,
               COALESCE(SUM(time_ms), 0) AS time_ms,
               COALESCE(AVG(read_time_ms), 0) AS avg_read_ms
        FROM study_attempts WHERE study_session_id = ?
    ');
    $stmtA->execute([$studySessionId]);
    $agg = $stmtA->fetch(PDO::FETCH_ASSOC) ?: [];

    $total = array_sum($byStatus);
    $attempts = (int)($agg['attempts'] ?? 0);
    return [
        'total_cards' => $total,
        'by_status' => $byStatus,
        'attempts' => $attempts,
        'correct_attempts' => (int)($agg['correct'] ?? 0),
        'accuracy' => $attempts > 0 ? round(((int)$agg['correct']) * 100 / $attempts, 1) : 0,
        'points' => (int)($agg['points'] ?? 0),
        'total_time_ms' => (int)($agg['time_ms'] ?? 0),
        'avg_read_ms' => (int)($agg['avg_read_ms'] ?? 0),
    ];
}

/**
 * Marca la sesión como finalizada y persiste el resumen.
 */
function triviax_study_finish_session(int $studySessionId): array {
    $pdo = triviax_db();
    $progress = triviax_study_session_progress($studySessionId);

    // Conceptos difíciles: tags de las cartas más falladas
    $stmt = $pdo->prepare('
        SELECT c.titulo, c.tags_json, sc.wrong_count
        FROM study_session_cards sc
        JOIN study_cards c ON c.id = sc.card_id
        WHERE sc.study_session_id = ? AND sc.wrong_count > 0
        ORDER BY sc.wrong_count DESC
        LIMIT 5
    ');
    $stmt->execute([$studySessionId]);
    $hardest = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $hardest[] = [
            'title' => $row['titulo'],
            'wrong_count' => (int)$row['wrong_count'],
            'tags' => json_decode($row['tags_json'] ?? '[]', true) ?: [],
        ];
    }
    $progress['hardest_cards'] = $hardest;

    $pdo->prepare('
        UPDATE study_sessions
           SET estado = \'finished\', finished_at = NOW(), summary_json = ?
         WHERE id = ? AND estado = \'active\'
    ')->execute([json_encode($progress, JSON_UNESCAPED_UNICODE), $studySessionId]);

    return $progress;
}

// ─────────────────────────────────────────────
// GUARDADO DE MAZOS (panel docente)
// ─────────────────────────────────────────────

function triviax_study_slugify(string $title): string {
    $slug = $title;
    if (function_exists('iconv')) {
        $c = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $slug);
        if ($c !== false) {
            $slug = $c;
        }
    }
    $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $slug));
    $slug = trim($slug, '_');
    return ($slug !== '' ? $slug : 'mazo') . '_' . date('ymd') . '_' . substr(bin2hex(random_bytes(3)), 0, 6);
}

/**
 * Crea o actualiza un mazo desde un proyecto JSON study_answer YA VALIDADO.
 * Reemplaza el conjunto de cartas (delete + insert) dentro de una transacción.
 *
 * @return array ['deck_id'=>int, 'slug'=>string, 'cards'=>int]
 */
function triviax_study_save_deck(array $data, int $docenteId, ?int $deckId = null): array {
    $pdo = triviax_db();
    $sa = $data['studyAnswer'];
    $settings = $sa['settings'] ?? [];
    $titulo = mb_substr(trim((string)($sa['title'] ?? ($data['metadata']['title'] ?? 'Mazo'))), 0, 200);
    $descripcion = trim((string)($sa['description'] ?? ''));
    $source = is_array($sa['source'] ?? null) ? $sa['source'] : null;

    $pdo->beginTransaction();
    try {
        if ($deckId !== null) {
            // Propiedad: solo el docente dueño puede actualizar
            $stmt = $pdo->prepare('SELECT id, slug FROM study_decks WHERE id = ? AND docente_id = ? FOR UPDATE');
            $stmt->execute([$deckId, $docenteId]);
            $deck = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$deck) {
                throw new RuntimeException('DECK_NOT_FOUND_OR_FORBIDDEN');
            }
            $slug = $deck['slug'];
            $pdo->prepare('
                UPDATE study_decks
                   SET titulo = ?, descripcion = ?, settings_json = ?, source_json = ?
                 WHERE id = ?
            ')->execute([
                $titulo, $descripcion,
                json_encode($settings, JSON_UNESCAPED_UNICODE),
                $source !== null ? json_encode($source, JSON_UNESCAPED_UNICODE) : null,
                $deckId,
            ]);
            $pdo->prepare('DELETE FROM study_cards WHERE deck_id = ?')->execute([$deckId]);
        } else {
            $slug = triviax_study_slugify($titulo);
            $pdo->prepare('
                INSERT INTO study_decks (docente_id, slug, titulo, descripcion, settings_json, source_json, estado)
                VALUES (?, ?, ?, ?, ?, ?, \'draft\')
            ')->execute([
                $docenteId, $slug, $titulo, $descripcion,
                json_encode($settings, JSON_UNESCAPED_UNICODE),
                $source !== null ? json_encode($source, JSON_UNESCAPED_UNICODE) : null,
            ]);
            $deckId = (int)$pdo->lastInsertId();
        }

        $ins = $pdo->prepare('
            INSERT INTO study_cards
                (deck_id, card_key, orden, titulo, learning_objective, study_text, key_idea,
                 vocabulary_json, assessment_type, assessment_json, feedback_json,
                 difficulty, cognitive_level, tags_json, points, estimated_read_time, estimated_answer_time)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $count = 0;
        foreach ($sa['cards'] as $i => $card) {
            $n = triviax_study_normalize_card($card, $i);
            $ins->execute([
                $deckId, $n['card_key'], $n['orden'], $n['titulo'],
                $n['learning_objective'] !== '' ? $n['learning_objective'] : null,
                $n['study_text'],
                $n['key_idea'] !== '' ? $n['key_idea'] : null,
                json_encode($n['vocabulary'], JSON_UNESCAPED_UNICODE),
                $n['assessment_type'],
                json_encode($n['assessment'], JSON_UNESCAPED_UNICODE),
                json_encode($n['feedback'], JSON_UNESCAPED_UNICODE),
                $n['difficulty'], $n['cognitive_level'],
                json_encode($n['tags'], JSON_UNESCAPED_UNICODE),
                $n['points'], $n['estimated_read_time'], $n['estimated_answer_time'],
            ]);
            $count++;
        }

        $pdo->commit();
        return ['deck_id' => $deckId, 'slug' => $slug, 'cards' => $count];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
