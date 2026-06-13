<?php
/**
 * TRIVIAX — Validador PHP de la modalidad "TRIVIAX Lotto" (lotto_oral).
 *
 * Espejo en servidor de js/validators/lottoValidator.js.
 * Es la AUTORIDAD de validación: nada se guarda/publica sin pasar por aquí.
 *
 * Funciones públicas:
 *   triviax_lotto_validate_payload(array $data): array  // ['ok'=>bool,'errors'=>[],'warnings'=>[]]
 *   triviax_lotto_is_payload_valid(array $data): bool
 *
 * Reglas y límites: docs/LOTTO.md §Validación.
 */

if (!defined('LOTTO_MODE')) {
    define('LOTTO_MODE', 'lotto_oral');
}

function triviax_lotto_difficulties(): array { return ['baja', 'media', 'alta']; }
function triviax_lotto_draw_modes(): array { return ['random_no_repeat', 'random_simple', 'teacher_choice']; }

function triviax_lotto_limits(): array {
    return [
        'sectionsMin'        => 4,
        'sectionsMax'        => 5,
        'studentsMin'        => 1,
        'studentsMax'        => 60,
        'questionsMin'       => 1,
        'questionsMax'       => 5,
        'studyTextMax'       => 2000,
        'sourceTextMax'      => 12000,
        'studyMinutesMin'    => 1,
        'responseMinutesMin' => 1,
    ];
}

function _lotto_strlen($v): int {
    return is_string($v) ? mb_strlen(trim($v)) : 0;
}

function _lotto_is_list($a): bool {
    if (!is_array($a)) return false;
    return $a === [] || array_keys($a) === range(0, count($a) - 1);
}

/**
 * Valida un payload de actividad Lotto completo.
 * @return array ['ok'=>bool, 'errors'=>string[], 'warnings'=>string[]]
 */
