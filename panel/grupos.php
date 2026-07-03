<?php
/**
 * TRIVIAX v7.0 — Panel docente "Mis grupos".
 * Crear/editar grupos, código de inscripción, importar estudiantes
 * desde CSV con previsualización y alias sugeridos, validar identidad
 * y exportar la lista. Usa los endpoints grp_* de api.php.
 */

require_once __DIR__ . '/../php/auth.php';
triviax_requerir_auth(TRIVIAX_ROL_DOCENTE);

$usuario = triviax_usuario_actual();
$csrf = htmlspecialchars(triviax_csrf_token(), ENT_QUOTES, 'UTF-8');
$nombreUsuario = trim(($usuario['nombre'] ?? '') . ' ' . ($usuario['apellido'] ?? '')) ?: 'Docente';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mis grupos — Panel docente — TRIVIAX</title>
    <link rel="stylesheet" href="<?= TRIVIAX_BASE ?>/css/styles.css?v=6.0.0">
    <link rel="stylesheet" href="<?= TRIVIAX_BASE ?>/css/actividades.css?v=6.3.0">
    <style>
        /* Estilos propios de la pantalla de grupos (tabla y badges) */
        .grp-table { width: 100%; border-collapse: collapse; font-size: 0.92rem; }
        .grp-table th, .grp-table td { padding: 8px 10px; text-align: left; border-bottom: 1px solid rgba(255,255,255,0.12); }
        .grp-table th { font-weight: 600; opacity: 0.75; font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.04em; }
        .grp-table input.grp-alias-input { width: 130px; padding: 4px 8px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.25); background: rgba(255,255,255,0.08); color: inherit; }
        .grp-badge { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 0.78rem; font-weight: 600; }
        .grp-badge.validado { background: rgba(34,197,94,0.22); color: #4ade80; }
        .grp-badge.pendiente { background: rgba(250,204,21,0.2); color: #fde047; }
        .grp-badge.importado { background: rgba(148,163,184,0.22); color: #cbd5e1; }
        .grp-badge.rechazado, .grp-badge.desactivado { background: rgba(239,68,68,0.2); color: #f87171; }
        .grp-badge.dup { background: rgba(239,68,68,0.2); color: #f87171; }
        .grp-row-actions { display: flex; gap: 6px; flex-wrap: wrap; }
        .grp-code-box { display: flex; align-items: center; gap: 10px; padding: 12px 16px; border-radius: 12px;
            background: rgba(99,102,241,0.14); border: 1px dashed rgba(99,102,241,0.5); margin: 10px 0; }
        .grp-code-box code { font-size: 1.4rem; letter-spacing: 0.18em; font-weight: 700; }
        .grp-inline-form { display: flex; gap: 8px; flex-wrap: wrap; align-items: flex-end; margin-top: 10px; }
        .grp-inline-form .act-field { margin: 0; }
        .grp-inline-form input { min-width: 140px; }
        .grp-import-errors { color: #f87171; font-size: 0.85rem; margin-top: 8px; }
    </style>
</head>
<body class="act-app" data-act-csrf="<?php echo $csrf; ?>">
    <header class="act-topbar">
        <a class="act-brand" href="<?= TRIVIAX_BASE ?>/panel/dashboard.php">👥 <span>Mis grupos</span> <em>Panel docente</em></a>
        <nav class="act-topnav">
            <span class="act-user"><?php echo htmlspecialchars($nombreUsuario, ENT_QUOTES, 'UTF-8'); ?></span>
            <a class="act-btn ghost sm" href="<?= TRIVIAX_BASE ?>/panel/dashboard.php">Panel</a>
            <a class="act-btn ghost sm" href="<?= TRIVIAX_BASE ?>/auth/logout.php">Salir</a>
        </nav>
    </header>

    <main class="act-main">

        <!-- ░░░ LISTA DE GRUPOS ░░░ -->
        <section class="act-screen active" data-screen="manage">
            <div class="act-panel wide">
                <div class="act-panel-head">
                    <h2>Tus grupos</h2>
                    <div class="grp-row-actions">
                        <button type="button" class="act-btn ghost sm" id="btn-codigo-docente" title="Genera tu código docente personal (12 caracteres). Se muestra una sola vez.">🔑 Mi código docente</button>
                        <button type="button" class="act-btn primary sm" data-go="create">➕ Nuevo grupo</button>
                    </div>
                </div>
                <p class="act-msg" id="manage-msg" role="status"></p>
                <div class="grp-code-box" id="codigo-docente-box" hidden>
                    <span>Tu código docente:</span> <code id="codigo-docente-valor"></code>
                    <small>Guárdalo ahora: no se volverá a mostrar. Si lo pierdes, regenera uno nuevo.</small>
                </div>
                <div class="act-grid-cards" id="group-list" aria-live="polite"></div>
                <p class="act-empty" id="list-empty" hidden>Todavía no creaste ningún grupo.</p>
            </div>
        </section>

        <!-- ░░░ CREAR / EDITAR GRUPO ░░░ -->
        <section class="act-screen" data-screen="create">
            <div class="act-panel" style="max-width:640px">
                <div class="act-panel-head">
                    <h2 id="create-title">Nuevo grupo</h2>
                    <button type="button" class="act-btn ghost sm" data-go="manage">Cancelar</button>
                </div>
                <p class="act-msg" id="create-msg" role="status"></p>
                <label class="act-field">
                    <span class="act-label">Nivel / curso (opcional)</span>
                    <input type="text" id="f-nivel" maxlength="80" placeholder="Ej: 7mo, 8vo, 9no">
                </label>
                <label class="act-field">
                    <span class="act-label">Nombre del grupo</span>
                    <input type="text" id="f-nombre" maxlength="120" placeholder="Ej: 7mo A">
                </label>
                <label class="act-field">
                    <span class="act-label">Descripción (opcional)</span>
                    <textarea id="f-descripcion" rows="3" placeholder="Notas internas sobre el grupo"></textarea>
                </label>
                <div class="wiz-nav">
                    <span></span>
                    <button type="button" class="act-btn primary" id="btn-save-group">Guardar grupo</button>
                </div>
            </div>
        </section>

        <!-- ░░░ DETALLE DE GRUPO ░░░ -->
        <section class="act-screen" data-screen="detail">
            <div class="act-panel wide">
                <div class="act-panel-head">
                    <h2 id="detail-title">Grupo</h2>
                    <div class="grp-row-actions">
                        <button type="button" class="act-btn ghost sm" data-go="manage">‹ Volver</button>
                        <button type="button" class="act-btn ghost sm" id="btn-edit-group">✏️ Editar</button>
                        <button type="button" class="act-btn ghost sm" id="btn-regen-code" title="Genera un código de inscripción nuevo (6-8 caracteres). Invalida el anterior.">🔑 Código de inscripción</button>
                        <button type="button" class="act-btn ghost sm" id="btn-export">⬇️ Exportar CSV</button>
                        <button type="button" class="act-btn primary sm" data-go="import">📄 Importar estudiantes</button>
                    </div>
                </div>
                <p class="act-msg" id="detail-msg" role="status"></p>
                <div class="grp-code-box" id="group-code-box" hidden>
                    <span>Código de inscripción:</span> <code id="group-code-valor"></code>
                    <small>Compártelo con tus estudiantes. Guárdalo ahora: no se volverá a mostrar.</small>
                </div>

                <div class="grp-inline-form">
                    <label class="act-field"><span class="act-label">Nombre</span><input type="text" id="add-nombre" maxlength="100"></label>
                    <label class="act-field"><span class="act-label">Apellido</span><input type="text" id="add-apellido" maxlength="100"></label>
                    <label class="act-field"><span class="act-label">Email (opcional)</span><input type="email" id="add-email" maxlength="255"></label>
                    <button type="button" class="act-btn ghost sm" id="btn-add-student">➕ Agregar</button>
                </div>

                <div style="overflow-x:auto; margin-top:14px">
                    <table class="grp-table" id="students-table">
                        <thead>
                            <tr><th>Apellido</th><th>Nombre</th><th>Email</th><th>Alias</th><th>Origen</th><th>Estado</th><th>Acciones</th></tr>
                        </thead>
                        <tbody id="students-body"></tbody>
                    </table>
                    <p class="act-empty" id="students-empty" hidden>Este grupo todavía no tiene estudiantes.</p>
                </div>
            </div>
        </section>

        <!-- ░░░ IMPORTAR CSV ░░░ -->
        <section class="act-screen" data-screen="import">
            <div class="act-panel wide">
                <div class="act-panel-head">
                    <h2 id="import-title">Importar estudiantes</h2>
                    <button type="button" class="act-btn ghost sm" id="btn-import-back">‹ Volver al grupo</button>
                </div>
                <p class="act-msg" id="import-msg" role="status"></p>

                <div id="import-step-upload">
                    <div class="act-field">
                        <span class="act-label">Archivo CSV (columnas: nombre, apellido, email opcional)</span>
                        <input type="file" id="f-csv-file" accept=".csv,text/csv">
                        <small class="act-hint">¿Tienes un Excel? Guárdalo como CSV: Archivo → Guardar como → CSV. Separador coma o punto y coma.</small>
                    </div>
                    <label class="act-field">
                        <span class="act-label">…o pega el contenido directamente</span>
                        <textarea id="f-csv-text" rows="8" placeholder="nombre;apellido;email&#10;Juan;Pérez;juan@ejemplo.edu.uy"></textarea>
                    </label>
                    <div class="wiz-nav">
                        <span></span>
                        <button type="button" class="act-btn primary" id="btn-preview">Previsualizar ›</button>
                    </div>
                </div>

                <div id="import-step-preview" hidden>
                    <p class="act-hint">Revisa los alias sugeridos (puedes editarlos) y desmarca las filas que no quieras importar. Las filas duplicadas vienen desmarcadas.</p>
                    <div style="overflow-x:auto">
                        <table class="grp-table" id="preview-table">
                            <thead>
                                <tr><th></th><th>Nombre</th><th>Apellido</th><th>Email</th><th>Alias</th><th>Observación</th></tr>
                            </thead>
                            <tbody id="preview-body"></tbody>
                        </table>
                    </div>
                    <div class="grp-import-errors" id="preview-errors"></div>
                    <div class="wiz-nav">
                        <button type="button" class="act-btn ghost" id="btn-preview-back">‹ Cambiar archivo</button>
                        <button type="button" class="act-btn primary" id="btn-confirm-import">Confirmar importación</button>
                    </div>
                </div>
            </div>
        </section>

    </main>

    <script>
        window.TRIVIAX_CSRF_TOKEN = <?php echo json_encode($csrf, JSON_UNESCAPED_UNICODE); ?>;
        window.TRIVIAX_BASE = '../';
        window.TRIVIAX_API  = '../api.php';
    </script>
    <script src="<?= TRIVIAX_BASE ?>/js/brand.js?v=6.0.0"></script>
    <script src="<?= TRIVIAX_BASE ?>/js/panel/gruposPanel.js?v=7.0.0"></script>
</body>
</html>
