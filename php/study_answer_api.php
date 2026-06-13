<?php
/**
 * TRIVIAX — Endpoints de la modalidad "Estudia y responde" (study_*).
 *
 * Incluido desde api.php cuando $action empieza con "study_".
 * Usa los helpers de api.php (triviax_api_json/success/error/input/require_post)
 * y de php/auth.php (CSRF, rate limit, sesión, roles).
 *
 * Seguridad (docs/ESTUDIA_Y_RESPONDE.md §4):
 *  - El estudiante recibe SOLO payloads públicos (sin answer/correct).
 *  - El servidor evalúa, puntúa y actualiza dominio.
 *  - Docente: sesión PHP rol docente + CSRF + propiedad del mazo.
 *  - Estudiante: session_token propio de la sesión de estudio + CSRF + rate limit.
 */

require_once __DIR__ . '/study_answer_engine.php';

// ─────────────────────────────────────────────
// Helpers locales
// ─────────────────────────────────────────────

function _study_api_require_db(): PDO {
    if (!triviax_db_available()) {
        triviax_api_error('SERVER_ERROR', 'Base de datos no disponible.', 503);
    }
    return triviax_db();
}

function _study_api_require_docente(): array {
    triviax_session_start();
    $u = triviax_usuario_actual();
    if ($u === null) {
        triviax_api_error('UNAUTHORIZED', 'Necesitás iniciar sesión.', 401);
    }
    if (!in_array($u['rol'], [TRIVIAX_ROL_DOCENTE, TRIVIAX_ROL_SUPERADMIN], true)) {
        triviax_api_error('FORBIDDEN', 'Acceso solo para docentes.', 403);
    }
    return $u;
}

/** Carga un mazo verificando propiedad (docente dueño o superadmin). */
function _study_api_load_own_deck(int $deckId, array $docente): array {
    $deck = triviax_study_load_deck($deckId);
    if (!$deck) {
        triviax_api_error('DECK_NOT_FOUND', 'El mazo no existe.', 404);
    }
    $esDuenio = (int)$deck['docente_id'] === (int)$docente['id'];
    if (!$esDuenio && $docente['rol'] !== TRIVIAX_ROL_SUPERADMIN) {
        triviax_api_error('FORBIDDEN', 'No tienes permiso sobre este mazo.', 403);
    }
    return $deck;
}

/** Valida el token de la sesión de estudio y la devuelve. */
function _study_api_validate_session(PDO $pdo, int $studySessionId, string $sessionToken, bool $lock = false): array {
    if ($studySessionId <= 0 || strlen($sessionToken) < 32) {
        triviax_api_error('UNAUTHORIZED', 'Credenciales de sesión de estudio inválidas.', 401);
    }
    $suffix = $lock ? ' FOR UPDATE' : '';
    $stmt = $pdo->prepare("SELECT * FROM study_sessions WHERE id = ? AND player_token_hash = ?{$suffix}");
    $stmt->execute([$studySessionId, hash('sha256', $sessionToken)]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$session) {
        triviax_api_error('FORBIDDEN', 'Sesión de estudio no autorizada.', 403);
    }
    return $session;
}

/** Rate limit genérico: registra cada petición y bloquea al superar el cupo. */
function _study_api_throttle(string $scope, string $identifier, int $maxPerWindow, int $windowSeconds, int $blockSeconds = 60): void {
    if (!triviax_rate_limit_check($scope, $identifier, $maxPerWindow, $windowSeconds)) {
        triviax_api_error('RATE_LIMITED', 'Demasiadas solicitudes. Espera un momento e intenta de nuevo.', 429);
    }
    triviax_rate_limit_hit($scope, $identifier, $windowSeconds, $blockSeconds, $maxPerWindow);
}

function _study_api_ip(): string {
    return (string)($_SERVER['REMOTE_ADDR'] ?? 'local');
}

