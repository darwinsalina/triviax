<?php
/**
 * TRIVIAX — Pantalla host (pizarra/proyector) de "TRIVIAX Lotto" (lotto_oral).
 * Requiere sesión de docente y código de actividad.
 * Muestra el estado del aula, temporizadores y la ronda de sorteo oral.
 */

require_once __DIR__ . '/php/auth.php';
triviax_requerir_auth(TRIVIAX_ROL_DOCENTE);

$usuario = triviax_usuario_actual();
$csrf = htmlspecialchars(triviax_csrf_token(), ENT_QUOTES, 'UTF-8');
$codigo = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($_GET['code'] ?? '')));

$activity = null;
$errorMsg = null;

if (strlen($codigo) === 6) {
    try {
        $pdo = triviax_db();
        $activity = triviax_lotto_load_activity_by_code($pdo, $codigo);
        if (!$activity) {
            $errorMsg = "La actividad con el código especificado no existe.";
        } else {
            $esDuenio = (int)$activity['docente_id'] === (int)$usuario['id'];
            if (!$esDuenio && $usuario['rol'] !== TRIVIAX_ROL_SUPERADMIN) {
                $errorMsg = "No tienes permisos para ver esta actividad.";
                $activity = null;
            }
        }
    } catch (Exception $e) {
        $errorMsg = "Error al conectar con la base de datos: " . $e->getMessage();
    }
} else {
    $errorMsg = "El código de la actividad es incorrecto o no fue provisto.";
}

