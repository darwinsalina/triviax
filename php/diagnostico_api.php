<?php
/**
 * Endpoints diagnostico_* del Diagnóstico pedagógico (TRIVIAX+ Épica 4).
 *
 * Requiere sesión docente (o superadmin).
 *
 * Acciones:
 *   GET  diagnostico_perfiles — agrupa a los estudiantes de un proyecto en
 *        los tres perfiles pedagógicos + desafíos débiles.
 *   POST diagnostico_sugerir  — genera (o deja listo para el chatbot) un
 *        sub-proyecto remedial validado con el validador oficial.
 */

require_once __DIR__ . '/diagnostico_engine.php';
require_once __DIR__ . '/challenge_validator.php';
require_once __DIR__ . '/llm_client.php';

function _diagnostico_require_docente(): array {
    $u = triviax_usuario_actual();
    if (!$u) {
        triviax_api_error('UNAUTHORIZED', 'Se requiere sesión de docente.', 401);
    }
    if (!in_array($u['rol'], [TRIVIAX_ROL_DOCENTE, TRIVIAX_ROL_SUPERADMIN], true)) {
        triviax_api_error('FORBIDDEN', 'Solo docentes pueden ver el diagnóstico.', 403);
    }
    return $u;
}

function _diagnostico_project_or_fail(): string {
    $project = trim((string)($_GET['project'] ?? (triviax_api_input()['project'] ?? '')));
    if ($project === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $project)) {
        triviax_api_error('VALIDATION_ERROR', 'Proyecto inválido.', 400);
    }
    return $project;
}

function triviax_diagnostico_api_handle(string $action): void {
    _diagnostico_require_docente();
    require_once __DIR__ . '/db.php';
    if (!triviax_db_available()) {
        triviax_api_error('SERVER_ERROR', 'Base de datos no disponible.', 503);
    }
    $pdo = triviax_db();

    switch ($action) {
        case 'diagnostico_perfiles': {
            $project = _diagnostico_project_or_fail();
            $metricas = triviax_diagnostico_metricas_proyecto($pdo, $project);
            $perfiles = triviax_diagnostico_clasificar($metricas);
            triviax_api_success([
                'perfiles' => $perfiles,
                'total_estudiantes' => count($metricas),
                'desafios_debiles' => triviax_diagnostico_desafios_debiles($pdo, $project),
                'ia_disponible' => triviax_llm_available(),
            ]);
            break;
        }

        case 'diagnostico_sugerir': {
            triviax_api_require_post();
            triviax_verify_csrf_json();
            triviax_api_throttle('diagnostico_sugerir', 10, 300, 300);
            $project = _diagnostico_project_or_fail();

            $stmtT = $pdo->prepare('SELECT title FROM proyectos WHERE id = ?');
            $stmtT->execute([$project]);
            $titulo = (string)($stmtT->fetchColumn() ?: $project);

            $metricas = triviax_diagnostico_clasificar(triviax_diagnostico_metricas_proyecto($pdo, $project));
            $conteos = [];
            foreach ($metricas as $clave => $grupo) {
                $conteos[$clave] = count($grupo['estudiantes']);
            }
            $debiles = triviax_diagnostico_desafios_debiles($pdo, $project);
            $prompt = triviax_diagnostico_prompt_refuerzo($titulo, $debiles, $conteos);

            // Sin proveedor configurado: modo asistente (patrón de la casa,
            // igual que Lotto y "Estudia y responde"): el docente usa el
            // prompt en su chatbot y valida el JSON con el importador.
            if (!triviax_llm_available()) {
                triviax_api_success([
                    'modo' => 'asistente',
                    'prompt' => $prompt,
                    'proyecto_remedial' => null,
                ], 'No hay proveedor de IA configurado: copia el prompt en tu chatbot y usa el JSON resultante en el Panel de Actividades.');
            }

            try {
                $texto = triviax_llm_generate($prompt);
            } catch (Throwable $e) {
                triviax_api_error('LLM_ERROR', 'La IA no respondió: ' . $e->getMessage(), 502);
            }
            $payload = triviax_llm_extract_json($texto);
            if ($payload === null) {
                triviax_api_error('LLM_ERROR', 'La IA no devolvió un JSON válido.', 502);
            }
            // Validación oficial del proyecto antes de entregarlo al docente.
            $errores = triviax_validate_project($payload);
            if (!empty($errores)) {
                triviax_api_error('LLM_INVALID', 'El proyecto generado tiene errores: ' . implode(' | ', array_slice($errores, 0, 5)), 502);
            }
            triviax_audit_log('diagnostico_sugerir', 'proyecto', $project, ['desafios' => count($payload['challenges'] ?? [])]);
            triviax_api_success([
                'modo' => 'ia',
                'prompt' => $prompt,
                'proyecto_remedial' => $payload,
            ], 'Sub-proyecto remedial generado y validado. Impórtalo desde el Panel de Actividades.');
            break;
        }

        default:
            triviax_api_error('BAD_ACTION', 'Acción de diagnóstico desconocida.', 400);
    }
}
