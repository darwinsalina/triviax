<?php
/**
 * TRIVIAX v7.0 — Backfill de políticas de acceso.
 *
 * Recorre las tablas de actividades existentes y crea en
 * actividad_acceso_politicas una política por defecto cuando no exista,
 * de modo que todo lo anterior a v7.0 siga comportándose igual:
 *   visibilidad = publica · requiere_login = 0 · evaluativa = 0
 * El estado_publicacion se mapea desde el estado interno de cada tabla
 * (draft → borrador, published → abierta, archived → archivada, etc.).
 *
 * Uso:
 *   php tools/backfill_activity_policies.php          (aplica)
 *   php tools/backfill_activity_policies.php --dry    (solo muestra)
 *
 * Desde web solo lo puede ejecutar un superadmin autenticado.
 */

require_once __DIR__ . '/../php/activity_access.php';

$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
    triviax_requerir_superadmin();
    header('Content-Type: text/plain; charset=utf-8');
}

$dry = $isCli && in_array('--dry', $argv ?? [], true);

/** Mapea el estado interno de cada modalidad al estado de publicación. */
function backfill_map_estado(string $tipo, array $row): string {
    switch ($tipo) {
        case 'proyecto':
            return !empty($row['in_trash']) ? 'archivada' : 'abierta';
        case 'lotto_activity':
            $map = [
                'draft' => 'borrador', 'archived' => 'archivada',
                'cancelled' => 'desactivada', 'finished' => 'cerrada',
            ];
            return $map[$row['status'] ?? ''] ?? 'abierta';
        default: // study_deck, crossword, wordsearch, jigsaw, etiquetar: draft/published/archived
            $map = ['draft' => 'borrador', 'archived' => 'archivada'];
            return $map[$row['estado'] ?? ''] ?? 'abierta';
    }
}

$pdo = triviax_db();

$fuentes = [
    'proyecto'           => 'SELECT id, docente_id, in_trash FROM proyectos',
    'study_deck'         => 'SELECT id, docente_id, estado FROM study_decks',
    'lotto_activity'     => 'SELECT id, docente_id, status FROM lotto_activities',
    'crossword_project'  => 'SELECT id, docente_id, estado FROM crossword_projects',
    'wordsearch_project' => 'SELECT id, docente_id, estado FROM wordsearch_projects',
    'jigsaw_project'     => 'SELECT id, docente_id, estado FROM jigsaw_projects',
    'etiquetar_project'  => 'SELECT id, docente_id, estado FROM etiquetar_projects',
];

$creadas = 0;
$existentes = 0;
$errores = 0;

$check = $pdo->prepare(
    'SELECT id FROM actividad_acceso_politicas WHERE actividad_tipo = ? AND actividad_ref = ? LIMIT 1'
);
$insert = $pdo->prepare(
    "INSERT INTO actividad_acceso_politicas
     (actividad_tipo, actividad_ref, docente_id, visibilidad, estado_publicacion,
      requiere_login, requiere_email_verificado, requiere_validacion_docente, evaluativa)
     VALUES (?, ?, ?, 'publica', ?, 0, 0, 0, 0)"
);

foreach ($fuentes as $tipo => $sql) {
    try {
        $rows = $pdo->query($sql)->fetchAll();
    } catch (Throwable $e) {
        echo "[SKIP] {$tipo}: " . $e->getMessage() . "\n";
        continue;
    }
    foreach ($rows as $row) {
        $ref = triviax_activity_ref($tipo, $row['id']);
        $check->execute([$tipo, $ref]);
        if ($check->fetchColumn()) {
            $existentes++;
            continue;
        }
        $estado = backfill_map_estado($tipo, $row);
        if ($dry) {
            echo "[DRY] {$tipo}:{$ref} → publica/{$estado}\n";
            $creadas++;
            continue;
        }
        try {
            $insert->execute([$tipo, $ref, $row['docente_id'] ?: null, $estado]);
            $creadas++;
            echo "[OK]  {$tipo}:{$ref} → publica/{$estado}\n";
        } catch (Throwable $e) {
            $errores++;
            echo "[ERR] {$tipo}:{$ref}: " . $e->getMessage() . "\n";
        }
    }
}

echo "\nResumen: {$creadas} políticas " . ($dry ? 'por crear' : 'creadas')
   . ", {$existentes} ya existentes, {$errores} errores.\n";

if (!$dry && $creadas > 0) {
    triviax_audit_log('backfill_politicas', 'actividad_acceso_politicas', null, [
        'creadas' => $creadas, 'existentes' => $existentes, 'errores' => $errores,
    ]);
}
exit($errores > 0 ? 1 : 0);