// Convertir rúbrica a string seguro para JS si la actividad es válida
$rubricJson = 'null';
if ($activity) {
    $rubricJson = $activity['rubric_json'] ?: 'null';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TRIVIAX Lotto — Pizarra del Salón</title>
    <link rel="stylesheet" href="/triviax/css/styles.css?v=5.0.8">
    <style>
        body { overflow: auto; background: #060913; }

        .lotto-host-page {
            min-height: 100vh;
            width: min(1366px, 100%);
            margin: 0 auto;
            padding: 0 16px 40px;
            display: grid;
            grid-template-rows: auto 1fr;
            gap: 20px;
            box-sizing: border-box;
        }

        /* Top Bar */
        .lotto-host-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 20px;
            background: rgba(9, 13, 22, 0.75);
            border: 1px solid rgba(255,255,255,0.06);
            border-radius: 16px;
            margin-top: 16px;
            backdrop-filter: blur(12px);
        }
        .brand h1 {
            color: var(--text-primary);
            font-size: 1.6rem;
            letter-spacing: 2px;
            margin: 0;
            font-weight: 900;
            background: var(--accent-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .brand p { color: var(--text-secondary); margin: 2px 0 0 0; font-size: 0.82rem; }
        
        .header-meta {
            display: flex;
            align-items: center;
            gap: 24px;
        }
        .header-code {
            text-align: center;
        }
        .header-code label {
            display: block;
            font-size: 0.72rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 2px;
        }
        .header-code span {
            font-family: Consolas, monospace;
            font-size: 2rem;
            font-weight: 900;
            color: #fcd360;
            letter-spacing: 2px;
            text-shadow: 0 0 10px rgba(252,211,96,0.3);
        }

        /* Columns Grid Layout */
        .lotto-host-grid {
            display: grid;
            grid-template-columns: 280px 1fr 280px;
            gap: 20px;
            align-items: start;
        }

        /* General Panel container */
        .lotto-panel {
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        .lotto-panel h2 {
            color: var(--text-primary);
            font-size: 1.05rem;
            margin: 0;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            padding-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        /* Left Column: Student List */
        .student-list-container {
            max-height: 70vh;
            overflow-y: auto;
            padding-right: 4px;
        }
        .student-list-container::-webkit-scrollbar {
            width: 6px;
        }
        .student-list-container::-webkit-scrollbar-track {
            background: transparent;
        }
        .student-list-container::-webkit-scrollbar-thumb {
            background: rgba(255,255,255,0.12);
            border-radius: 99px;
        }
        .student-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 9px 12px;
            background: rgba(255,255,255,0.02);
            border: 1px solid rgba(255,255,255,0.04);
            border-radius: 8px;
            margin-bottom: 6px;
            font-size: 0.85rem;
            transition: all 0.2s;
        }
        .student-item:hover {
            background: rgba(255,255,255,0.04);
        }
        .student-info {
            display: flex;
            align-items: center;
            gap: 10px;
            overflow: hidden;
        }
        .student-num {
            font-family: Consolas, monospace;
            font-weight: 700;
            color: var(--text-muted);
            background: rgba(255,255,255,0.06);
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.78rem;
        }
        .student-name {
            color: var(--text-primary);
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .student-badge {
            font-size: 0.7rem;
            font-weight: 800;
            padding: 2px 8px;
            border-radius: 99px;
            text-transform: uppercase;
        }
        /* Status colors based on effective_status */
        .student-item[data-status="pending"] { opacity: 0.45; }
        .student-item[data-status="pending"] .student-badge { background: rgba(255,255,255,0.06); color: var(--text-muted); }
        
        .student-item[data-status="logged"] .student-badge { background: rgba(52,211,153,0.12); color: #34d399; border: 1px solid rgba(52,211,153,0.2); }
        .student-item[data-status="logged"] { border-color: rgba(52,211,153,0.25); box-shadow: 0 0 8px rgba(52,211,153,0.08); }
        
        .student-item[data-status="studying"] .student-badge { background: rgba(34,211,238,0.12); color: #22d3ee; border: 1px solid rgba(34,211,238,0.2); }
        .student-item[data-status="studying"] { border-color: rgba(34,211,238,0.2); }
        
        .student-item[data-status="ready"] .student-badge { background: rgba(16,185,129,0.16); color: #10b981; border: 1px solid rgba(16,185,129,0.3); }
        .student-item[data-status="ready"] { border-color: rgba(16,185,129,0.35); }
        
        .student-item[data-status="called"] .student-badge { background: rgba(252,211,96,0.18); color: #fbbf24; border: 1px solid rgba(252,211,96,0.45); }
        .student-item[data-status="called"] { border-color: rgba(252,211,96,0.5); box-shadow: 0 0 12px rgba(252,211,96,0.15); }
        
        .student-item[data-status="evaluated"] .student-badge { background: rgba(139,92,246,0.14); color: #a78bfa; border: 1px solid rgba(139,92,246,0.2); }
        .student-item[data-status="evaluated"] { opacity: 0.65; }
        
        .student-item[data-status="postponed"] .student-badge { background: rgba(249,115,22,0.14); color: #fb923c; border: 1px solid rgba(249,115,22,0.22); }
        .student-item[data-status="absent"] .student-badge { background: rgba(148,163,184,0.12); color: #94a3b8; }
        .student-item[data-status="absent"] { opacity: 0.35; }
        
        .student-item[data-status="disconnected"] .student-badge { background: rgba(239,68,68,0.12); color: #f87171; border: 1px solid rgba(239,68,68,0.2); }
        .student-item[data-status="disconnected"] { border-color: rgba(239,68,68,0.2); }

        .btn-release-login {
            background: transparent;
            border: 1px solid rgba(255,255,255,0.08);
            color: var(--text-muted);
            border-radius: 6px;
            padding: 2px 6px;
            font-size: 0.68rem;
            cursor: pointer;
            transition: all 0.15s;
        }
        .btn-release-login:hover {
            color: #f87171;
            border-color: rgba(239,68,68,0.4);
            background: rgba(239,68,68,0.06);
        }

        /* Center Column: Sorteo Area */
        .lotto-sorteo-area {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 32px;
            padding: 30px 10px;
        }

        /* Carousel slots wrapper */
        .lotto-carousel {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 16px;
            width: 100%;
            height: 190px;
        }

        .carousel-card {
            width: 110px;
            height: 140px;
            border-radius: 16px;
            background: rgba(255,255,255,0.02);
            border: 1px solid rgba(255,255,255,0.06);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 12px;
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            opacity: 0.5;
            position: relative;
        }
        .carousel-card .card-number {
            font-family: Consolas, monospace;
            font-size: 2.2rem;
            font-weight: 900;
            color: var(--text-secondary);
        }
        .carousel-card .card-name {
            font-size: 0.74rem;
            color: var(--text-muted);
            font-weight: 700;
            text-align: center;
            padding: 0 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            width: 90%;
        }

        /* Highlight Center Card (Slot 3) */
        .carousel-card.is-current {
            width: 154px;
            height: 184px;
            opacity: 1;
            background: rgba(99, 102, 241, 0.12);
            border: 2px solid rgba(99, 102, 241, 0.75);
            box-shadow: 0 0 35px rgba(99, 102, 241, 0.45), inset 0 0 15px rgba(99, 102, 241, 0.2);
            transform: scale(1.06);
            animation: glowPulse 2s infinite ease-in-out;
        }
        @keyframes glowPulse {
            0%, 100% { box-shadow: 0 0 35px rgba(99, 102, 241, 0.45), inset 0 0 15px rgba(99, 102, 241, 0.2); }
            50% { box-shadow: 0 0 50px rgba(168, 85, 247, 0.6), inset 0 0 22px rgba(168, 85, 247, 0.3); border-color: rgba(168, 85, 247, 0.8); }
        }
        .carousel-card.is-current .card-number {
            font-size: 3.4rem;
            color: #fcd360;
            text-shadow: 0 0 15px rgba(252,211,96,0.4);
        }
        .carousel-card.is-current .card-name {
            font-size: 0.95rem;
            color: var(--text-primary);
            font-weight: 800;
        }
        .carousel-card.is-current::before {
            content: "🎤 EVALUAR";
            position: absolute;
            top: -12px;
            background: linear-gradient(135deg, #6366f1, #a855f7);
            color: white;
            font-size: 0.65rem;
            font-weight: 900;
            padding: 3px 10px;
            border-radius: 99px;
            letter-spacing: 1px;
            box-shadow: 0 4px 10px rgba(99, 102, 241, 0.3);
        }

        /* Controls for oral phase in Sorteo Area */
        .lotto-oral-controls {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            justify-content: center;
        }
        
        /* Large Timer Area */
        .lotto-timer-panel {
            text-align: center;
            padding: 24px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }
        .lotto-timer-display {
            font-family: Consolas, monospace;
            font-size: 4.2rem;
            font-weight: 900;
            color: var(--text-primary);
            letter-spacing: 2px;
            line-height: 1;
        }
        .lotto-timer-display[data-tone="low"] {
            color: #f87171;
            text-shadow: 0 0 20px rgba(239,68,68,0.4);
            animation: lottoPulse 1s infinite;
        }
        .lotto-timer-phase {
            font-size: 0.85rem;
            font-weight: 800;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 1.5px;
        }

        /* Right Column: General Controls */
        .controls-list {
            display: grid;
            gap: 10px;
        }
        .controls-list .btn {
            width: 100%;
            padding: 12px 14px;
            font-size: 0.9rem;
            font-weight: 700;
            justify-content: center;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Evaluation Modal */
        .modal-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(3, 5, 10, 0.82);
            backdrop-filter: blur(8px);
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .modal-container {
            width: min(720px, 100%);
            max-height: 90vh;
            overflow-y: auto;
            border-radius: 20px;
            padding: 26px;
            display: flex;
            flex-direction: column;
            gap: 20px;
            position: relative;
        }
        .modal-container::-webkit-scrollbar {
            width: 6px;
        }
        .modal-container::-webkit-scrollbar-thumb {
            background: rgba(255,255,255,0.12);
            border-radius: 9px;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            padding-bottom: 12px;
        }
        .modal-header h3 {
            margin: 0;
            font-size: 1.25rem;
            color: var(--text-primary);
        }
        .modal-header p {
            margin: 4px 0 0 0;
            font-size: 0.85rem;
            color: var(--text-secondary);
        }
        .modal-close {
            background: transparent;
            border: none;
            color: var(--text-muted);
            font-size: 1.5rem;
            cursor: pointer;
            line-height: 1;
        }
        .modal-close:hover { color: var(--text-primary); }

        .eval-section-info {
            padding: 14px;
            background: rgba(255,255,255,0.02);
            border: 1px solid rgba(255,255,255,0.05);
            border-radius: 12px;
            font-size: 0.88rem;
            line-height: 1.5;
        }
        .eval-section-info strong { color: var(--text-primary); }
        .eval-section-info p { margin: 6px 0 0 0; color: var(--text-secondary); }

        .eval-lists-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        .eval-list-box {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .eval-list-box h4 {
            margin: 0;
            font-size: 0.8rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .eval-list {
            padding-left: 18px;
            margin: 0;
            font-size: 0.84rem;
            color: var(--text-secondary);
            line-height: 1.45;
        }
        .eval-list li { margin-bottom: 4px; }

        /* Rubric Criteria Grid */
        .rubric-grid {
            display: grid;
            gap: 10px;
        }
        .rubric-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 8px 12px;
            background: rgba(255,255,255,0.01);
            border: 1px solid rgba(255,255,255,0.03);
            border-radius: 10px;
        }
        .rubric-label {
            font-size: 0.88rem;
            font-weight: 600;
            color: var(--text-secondary);
        }
        .rubric-options {
            display: flex;
            gap: 6px;
        }
        .rubric-opt {
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 800;
            color: var(--text-muted);
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
            cursor: pointer;
            transition: all 0.15s;
        }
        .rubric-opt:hover {
            color: var(--text-primary);
            border-color: rgba(99,102,241,0.5);
            background: rgba(99,102,241,0.1);
        }
        .rubric-opt.is-selected {
            color: white;
            background: var(--accent-gradient);
            border-color: transparent;
            box-shadow: 0 2px 8px rgba(99,102,241,0.35);
        }

        .eval-score-summary {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: rgba(99,102,241,0.06);
            border: 1px solid rgba(99,102,241,0.25);
            border-radius: 12px;
            padding: 14px 18px;
        }
        .eval-score-label {
            font-weight: 800;
            color: var(--text-primary);
            font-size: 0.95rem;
        }
        .eval-score-value {
            font-family: Consolas, monospace;
            font-size: 1.6rem;
            font-weight: 900;
            color: #fcd360;
        }

        /* Form fields */
        .eval-field {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .eval-field label {
            font-size: 0.8rem;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
        }
        .eval-select, .eval-textarea {
            width: 100%;
            padding: 10px 14px;
            color: var(--text-primary);
            background: rgba(9, 13, 22, 0.8);
            border: 1px solid rgba(255,255,255,0.12);
            border-radius: 10px;
            font-family: inherit;
            font-size: 0.9rem;
            box-sizing: border-box;
        }
        .eval-select:focus, .eval-textarea:focus {
            outline: none;
            border-color: rgba(99,102,241,0.8);
        }
        .eval-select option {
            background: #0f172a;
            color: #f8fafc;
        }
        .eval-textarea {
            min-height: 70px;
            resize: vertical;
            line-height: 1.45;
        }

        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            border-top: 1px solid rgba(255,255,255,0.08);
            padding-top: 16px;
        }

        /* Error Screen */
        .lotto-error-card {
            max-width: 580px;
            margin: 80px auto;
            padding: 30px;
            text-align: center;
        }
        .lotto-error-card h2 { color: #f87171; font-size: 1.4rem; margin-bottom: 12px; }
        .lotto-error-card p { color: var(--text-secondary); line-height: 1.55; margin-bottom: 24px; }

        .lotto-hidden { display: none !important; }

        @media (max-width: 1024px) {
            .lotto-host-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            .student-list-container {
                max-height: 300px;
            }
        }
    </style>
</head>
<body>
<div class="lotto-host-page" 
     data-lotto-host 
     data-csrf="<?php echo $csrf; ?>" 
     data-activity-id="<?php echo $activity ? (int)$activity['id'] : 0; ?>"
     data-rubric="<?php echo htmlspecialchars($rubricJson, ENT_QUOTES, 'UTF-8'); ?>">

    <?php if ($errorMsg): ?>
        <!-- Error Screen -->
        <main class="lotto-error-card glass-card">
            <h2>Error de Acceso</h2>
            <p><?php echo htmlspecialchars($errorMsg, ENT_QUOTES, 'UTF-8'); ?></p>
            <a href="/triviax/panel/dashboard.php" class="btn btn-primary">Volver al Dashboard</a>
        </main>
    <?php else: ?>
        <!-- Header / Topbar -->
        <header class="lotto-host-header">
            <div class="brand">
                <h1 data-activity-title><?php echo htmlspecialchars($activity['titulo'], ENT_QUOTES, 'UTF-8'); ?></h1>
                <p>Dinámica de Sorteo Oral · Grupo: <strong data-activity-group><?php echo htmlspecialchars($activity['grupo'] ?: '—', ENT_QUOTES, 'UTF-8'); ?></strong></p>
            </div>
            <div class="header-meta">
                <div class="header-code">
                    <label>Código de ingreso</label>
                    <span data-activity-code><?php echo htmlspecialchars($activity['codigo'], ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
            </div>
        </header>

        <!-- Main Workspace -->
        <main class="lotto-host-grid">
            
            <!-- Left Column: Student List -->
            <section class="lotto-panel glass-card">
                <h2 id="student-count-title">Estudiantes (0)</h2>
                <div class="student-list-container" data-student-list>
                    <!-- JavaScript will fill this in -->
                </div>
            </section>

            <!-- Center Column: Sorteo Display and Timer -->
            <section class="lotto-panel glass-card" style="align-self: stretch; justify-content: space-between;">
                <h2>Ronda de Sorteo</h2>
                
                <div class="lotto-sorteo-area">
                    <!-- Carousel of 5 Cards -->
                    <div class="lotto-carousel" data-draw-carousel>
                        <!-- 5 slots dynamically updated. Center slot is slot 3 (index 2) -->
                        <div class="carousel-card" data-slot="0">
                            <span class="card-number">—</span>
                            <span class="card-name">Esperando</span>
                        </div>
                        <div class="carousel-card" data-slot="1">
                            <span class="card-number">—</span>
                            <span class="card-name">Esperando</span>
                        </div>
                        <div class="carousel-card is-current" data-slot="2">
                            <span class="card-number">—</span>
                            <span class="card-name">Esperando</span>
                        </div>
                        <div class="carousel-card" data-slot="3">
                            <span class="card-number">—</span>
                            <span class="card-name">Esperando</span>
                        </div>
                        <div class="carousel-card" data-slot="4">
                            <span class="card-number">—</span>
                            <span class="card-name">Esperando</span>
                        </div>
                    </div>

                    <!-- Oral dynamic evaluation controls -->
                    <div class="lotto-oral-controls lotto-hidden" data-oral-actions>
                        <button type="button" class="btn btn-primary" data-btn-evaluate>🎤 Evaluar oral</button>
                        <button type="button" class="btn btn-secondary" data-btn-postpone>⏳ Posponer estudiante</button>
                        <button type="button" class="btn btn-secondary" style="border-color: rgba(239,68,68,0.3);" data-btn-absent>❌ Marcar ausente</button>
                        <button type="button" class="btn btn-secondary" data-btn-skip>⏭️ Omitir / Sortear otro</button>
                    </div>
                </div>

                <!-- Timer Panel -->
                <div class="lotto-timer-panel" style="border-top: 1px solid rgba(255,255,255,0.08); padding-top: 20px;">
                    <div class="lotto-timer-display" data-host-timer>--:--</div>
                    <div class="lotto-timer-phase" data-host-phase>Cargando...</div>
                </div>
            </section>

            <!-- Right Column: Teacher Actions -->
            <section class="lotto-panel glass-card">
                <h2>Acciones Docente</h2>
                <div class="controls-list">
                    <!-- Dynamic state buttons -->
                    <button type="button" class="btn btn-primary lotto-hidden" data-act-open-login>🔑 Abrir ingreso</button>
                    <button type="button" class="btn btn-primary lotto-hidden" data-act-start-study>📖 Iniciar estudio</button>
                    <button type="button" class="btn btn-secondary lotto-hidden" data-act-extend-study>⏱️ Extender +2 min</button>
                    <button type="button" class="btn btn-primary lotto-hidden" data-act-start-response>✍️ Iniciar respuesta</button>
                    <button type="button" class="btn btn-secondary lotto-hidden" data-act-extend-response>⏱️ Extender +1 min</button>
                    <button type="button" class="btn btn-primary lotto-hidden" data-act-start-oral>🎤 Iniciar orales</button>
                    <button type="button" class="btn btn-primary lotto-hidden" data-act-draw-initial>🎲 Iniciar sorteo</button>
                    <button type="button" class="btn btn-secondary lotto-hidden" style="border-color: rgba(239,68,68,0.4); color: #f87171;" data-act-finish>🏁 Finalizar actividad</button>
                    
                    <a href="/triviax/panel/lotto.php" class="btn btn-secondary" style="margin-top: 20px; justify-content: center;">Volver al Listado</a>
                </div>
            </section>

        </main>

        <!-- Evaluation Modal (Starts Hidden) -->
        <div class="modal-overlay lotto-hidden" data-eval-modal>
            <div class="modal-container glass-card">
                <div class="modal-header">
                    <div>
                        <h3 data-modal-title>Evaluar Oral</h3>
                        <p data-modal-subtitle>Estudiante: --</p>
                    </div>
                    <button type="button" class="modal-close" data-modal-close>&times;</button>
                </div>

                <div class="eval-section-info">
                    <strong>Sección asignada:</strong> <span data-modal-sec-title>--</span>
                    <p data-modal-sec-summary>--</p>
                </div>

                <div class="eval-lists-grid">
                    <div class="eval-list-box">
                        <h4>Pregunta oral principal</h4>
                        <p style="font-size: 0.88rem; color: var(--text-primary); margin: 0; font-weight: 600;" data-modal-oral-question>--</p>
                    </div>
                    <div class="eval-list-box">
                        <h4>Respuestas esperadas</h4>
                        <ul class="eval-list" data-modal-expected>
                            <!-- List elements will be filled in -->
                        </ul>
                    </div>
                </div>

                <div class="eval-list-box">
                    <h4>Preguntas alternativas / de apoyo</h4>
                    <ul class="eval-list" data-modal-followups>
                        <!-- List elements will be filled in -->
                    </ul>
                </div>

                <!-- Rubric Form -->
                <div class="rubric-grid" data-modal-rubric-container>
                    <!-- Rubric rows will be generated dynamically by JS based on criteria -->
                </div>

                <div class="eval-score-summary">
                    <span class="eval-score-label">Calificación de la Rúbrica</span>
                    <span class="eval-score-value" data-modal-rubric-total>0 / 12</span>
                </div>

                <div class="eval-field">
                    <label>Resultado Rápido</label>
                    <select class="eval-select" data-modal-quick-res>
                        <option value="sin_evaluar">Seleccionar resultado...</option>
                        <option value="excelente">Excelente</option>
                        <option value="correcto">Correcto</option>
                        <option value="incompleto">Incompleto</option>
                        <option value="no_responde">No responde</option>
                    </select>
                </div>

                <div class="eval-field">
                    <label>Nota numérica <small>(opcional)</small></label>
                    <input type="number" class="eval-select" data-modal-num-score min="0" max="100" step="0.1" placeholder="Ej. 10 o 8.5">
                </div>

                <div class="eval-field">
                    <label>Observaciones y Comentarios</label>
                    <textarea class="eval-textarea" data-modal-comment placeholder="Escribe notas sobre la explicación, fortalezas o aspectos a mejorar..."></textarea>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" data-modal-btn-cancel>Cancelar</button>
                    <button type="button" class="btn btn-primary" data-modal-btn-save>Registrar y Continuar</button>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script src="/triviax/js/brand.js?v=5.0.8"></script>
<?php if ($activity): ?>
    <script type="module" src="/triviax/js/engines/lottoHostEngine.js"></script>
<?php endif; ?>
</body>
</html>