/** Construye el bloque de feedback autorizado que se devuelve tras evaluar. */
function _study_api_feedback(array $cardRow, array $evaluation, bool $showExplanation): array {
    $fb = json_decode($cardRow['feedback_json'] ?? '{}', true) ?: [];
    $out = [
        'is_correct' => $evaluation['is_correct'],
        'partial_score' => $evaluation['partial_score'],
        'message' => $evaluation['is_correct']
            ? (string)($fb['correct'] ?? '¡Correcto!')
            : (string)($fb['incorrect'] ?? 'Respuesta incorrecta.'),
    ];
    if ($showExplanation) {
        $out['explanation'] = (string)($fb['explanation'] ?? '');
    }
    if (!empty($evaluation['detail'])) {
        $out['detail'] = $evaluation['detail'];
    }
    return $out;
}

// ─────────────────────────────────────────────
// Despachador
// ─────────────────────────────────────────────

function triviax_study_api_handle(string $action): void {
    switch ($action) {
        // ══════════════ DOCENTE ══════════════

        case 'study_validate_deck': {
            triviax_api_require_post();
            triviax_verify_csrf_json();
            _study_api_require_docente();
            $input = triviax_api_input();
            $data = $input['project'] ?? $input;
            $result = triviax_study_validate_project($data);
            triviax_api_json([
                'ok' => $result['ok'],
                'success' => $result['ok'],
                'code' => $result['ok'] ? 'OK' : 'VALIDATION_ERROR',
                'message' => $result['ok'] ? 'El mazo es válido.' : 'El mazo tiene errores.',
                'errors' => $result['errors'],
                'warnings' => $result['warnings'],
            ], $result['ok'] ? 200 : 422);
            break;
        }

        case 'study_save_deck': {
            triviax_api_require_post();
            triviax_verify_csrf_json();
            $docente = _study_api_require_docente();
            _study_api_require_db();
            _study_api_throttle('study_save', (string)$docente['id'], 30, 600);

            $input = triviax_api_input();
            $data = $input['project'] ?? null;
            $deckId = isset($input['deck_id']) ? (int)$input['deck_id'] : null;
            if (!is_array($data)) {
                triviax_api_error('VALIDATION_ERROR', 'Falta el proyecto JSON del mazo.', 400);
            }
            $result = triviax_study_validate_project($data);
            if (!$result['ok']) {
                triviax_api_json([
                    'ok' => false, 'success' => false, 'code' => 'VALIDATION_ERROR',
                    'message' => 'El mazo tiene errores y no se guardó.',
                    'errors' => $result['errors'], 'warnings' => $result['warnings'],
                ], 422);
            }
            try {
                $saved = triviax_study_save_deck($data, (int)$docente['id'], $deckId);
                triviax_audit_log('study_deck_saved', 'study_deck', (string)$saved['deck_id'], ['cards' => $saved['cards']]);
                triviax_api_success([
                    'deck_id' => $saved['deck_id'],
                    'slug' => $saved['slug'],
                    'cards' => $saved['cards'],
                    'warnings' => $result['warnings'],
                ], 'Mazo guardado como borrador.');
            } catch (RuntimeException $e) {
                if ($e->getMessage() === 'DECK_NOT_FOUND_OR_FORBIDDEN') {
                    triviax_api_error('FORBIDDEN', 'No tienes permiso sobre este mazo.', 403);
                }
                triviax_api_error('SERVER_ERROR', 'No se pudo guardar el mazo.', 500);
            } catch (Throwable $e) {
                triviax_api_error('SERVER_ERROR', 'No se pudo guardar el mazo.', 500);
            }
            break;
        }

        case 'study_publish_deck': {
            triviax_api_require_post();
            triviax_verify_csrf_json();
            $docente = _study_api_require_docente();
            $pdo = _study_api_require_db();
            $input = triviax_api_input();
            $deckId = (int)($input['deck_id'] ?? 0);
            $deck = _study_api_load_own_deck($deckId, $docente);
            $nuevoEstado = ($input['estado'] ?? 'published');
            if (!in_array($nuevoEstado, ['published', 'draft', 'archived'], true)) {
                triviax_api_error('VALIDATION_ERROR', 'Estado de mazo inválido.', 400);
            }
            // Para publicar, el mazo debe tener cartas
            if ($nuevoEstado === 'published') {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM study_cards WHERE deck_id = ?');
                $stmt->execute([$deckId]);
                if ((int)$stmt->fetchColumn() === 0) {
                    triviax_api_error('VALIDATION_ERROR', 'El mazo no tiene cartas; no puede publicarse.', 422);
                }
            }
            $pdo->prepare('UPDATE study_decks SET estado = ? WHERE id = ?')->execute([$nuevoEstado, $deckId]);
            triviax_audit_log('study_deck_estado', 'study_deck', (string)$deckId, ['estado' => $nuevoEstado]);
            triviax_api_success(['deck_id' => $deckId, 'estado' => $nuevoEstado], 'Estado del mazo actualizado.');
            break;
        }

        case 'study_list_decks': {
            $docente = _study_api_require_docente();
            $pdo = _study_api_require_db();
            $esSuper = $docente['rol'] === TRIVIAX_ROL_SUPERADMIN;
            $sql = '
                SELECT d.id, d.slug, d.titulo, d.descripcion, d.estado, d.created_at, d.updated_at,
                       (SELECT COUNT(*) FROM study_cards c WHERE c.deck_id = d.id) AS cards,
                       (SELECT COUNT(*) FROM study_sessions s WHERE s.deck_id = d.id) AS sessions
                FROM study_decks d ' . ($esSuper ? '' : 'WHERE d.docente_id = ? ') . '
                ORDER BY d.updated_at DESC, d.created_at DESC';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($esSuper ? [] : [(int)$docente['id']]);
            triviax_api_success(['decks' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;
        }

        case 'study_deck_preview': {
            // Vista completa (CON respuestas) — solo dueño/superadmin.
            $docente = _study_api_require_docente();
            _study_api_require_db();
            $deckId = (int)($_GET['deck_id'] ?? 0);
            $deck = _study_api_load_own_deck($deckId, $docente);
            $cards = triviax_study_load_cards($deckId);
            $full = array_map(function ($c) {
                return [
                    'card_id' => (int)$c['id'],
                    'card_key' => $c['card_key'],
                    'order' => (int)$c['orden'],
                    'title' => $c['titulo'],
                    'learningObjective' => $c['learning_objective'],
                    'studyText' => $c['study_text'],
                    'keyIdea' => $c['key_idea'],
                    'vocabulary' => json_decode($c['vocabulary_json'] ?? '[]', true) ?: [],
                    'assessment' => json_decode($c['assessment_json'] ?? '{}', true) ?: [],
                    'feedback' => json_decode($c['feedback_json'] ?? '{}', true) ?: [],
                    'difficulty' => $c['difficulty'],
                    'cognitiveLevel' => $c['cognitive_level'],
                    'tags' => json_decode($c['tags_json'] ?? '[]', true) ?: [],
                    'points' => (int)$c['points'],
                ];
            }, $cards);
            triviax_api_success([
                'deck' => [
                    'id' => (int)$deck['id'],
                    'slug' => $deck['slug'],
                    'titulo' => $deck['titulo'],
                    'descripcion' => $deck['descripcion'],
                    'estado' => $deck['estado'],
                    'settings' => $deck['settings'],
                ],
                'cards' => $full,
            ]);
            break;
        }

        case 'study_deck_report': {
            // Reporte docente básico del mazo: participantes, dominio, cartas más falladas.
            $docente = _study_api_require_docente();
            $pdo = _study_api_require_db();
            $deckId = (int)($_GET['deck_id'] ?? 0);
            _study_api_load_own_deck($deckId, $docente);

            $stmtS = $pdo->prepare("
                SELECT s.id, s.estado, s.started_at, s.finished_at, s.summary_json,
                       COALESCE(CONCAT(u.nombre, ' ', u.apellido), 'Invitado') AS estudiante
                FROM study_sessions s
                LEFT JOIN usuarios u ON u.id = s.usuario_id
                WHERE s.deck_id = ?
                ORDER BY s.started_at DESC
                LIMIT 200
            ");
            $stmtS->execute([$deckId]);
            $sessions = array_map(function ($s) {
                $s['summary'] = json_decode($s['summary_json'] ?? 'null', true);
                unset($s['summary_json']);
                return $s;
            }, $stmtS->fetchAll(PDO::FETCH_ASSOC));

            $stmtC = $pdo->prepare('
                SELECT c.card_key, c.titulo,
                       COUNT(a.id) AS attempts,
                       COALESCE(SUM(a.is_correct), 0) AS correct,
                       COALESCE(SUM(1 - a.is_correct), 0) AS wrong
                FROM study_cards c
                LEFT JOIN study_attempts a ON a.card_id = c.id
                WHERE c.deck_id = ?
                GROUP BY c.id, c.card_key, c.titulo
                ORDER BY wrong DESC, attempts DESC
            ');
            $stmtC->execute([$deckId]);

            triviax_api_success([
                'sessions' => $sessions,
                'cards' => $stmtC->fetchAll(PDO::FETCH_ASSOC),
            ]);
            break;
        }

        // ══════════════ ESTUDIANTE ══════════════

        case 'study_list_published': {
            // Catálogo público de mazos publicados (metadatos, sin cartas).
            $pdo = _study_api_require_db();
            $stmt = $pdo->query("
                SELECT d.id, d.slug, d.titulo, d.descripcion, d.created_at,
                       (SELECT COUNT(*) FROM study_cards c WHERE c.deck_id = d.id) AS cards
                FROM study_decks d
                WHERE d.estado = 'published'
                ORDER BY d.updated_at DESC
            ");
            triviax_api_success(['decks' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;
        }

        case 'study_start': {
            triviax_api_require_post();
            triviax_verify_csrf_json();
            $pdo = _study_api_require_db();
            _study_api_throttle('study_start', _study_api_ip(), 15, 600);

            $input = triviax_api_input();
            $deckId = (int)($input['deck_id'] ?? 0);
            $slug = trim((string)($input['slug'] ?? ''));
            $deck = $deckId > 0 ? triviax_study_load_deck($deckId) : ($slug !== '' ? triviax_study_load_deck_by_slug($slug) : null);
            if (!$deck || $deck['estado'] !== 'published') {
                triviax_api_error('DECK_NOT_FOUND', 'El mazo no existe o no está publicado.', 404);
            }
            $cards = triviax_study_load_cards((int)$deck['id']);
            if (count($cards) === 0) {
                triviax_api_error('DECK_NOT_FOUND', 'El mazo no tiene cartas.', 404);
            }

            triviax_session_start();
            $usuario = triviax_usuario_actual();
            $orderMode = (string)($deck['settings']['orderMode'] ?? 'progressive');
            if (!in_array($orderMode, ['progressive', 'random', 'adaptive'], true)) {
                $orderMode = 'progressive';
            }

            $token = bin2hex(random_bytes(32));
            try {
                $pdo->beginTransaction();
                $pdo->prepare('
                    INSERT INTO study_sessions (deck_id, usuario_id, player_token_hash, estado, order_mode, last_seen_at)
                    VALUES (?, ?, ?, \'active\', ?, NOW())
                ')->execute([
                    (int)$deck['id'],
                    $usuario !== null ? (int)$usuario['id'] : null,
                    hash('sha256', $token),
                    $orderMode,
                ]);
                $studySessionId = (int)$pdo->lastInsertId();

                $ins = $pdo->prepare('
                    INSERT INTO study_session_cards (study_session_id, card_id, status)
                    VALUES (?, ?, \'new\')
                ');
                foreach ($cards as $c) {
                    $ins->execute([$studySessionId, (int)$c['id']]);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                triviax_api_error('SERVER_ERROR', 'No se pudo iniciar la sesión de estudio.', 500);
            }

            $settings = $deck['settings'];
            triviax_api_success([
                'study_session_id' => $studySessionId,
                'session_token' => $token,
                'deck' => [
                    'id' => (int)$deck['id'],
                    'titulo' => $deck['titulo'],
                    'descripcion' => $deck['descripcion'],
                    'cards' => count($cards),
                    'orderMode' => $orderMode,
                    'timeLimit' => (int)($settings['timeLimit'] ?? 0),
                    'showExplanation' => (bool)($settings['showExplanation'] ?? true),
                    'mastery' => $settings['mastery'] ?? null,
                    'repeatPolicy' => $settings['repeatPolicy'] ?? null,
                ],
                'progress' => triviax_study_session_progress($studySessionId),
            ], 'Sesión de estudio iniciada.');
            break;
        }

        case 'study_state': {
            $pdo = _study_api_require_db();
            $studySessionId = (int)($_GET['study_session_id'] ?? 0);
            $sessionToken = trim((string)($_GET['session_token'] ?? ''));
            $session = _study_api_validate_session($pdo, $studySessionId, $sessionToken);
            $pdo->prepare('UPDATE study_sessions SET last_seen_at = NOW() WHERE id = ?')->execute([$studySessionId]);
            $summary = json_decode($session['summary_json'] ?? 'null', true);
            triviax_api_success([
                'estado' => $session['estado'],
                'order_mode' => $session['order_mode'],
                'progress' => triviax_study_session_progress($studySessionId),
                'summary' => $summary,
            ]);
            break;
        }

        case 'study_start_card': {
            // Entrega la PRÓXIMA carta (payload público, sin respuestas) y la marca como vista.
            triviax_api_require_post();
            triviax_verify_csrf_json();
            $pdo = _study_api_require_db();
            $input = triviax_api_input();
            $studySessionId = (int)($input['study_session_id'] ?? 0);
            $sessionToken = trim((string)($input['session_token'] ?? ''));
            _study_api_throttle('study_card', (string)$studySessionId, 60, 60);

            try {
                $pdo->beginTransaction();
                $session = _study_api_validate_session($pdo, $studySessionId, $sessionToken, true);
                if ($session['estado'] !== 'active') {
                    $pdo->rollBack();
                    triviax_api_error('SESSION_FINISHED', 'La sesión de estudio ya terminó.', 409);
                }
                $card = triviax_study_select_next_card($studySessionId);
                if (!$card) {
                    $pdo->commit();
                    triviax_api_success([
                        'card' => null,
                        'deck_complete' => true,
                        'progress' => triviax_study_session_progress($studySessionId),
                    ], 'No quedan cartas pendientes.');
                }
                // Marcar como vista (habilita submit) y correr la cola de repaso
                $pdo->prepare('
                    UPDATE study_session_cards
                       SET first_seen_at = COALESCE(first_seen_at, NOW()), last_seen_at = NOW()
                     WHERE study_session_id = ? AND card_id = ?
                ')->execute([$studySessionId, (int)$card['id']]);
                triviax_study_tick_due($studySessionId);
                $pdo->prepare('UPDATE study_sessions SET last_seen_at = NOW() WHERE id = ?')->execute([$studySessionId]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }

            triviax_api_success([
                'card' => triviax_study_public_card_payload($card),
                'deck_complete' => false,
                'progress' => triviax_study_session_progress($studySessionId),
            ]);
            break;
        }

        case 'study_submit_answer': {
            triviax_api_require_post();
            triviax_verify_csrf_json();
            $pdo = _study_api_require_db();
            $input = triviax_api_input();
            $studySessionId = (int)($input['study_session_id'] ?? 0);
            $sessionToken = trim((string)($input['session_token'] ?? ''));
            $cardId = (int)($input['card_id'] ?? 0);
            $idempotencyKey = trim((string)($input['idempotency_key'] ?? ''));
            $answerPayload = $input['answer_payload'] ?? null;
            $timeMs = isset($input['time_ms']) ? max(0, (int)$input['time_ms']) : null;
            $readTimeMs = isset($input['read_time_ms']) ? max(0, (int)$input['read_time_ms']) : null;

            if (!preg_match('/^[a-zA-Z0-9._:-]{8,64}$/', $idempotencyKey)) {
                triviax_api_error('INVALID_ANSWER', 'Clave de idempotencia inválida.', 400);
            }
            if ($cardId <= 0 || !is_array($answerPayload)) {
                triviax_api_error('INVALID_ANSWER', 'Falta la respuesta o la carta.', 400);
            }
            _study_api_throttle('study_submit', (string)$studySessionId, 40, 60);

            try {
                $pdo->beginTransaction();
                $session = _study_api_validate_session($pdo, $studySessionId, $sessionToken, true);
                if ($session['estado'] !== 'active') {
                    $pdo->rollBack();
                    triviax_api_error('SESSION_FINISHED', 'La sesión de estudio ya terminó.', 409);
                }

                // Idempotencia: si ya se registró, devolver sin duplicar
                $stmtR = $pdo->prepare('SELECT id, is_correct, points_delta FROM study_attempts WHERE idempotency_key = ? LIMIT 1');
                $stmtR->execute([$idempotencyKey]);
                $prev = $stmtR->fetch(PDO::FETCH_ASSOC);
                if ($prev) {
                    $pdo->commit();
                    triviax_api_success([
                        'cached' => true,
                        'is_correct' => (bool)$prev['is_correct'],
                        'points_delta' => (int)$prev['points_delta'],
                    ], 'Respuesta ya registrada.');
                }

                $deck = triviax_study_load_deck((int)$session['deck_id']);
                $card = triviax_study_load_card((int)$session['deck_id'], $cardId);
                if (!$card) {
                    $pdo->rollBack();
                    triviax_api_error('CARD_NOT_FOUND', 'La carta no pertenece a este mazo.', 404);
                }

                // La carta debe haber sido servida y no estar cerrada
                $stmtSt = $pdo->prepare('SELECT * FROM study_session_cards WHERE study_session_id = ? AND card_id = ? FOR UPDATE');
                $stmtSt->execute([$studySessionId, $cardId]);
                $cardState = $stmtSt->fetch(PDO::FETCH_ASSOC);
                if (!$cardState || $cardState['first_seen_at'] === null) {
                    $pdo->rollBack();
                    triviax_api_error('CARD_NOT_FOUND', 'La carta no fue entregada en esta sesión.', 409);
                }
                if (in_array($cardState['status'], ['mastered', 'failed', 'skipped'], true)) {
                    $pdo->rollBack();
                    triviax_api_error('CARD_ALREADY_ANSWERED', 'Esta carta ya está cerrada en la sesión.', 409);
                }

                $settings = $deck['settings'] ?? [];

                // ── Evaluación y puntaje: AUTORIDAD SERVER-SIDE ──
                $evaluation = triviax_study_evaluate_answer($card, $answerPayload);
                $pointsDelta = triviax_study_calculate_points($card, $evaluation, $timeMs, $settings);

                triviax_session_start();
                $usuario = triviax_usuario_actual();

                $pdo->prepare('
                    INSERT INTO study_attempts
                        (study_session_id, card_id, usuario_id, answer_payload, is_correct,
                         partial_score, points_delta, time_ms, read_time_ms, idempotency_key)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ')->execute([
                    $studySessionId, $cardId,
                    $usuario !== null ? (int)$usuario['id'] : ($session['usuario_id'] !== null ? (int)$session['usuario_id'] : null),
                    json_encode($answerPayload, JSON_UNESCAPED_UNICODE),
                    $evaluation['is_correct'] ? 1 : 0,
                    $evaluation['partial_score'],
                    $pointsDelta,
                    $timeMs, $readTimeMs,
                    $idempotencyKey,
                ]);

                $newState = triviax_study_update_card_state($studySessionId, $cardId, $evaluation, $settings);
                $pdo->prepare('UPDATE study_sessions SET last_seen_at = NOW() WHERE id = ?')->execute([$studySessionId]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                triviax_api_error('SERVER_ERROR', 'No se pudo registrar la respuesta.', 500);
            }

            $showExplanation = (bool)(($deck['settings']['showExplanation'] ?? true));
            $progress = triviax_study_session_progress($studySessionId);
            $pendientes = $progress['by_status']['new'] + $progress['by_status']['learning'] + $progress['by_status']['review'];

            triviax_api_success([
                'feedback' => _study_api_feedback($card, $evaluation, $showExplanation),
                'points_delta' => $pointsDelta,
                'card_status' => $newState['status'],
                'card_box' => (int)$newState['box'],
                'returns_to_deck' => in_array($newState['status'], ['review', 'learning'], true),
                'progress' => $progress,
                'deck_complete' => $pendientes === 0,
            ]);
            break;
        }

        case 'study_skip_card': {
            triviax_api_require_post();
            triviax_verify_csrf_json();
            $pdo = _study_api_require_db();
            $input = triviax_api_input();
            $studySessionId = (int)($input['study_session_id'] ?? 0);
            $sessionToken = trim((string)($input['session_token'] ?? ''));
            $cardId = (int)($input['card_id'] ?? 0);
            try {
                $pdo->beginTransaction();
                $session = _study_api_validate_session($pdo, $studySessionId, $sessionToken, true);
                if ($session['estado'] !== 'active') {
                    $pdo->rollBack();
                    triviax_api_error('SESSION_FINISHED', 'La sesión de estudio ya terminó.', 409);
                }
                $upd = $pdo->prepare('
                    UPDATE study_session_cards
                       SET status = \'skipped\', last_seen_at = NOW()
                     WHERE study_session_id = ? AND card_id = ?
                       AND status IN (\'new\', \'learning\', \'review\')
                ');
                $upd->execute([$studySessionId, $cardId]);
                if ($upd->rowCount() === 0) {
                    $pdo->rollBack();
                    triviax_api_error('CARD_NOT_FOUND', 'La carta no puede saltearse.', 409);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                triviax_api_error('SERVER_ERROR', 'No se pudo saltear la carta.', 500);
            }
            triviax_api_success(['progress' => triviax_study_session_progress($studySessionId)]);
            break;
        }

        case 'study_finish': {
            triviax_api_require_post();
            triviax_verify_csrf_json();
            $pdo = _study_api_require_db();
            $input = triviax_api_input();
            $studySessionId = (int)($input['study_session_id'] ?? 0);
            $sessionToken = trim((string)($input['session_token'] ?? ''));
            _study_api_throttle('study_finish', (string)$studySessionId, 5, 600);
            $session = _study_api_validate_session($pdo, $studySessionId, $sessionToken);
            if ($session['estado'] === 'finished') {
                $summary = json_decode($session['summary_json'] ?? 'null', true);
                triviax_api_success(['summary' => $summary, 'cached' => true], 'La sesión ya estaba finalizada.');
            }
            $summary = triviax_study_finish_session($studySessionId);
            triviax_api_success(['summary' => $summary], 'Sesión de estudio finalizada.');
            break;
        }

        default:
            triviax_api_error('VALIDATION_ERROR', 'Acción study_ desconocida.', 400);
    }
}
