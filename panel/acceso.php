<?php
/**
 * TRIVIAX v7.0 — Panel docente "Acceso y evaluación".
 * Configura la política transversal de cualquier actividad (visibilidad,
 * publicación, plazos, requisitos, dominios, grupos, código de acceso,
 * evaluación y versionado) y consulta/exporta las entregas.
 * Usa los endpoints acceso_* de api.php.
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
    <title>Acceso y evaluación — Panel docente — TRIVIAX</title>
    <link rel="stylesheet" href="<?= TRIVIAX_BASE ?>/css/styles.css?v=6.0.0">
    <link rel="stylesheet" href="<?= TRIVIAX_BASE ?>/css/actividades.css?v=6.3.0">
    <style>
        .acc-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
        @media (max-width: 800px) { .acc-grid { grid-template-columns: 1fr; } }
        .acc-block { border: 1px solid rgba(255,255,255,0.14); border-radius: 14px; padding: 16px; }
        .acc-block h3 { margin: 0 0 12px; font-size: 1rem; opacity: 0.85; }
        .acc-block .act-field { margin-bottom: 10px; }
        .acc-block select, .acc-block input[type="text"], .acc-block input[type="number"],
        .acc-block input[type="datetime-local"], .acc-block textarea {
            width: 100%; padding: 8px 10px; border-radius: 10px;
            border: 1px solid rgba(255,255,255,0.25); background: rgba(255,255,255,0.07); color: inherit;
        }
        .acc-check-list { display: flex; flex-direction: column; gap: 6px; max-height: 180px; overflow-y: auto; }
        .acc-warning { background: rgba(250,204,21,0.14); border: 1px solid rgba(250,204,21,0.4);
            border-radius: 10px; padding: 10px 14px; font-size: 0.88rem; margin: 10px 0; }
        .acc-code-box { display: flex; align-items: center; gap: 10px; padding: 12px 16px; border-radius: 12px;
            background: rgba(99,102,241,0.14); border: 1px dashed rgba(99,102,241,0.5); margin: 10px 0; }
        .acc-code-box code { font-size: 1.3rem; letter-spacing: 0.16em; font-weight: 700; }
        .grp-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
        .grp-table th, .grp-table td { padding: 7px 10px; text-align: left; border-bottom: 1px solid rgba(255,255,255,0.12); }
        .grp-table th { font-weight: 600; opacity: 0.75; font-size: 0.8rem; text-transform: uppercase; }
    </style>
</head>
<body class="act-app" data-act-csrf="<?php echo $csrf; ?>">
    <header class="act-topbar">
        <a class="act-brand" href="<?= TRIVIAX_BASE ?>/panel/dashboard.php">🔐 <span>Acceso y evaluación</span> <em>Panel docente</em></a>
        <nav class="act-topnav">
            <span class="act-user"><?php echo htmlspecialchars($nombreUsuario, ENT_QUOTES, 'UTF-8'); ?></span>
            <a class="act-btn ghost sm" href="<?= TRIVIAX_BASE ?>/panel/grupos.php">👥 Mis grupos</a>
            <a class="act-btn ghost sm" href="<?= TRIVIAX_BASE ?>/panel/dashboard.php">Panel</a>
            <a class="act-btn ghost sm" href="<?= TRIVIAX_BASE ?>/auth/logout.php">Salir</a>
        </nav>
    </header>

    <main class="act-main">
        <section class="act-screen active" data-screen="config">
            <div class="act-panel wide">
                <div class="act-panel-head">
                    <h2>Configuración de acceso de actividad</h2>
                </div>
                <p class="act-msg" id="msg" role="status"></p>

                <div class="grp-inline-form" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:16px">
                    <label class="act-field" style="min-width:220px">
                        <span class="act-label">Modalidad</span>
                        <select id="f-tipo">
                            <option value="proyecto">Tablero principal</option>
                            <option value="study_deck">Estudia y responde</option>
                            <option value="lotto_activity">TRIVIAX Lotto</option>
                            <option value="crossword_project">Crucigrama</option>
                            <option value="wordsearch_project">Sopa de letras</option>
                            <option value="jigsaw_project">Puzle</option>
                            <option value="etiquetar_project">Etiquetar</option>
                        </select>
                    </label>
                    <label class="act-field" style="min-width:300px;flex:1">
                        <span class="act-label">Actividad</span>
                        <select id="f-ref"><option value="">Cargando…</option></select>
                    </label>
                </div>

                <div id="policy-form" hidden>
                    <div class="acc-warning" id="entregas-warning" hidden>
                        ⚠️ Esta actividad ya tiene entregas registradas. Si editas su contenido se creará
                        automáticamente una nueva versión; las entregas anteriores conservan la versión con la que se hicieron.
                    </div>
                    <div class="acc-code-box" id="codigo-box" hidden>
                        <span>Código de acceso:</span> <code id="codigo-valor"></code>
                        <small>Compártelo con quienes deban entrar. No se volverá a mostrar; si se pierde, genera otro.</small>
                    </div>

                    <div class="acc-grid">
                        <div class="acc-block">
                            <h3>📢 Publicación y visibilidad</h3>
                            <label class="act-field"><span class="act-label">Estado</span>
                                <select id="p-estado">
                                    <option value="borrador">Borrador</option>
                                    <option value="programada">Programada</option>
                                    <option value="abierta">Abierta</option>
                                    <option value="cerrada">Cerrada</option>
                                    <option value="desactivada">Desactivada</option>
                                    <option value="archivada">Archivada</option>
                                </select>
                            </label>
                            <label class="act-field"><span class="act-label">Visibilidad</span>
                                <select id="p-visibilidad">
                                    <option value="publica">Pública — aparece en el catálogo</option>
                                    <option value="no_listada">No listada — solo por enlace o código</option>
                                    <option value="restringida">Restringida — exige requisitos</option>
                                </select>
                            </label>
                            <label class="act-field"><span class="act-label">Apertura (opcional)</span>
                                <input type="datetime-local" id="p-abre">
                            </label>
                            <label class="act-field"><span class="act-label">Cierre (opcional)</span>
                                <input type="datetime-local" id="p-cierra">
                            </label>
                        </div>

                        <div class="acc-block">
                            <h3>🔒 Requisitos de acceso</h3>
                            <label class="act-check"><input type="checkbox" id="p-login"> Requiere iniciar sesión</label><br>
                            <label class="act-check"><input type="checkbox" id="p-email"> Requiere email verificado</label><br>
                            <label class="act-check"><input type="checkbox" id="p-validacion"> Requiere validación del docente</label>
                            <label class="act-field" style="margin-top:10px"><span class="act-label">Dominios de email permitidos (uno por línea, vacío = todos)</span>
                                <textarea id="p-dominios" rows="3" placeholder="woodsideschool.edu.uy"></textarea>
                            </label>
                            <div class="act-field"><span class="act-label">Código de acceso</span>
                                <div style="display:flex;gap:8px;align-items:center">
                                    <span id="codigo-estado" style="font-size:0.88rem;opacity:0.8">Sin código</span>
                                    <label class="act-check"><input type="checkbox" id="p-gen-codigo"> Generar nuevo</label>
                                    <label class="act-check"><input type="checkbox" id="p-quitar-codigo"> Quitar</label>
                                </div>
                            </div>
                        </div>

                        <div class="acc-block">
                            <h3>👥 Grupos habilitados</h3>
                            <p class="act-hint">Si marcas grupos, solo sus estudiantes (más los habilitados individualmente) podrán entrar.</p>
                            <div class="acc-check-list" id="p-grupos"></div>
                        </div>

                        <div class="acc-block">
                            <h3>📝 Evaluación</h3>
                            <label class="act-check"><input type="checkbox" id="p-evaluativa"> Actividad evaluativa (congela versión al publicar)</label>
                            <label class="act-field" style="margin-top:10px"><span class="act-label">Máximo de intentos (vacío = sin límite)</span>
                                <input type="number" id="p-intentos" min="1" max="999" placeholder="Sin límite">
                            </label>
                            <label class="act-field"><span class="act-label">Política de feedback</span>
                                <select id="p-feedback">
                                    <option value="inmediato">Inmediato</option>
                                    <option value="al_final">Al final de la actividad</option>
                                    <option value="al_cierre">Al cierre del plazo</option>
                                    <option value="nunca">Nunca</option>
                                </select>
                            </label>
                            <p class="act-hint" id="version-info">Sin versión congelada.</p>
                        </div>
                    </div>

                    <div class="wiz-nav">
                        <button type="button" class="act-btn ghost" id="btn-entregas">📊 Ver entregas</button>
                        <button type="button" class="act-btn primary" id="btn-save">Guardar política</button>
                    </div>

                    <div id="entregas-box" hidden style="margin-top:18px">
                        <div class="act-panel-head">
                            <h2 style="font-size:1.05rem">Entregas</h2>
                            <a class="act-btn ghost sm" id="btn-export-entregas" href="#">⬇️ Exportar CSV</a>
                        </div>
                        <div style="overflow-x:auto">
                            <table class="grp-table">
                                <thead><tr><th>Estudiante</th><th>Alias</th><th>Grupo</th><th>Puntaje</th><th>%</th><th>Estado</th><th>Evaluativa</th><th>Versión</th><th>Entregado</th></tr></thead>
                                <tbody id="entregas-body"></tbody>
                            </table>
                            <p class="act-empty" id="entregas-empty" hidden>Sin entregas registradas.</p>
                        </div>
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
    <script src="<?= TRIVIAX_BASE ?>/js/panel/accesoPanel.js?v=7.0.0"></script>
</body>
</html>