function triviax_lotto_validate_payload($data): array {
    $errors = [];
    $warnings = [];
    $limits = triviax_lotto_limits();

    if (!is_array($data)) {
        return ['ok' => false, 'errors' => ['El proyecto no es un objeto JSON válido.'], 'warnings' => []];
    }

    $metadata = $data['metadata'] ?? null;
    $lotto = $data['lotto'] ?? null;

    if (!is_array($metadata) || ($metadata['mode'] ?? null) !== LOTTO_MODE) {
        $errors[] = 'metadata.mode debe ser "' . LOTTO_MODE . '".';
    }
    if (!is_array($lotto)) {
        $errors[] = 'Falta el bloque "lotto".';
        return ['ok' => false, 'errors' => $errors, 'warnings' => $warnings];
    }
    if (empty($lotto['version'])) {
        $errors[] = 'Falta lotto.version.';
    }
    if (_lotto_strlen($lotto['title'] ?? '') === 0) {
        $errors[] = 'Falta lotto.title.';
    }

    // ── Settings ──
    $settings = is_array($lotto['settings'] ?? null) ? $lotto['settings'] : [];
    if (!is_array($lotto['settings'] ?? null)) {
        $errors[] = 'Falta lotto.settings.';
    }
    $sectionsCount = (int)($settings['sectionsCount'] ?? 0);
    if ($sectionsCount < $limits['sectionsMin'] || $sectionsCount > $limits['sectionsMax']) {
        $errors[] = 'settings.sectionsCount debe ser ' . $limits['sectionsMin'] . ' o ' . $limits['sectionsMax'] . '.';
    }
    if ((int)($settings['studyMinutes'] ?? 0) < $limits['studyMinutesMin']) {
        $errors[] = 'settings.studyMinutes debe ser al menos ' . $limits['studyMinutesMin'] . '.';
    }
    if ((int)($settings['responseMinutes'] ?? 0) < $limits['responseMinutesMin']) {
        $errors[] = 'settings.responseMinutes debe ser al menos ' . $limits['responseMinutesMin'] . '.';
    }
    $qps = (int)($settings['questionsPerStudent'] ?? 3);
    if ($qps < $limits['questionsMin'] || $qps > $limits['questionsMax']) {
        $errors[] = 'settings.questionsPerStudent debe estar entre ' . $limits['questionsMin'] . ' y ' . $limits['questionsMax'] . '.';
    }
    if (isset($settings['drawMode']) && !in_array($settings['drawMode'], triviax_lotto_draw_modes(), true)) {
        $errors[] = 'settings.drawMode inválido: ' . (string)$settings['drawMode'] . '.';
    }

    // ── Rúbrica ──
    $rubric = $lotto['rubric'] ?? null;
    if (!is_array($rubric) || !_lotto_is_list($rubric['criteria'] ?? null) || count($rubric['criteria']) < 1) {
        $errors[] = 'La rúbrica debe tener al menos un criterio en rubric.criteria.';
    } else {
        $seenCrit = [];
        foreach ($rubric['criteria'] as $i => $c) {
            $cid = is_array($c) ? trim((string)($c['id'] ?? '')) : '';
            if ($cid === '' || _lotto_strlen($c['label'] ?? '') === 0 || !is_numeric($c['max'] ?? null) || $c['max'] <= 0) {
                $errors[] = 'El criterio de rúbrica #' . ($i + 1) . ' es inválido (necesita id, label y max > 0).';
                continue;
            }
            if (isset($seenCrit[$cid])) {
                $errors[] = "Hay un criterio de rúbrica duplicado: {$cid}.";
            }
            $seenCrit[$cid] = true;
        }
    }

    // ── Secciones ──
    $sections = $lotto['sections'] ?? null;
    $sectionIds = [];
    if (!_lotto_is_list($sections)) {
        $errors[] = 'lotto.sections debe ser un arreglo.';
        $sections = [];
    }
    if (count($sections) < $limits['sectionsMin'] || count($sections) > $limits['sectionsMax']) {
        $errors[] = 'La actividad debe tener ' . $limits['sectionsMin'] . ' o ' . $limits['sectionsMax'] . ' secciones (tiene ' . count($sections) . ').';
    }
    foreach ($sections as $i => $sec) {
        $ref = (is_array($sec) && !empty($sec['id'])) ? ('La sección ' . $sec['id']) : ('La sección #' . ($i + 1));
        if (!is_array($sec)) {
            $errors[] = "{$ref} no es un objeto válido.";
            continue;
        }
        $sid = trim((string)($sec['id'] ?? ''));
        if ($sid === '') {
            $errors[] = 'La sección #' . ($i + 1) . ' no tiene id.';
        } elseif (isset($sectionIds[$sid])) {
            $errors[] = "Hay un id de sección duplicado: {$sid}.";
        } else {
            $sectionIds[$sid] = true;
        }
        if (_lotto_strlen($sec['title'] ?? '') === 0) {
            $errors[] = "{$ref} no tiene título.";
        }
        if (_lotto_strlen($sec['summaryText'] ?? '') === 0) {
            $errors[] = "{$ref} no tiene texto resumen.";
        }
        if (isset($sec['difficulty']) && !in_array($sec['difficulty'], triviax_lotto_difficulties(), true)) {
            $errors[] = "{$ref} tiene una dificultad inválida: {$sec['difficulty']}.";
        }
    }

    // ── Estudiantes ──
    $students = $lotto['students'] ?? null;
    $studentNumbers = [];
    if (!_lotto_is_list($students)) {
        $errors[] = 'lotto.students debe ser un arreglo.';
        $students = [];
    }
    if (count($students) < $limits['studentsMin']) {
        $errors[] = 'La actividad debe tener al menos ' . $limits['studentsMin'] . ' estudiante.';
    }
    if (count($students) > $limits['studentsMax']) {
        $errors[] = 'La actividad supera el máximo de ' . $limits['studentsMax'] . ' estudiantes (tiene ' . count($students) . ').';
    }
    foreach ($students as $i => $st) {
        if (!is_array($st)) {
            $errors[] = 'El estudiante #' . ($i + 1) . ' no es un objeto válido.';
            continue;
        }
        $num = $st['number'] ?? null;
        if (!is_numeric($num) || (int)$num < 1) {
            $errors[] = 'El estudiante #' . ($i + 1) . ' no tiene un número válido (entero mayor a 0).';
        } elseif (isset($studentNumbers[(int)$num])) {
            $errors[] = 'El estudiante número ' . (int)$num . ' está duplicado.';
        } else {
            $studentNumbers[(int)$num] = true;
        }
        if (_lotto_strlen($st['firstName'] ?? '') === 0) {
            $errors[] = 'El estudiante #' . ($i + 1) . ' no tiene nombre de pila (firstName).';
        }
    }

    // ── Asignaciones ──
    $assignments = $lotto['assignments'] ?? null;
    if (!_lotto_is_list($assignments)) {
        $errors[] = 'lotto.assignments debe ser un arreglo.';
        $assignments = [];
    }
    $assignedNumbers = [];
    foreach ($assignments as $i => $as) {
        if (!is_array($as)) {
            $errors[] = 'La asignación #' . ($i + 1) . ' no es un objeto válido.';
            continue;
        }
        $num = (int)($as['studentNumber'] ?? 0);
        $name = '';
        foreach ($students as $st) {
            if (is_array($st) && (int)($st['number'] ?? 0) === $num) {
                $name = (string)($st['firstName'] ?? '');
                break;
            }
        }
        $ref = $name !== '' ? "La asignación de {$name} (número {$num})" : "La asignación #" . ($i + 1) . " (número {$num})";

        if (!isset($studentNumbers[$num])) {
            $errors[] = "{$ref} referencia un estudiante inexistente.";
        } elseif (isset($assignedNumbers[$num])) {
            $errors[] = "El estudiante número {$num} tiene más de una asignación.";
        } else {
            $assignedNumbers[$num] = true;
        }

        $secId = trim((string)($as['sectionId'] ?? ''));
        if ($secId === '' || !isset($sectionIds[$secId])) {
            $errors[] = "{$ref} referencia una sección inexistente: " . ($secId !== '' ? $secId : 'sin sectionId') . '.';
        }

        $stLen = _lotto_strlen($as['studyText'] ?? '');
        if ($stLen === 0) {
            $errors[] = "{$ref} no tiene texto de estudio (studyText).";
        } elseif ($stLen > $limits['studyTextMax']) {
            $errors[] = "{$ref} supera el máximo de {$limits['studyTextMax']} caracteres de studyText (tiene {$stLen}).";
        }

        $qs = $as['studentQuestions'] ?? null;
        if (!_lotto_is_list($qs) || count($qs) < $limits['questionsMin']) {
            $errors[] = "{$ref} debe tener al menos {$limits['questionsMin']} pregunta guía (studentQuestions).";
        } elseif (count($qs) > $limits['questionsMax']) {
            $errors[] = "{$ref} supera el máximo de {$limits['questionsMax']} preguntas guía.";
        }

        if (_lotto_strlen($as['oralMainQuestion'] ?? '') === 0) {
            $errors[] = "{$ref} no tiene pregunta oral principal (oralMainQuestion).";
        }

        $expected = $as['teacherExpectedAnswers'] ?? null;
        if (!_lotto_is_list($expected) || count($expected) < 1) {
            $errors[] = "{$ref} debe tener al menos una respuesta esperada para el docente (teacherExpectedAnswers).";
        }

        if (isset($as['difficulty']) && !in_array($as['difficulty'], triviax_lotto_difficulties(), true)) {
            $errors[] = "{$ref} tiene una dificultad inválida: {$as['difficulty']}.";
        }
    }

    // Todos los estudiantes deben tener asignación
    foreach ($students as $st) {
        if (!is_array($st)) continue;
        $num = (int)($st['number'] ?? 0);
        if ($num >= 1 && isset($studentNumbers[$num]) && !isset($assignedNumbers[$num])) {
            $name = (string)($st['firstName'] ?? '');
            $errors[] = "El estudiante {$name} (número {$num}) no tiene asignación.";
        }
    }

    return ['ok' => empty($errors), 'errors' => $errors, 'warnings' => $warnings];
}

function triviax_lotto_is_payload_valid($data): bool {
    return triviax_lotto_validate_payload($data)['ok'];
}
