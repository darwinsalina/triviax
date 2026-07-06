<?php
/**
 * TRIVIAX+ — Marketplace educativo (Épica 3, v7.3)
 * Los docentes exploran actividades públicas de otros docentes, las clonan a
 * su catálogo y publican/retiran las propias. Todo vía api.php?action=marketplace_*.
 */

require_once __DIR__ . '/../php/auth.php';
triviax_requerir_auth(TRIVIAX_ROL_DOCENTE);

$usuario   = triviax_usuario_actual();
$csrfToken = triviax_csrf_token();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Marketplace — TRIVIAX</title>
    <link rel="stylesheet" href="<?= TRIVIAX_BASE ?>/css/styles.css?v=7.0.2">
    <style>
        body { overflow: auto; }
        .panel-layout { min-height: 100vh; display: flex; flex-direction: column; }
        .topbar {
            display: flex; align-items: center; justify-content: space-between;
            padding: 14px 32px; background: rgba(9,13,22,0.85);
            border-bottom: 1px solid var(--border-color); backdrop-filter: blur(12px);
            position: sticky; top: 0; z-index: 100;
        }
        .topbar-logo {
            font-size: 1.5rem; font-weight: 800; background: var(--accent-gradient);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
            background-clip: text; letter-spacing: 2px;
        }
        .topbar-nav { display: flex; align-items: center; gap: 12px; }
        .topbar-nav a {
            color: var(--text-muted); text-decoration: none; font-size: 0.85rem;
            padding: 6px 12px; border: 1px solid var(--border-color); border-radius: 8px;
        }
        .topbar-nav a:hover { color: var(--text-primary); border-color: var(--accent); }
        .panel-content { flex: 1; max-width: 1080px; width: 100%; margin: 0 auto; padding: 36px 24px; }
        .panel-title { font-size: 1.6rem; font-weight: 800; color: var(--text-primary); margin-bottom: 6px; }
        .panel-sub { color: var(--text-muted); font-size: 0.92rem; margin-bottom: 24px; }
        .mk-tabs { display: flex; gap: 10px; margin-bottom: 20px; }
        .mk-tab {
            background: rgba(255,255,255,0.04); border: 1px solid var(--border-color);
            border-radius: 10px; padding: 10px 18px; color: var(--text-secondary);
            font-family: var(--font-main); font-size: 0.92rem; font-weight: 600; cursor: pointer;
        }
        .mk-tab.active { border-color: var(--accent); color: var(--text-primary); background: rgba(99,102,241,0.12); }
        .mk-filtros { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 20px; }
        .mk-filtros input, .mk-filtros select {
            background: rgba(255,255,255,0.05); border: 1px solid var(--border-color);
            border-radius: 10px; padding: 10px 14px; color: var(--text-primary);
            font-family: var(--font-main); font-size: 0.92rem; outline: none;
        }
        .mk-filtros input { flex: 1; min-width: 220px; }
        .mk-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 16px; }
        .mk-card {
            background: var(--bg-card); border: 1px solid var(--border-color);
            border-radius: 14px; padding: 20px; display: flex; flex-direction: column; gap: 10px;
        }
        .mk-card-title { font-weight: 700; color: var(--text-primary); font-size: 1.05rem; }
        .mk-card-meta { color: var(--text-muted); font-size: 0.82rem; display: flex; gap: 10px; flex-wrap: wrap; }
        .mk-chip {
            display: inline-block; background: rgba(99,102,241,0.15); color: #a5b4fc;
            padding: 2px 10px; border-radius: 999px; font-size: 0.75rem; font-weight: 600;
        }
        .mk-obs { color: var(--text-secondary); font-size: 0.85rem; line-height: 1.5; flex: 1; }
        .mk-actions { display: flex; gap: 8px; }
        .mk-btn {
            border: none; border-radius: 9px; padding: 9px 14px; cursor: pointer;
            font-family: var(--font-main); font-size: 0.85rem; font-weight: 700;
        }
        .mk-btn-primary { background: var(--accent-gradient); color: #fff; }
        .mk-btn-outline { background: rgba(255,255,255,0.05); color: var(--text-secondary); border: 1px solid var(--border-color); }
        .mk-btn:disabled { opacity: 0.5; cursor: default; }
        .mk-vacio { color: var(--text-muted); padding: 40px 0; text-align: center; grid-column: 1 / -1; }
        .mk-aviso {
            border-radius: 10px; padding: 12px 16px; margin-bottom: 18px; font-size: 0.9rem; display: none;
        }
        .mk-aviso.ok { background: rgba(34,197,94,0.12); border: 1px solid rgba(34,197,94,0.4); color: #86efac; }
        .mk-aviso.error { background: rgba(239,68,68,0.12); border: 1px solid rgba(239,68,68,0.4); color: #fca5a5; }
    </style>
</head>
<body>
<div class="panel-layout">
    <header class="topbar">
        <div class="topbar-logo">TRIVIAX</div>
        <div class="topbar-nav">
            <a href="<?= TRIVIAX_BASE ?>/panel/dashboard.php">← Dashboard</a>
            <a href="<?= TRIVIAX_BASE ?>/auth/logout.php">Cerrar sesión</a>
        </div>
    </header>

    <main class="panel-content">
        <div class="panel-title">🛒 Marketplace educativo</div>
        <p class="panel-sub">Explora actividades publicadas por otros docentes, clónalas a tu catálogo y comparte las tuyas.</p>

        <div id="mk-aviso" class="mk-aviso"></div>

        <div class="mk-tabs">
            <button class="mk-tab active" data-tab="explorar">Explorar</button>
            <button class="mk-tab" data-tab="mias">Mis publicaciones</button>
        </div>

        <div id="tab-explorar">
            <div class="mk-filtros">
                <input type="search" id="mk-q" placeholder="Buscar por tema, título o docente…">
                <select id="mk-nivel"><option value="">Todos los niveles</option></select>
                <select id="mk-orden">
                    <option value="recientes">Más recientes</option>
                    <option value="descargas">Más clonadas</option>
                </select>
            </div>
            <div id="mk-grid" class="mk-grid"><div class="mk-vacio">Cargando actividades…</div></div>
        </div>

        <div id="tab-mias" style="display:none;">
            <p class="panel-sub">Publica tus proyectos de tablero para que otros docentes puedan clonarlos. Tus datos de partidas, reportes y estadísticas nunca se comparten.</p>
            <div id="mk-mias-grid" class="mk-grid"><div class="mk-vacio">Cargando…</div></div>
        </div>
    </main>
</div>

<script>
(function () {
    const API = '<?= TRIVIAX_BASE ?>/api.php';
    const CSRF = <?= json_encode($csrfToken) ?>;
    const aviso = document.getElementById('mk-aviso');

    function avisar(texto, tipo) {
        aviso.textContent = texto;
        aviso.className = 'mk-aviso ' + tipo;
        aviso.style.display = 'block';
        setTimeout(() => { aviso.style.display = 'none'; }, 5000);
    }

    function esc(t) {
        const d = document.createElement('div');
        d.textContent = String(t ?? '');
        return d.innerHTML;
    }

    async function api(action, params, post) {
        const url = new URL(API, window.location.origin);
        url.searchParams.set('action', action);
        Object.entries(params || {}).forEach(([k, v]) => { if (v) url.searchParams.set(k, v); });
        const opts = post
            ? { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF }, body: JSON.stringify(post) }
            : {};
        const res = await fetch(url, opts);
        const data = await res.json().catch(() => ({}));
        if (!res.ok || data.success === false) {
            throw new Error(data.message || data.error || ('HTTP ' + res.status));
        }
        return data;
    }

    // ── Tabs ─────────────────────────────────────────────────────
    document.querySelectorAll('.mk-tab').forEach(tab => {
        tab.addEventListener('click', () => {
            document.querySelectorAll('.mk-tab').forEach(t => t.classList.remove('active'));
            tab.classList.add('active');
            const cual = tab.getAttribute('data-tab');
            document.getElementById('tab-explorar').style.display = cual === 'explorar' ? '' : 'none';
            document.getElementById('tab-mias').style.display = cual === 'mias' ? '' : 'none';
            if (cual === 'mias') cargarMias();
        });
    });

    // ── Explorar ─────────────────────────────────────────────────
    const grid = document.getElementById('mk-grid');

    async function cargarNiveles() {
        try {
            const data = await api('marketplace_levels');
            const sel = document.getElementById('mk-nivel');
            (data.niveles || []).forEach(n => {
                const opt = document.createElement('option');
                opt.value = n;
                opt.textContent = n;
                sel.appendChild(opt);
            });
        } catch (e) { /* filtro opcional */ }
    }

    async function cargarLista() {
        grid.innerHTML = '<div class="mk-vacio">Cargando actividades…</div>';
        try {
            const data = await api('marketplace_list', {
                q: document.getElementById('mk-q').value.trim(),
                nivel: document.getElementById('mk-nivel').value,
                orden: document.getElementById('mk-orden').value
            });
            const items = data.items || [];
            if (!items.length) {
                grid.innerHTML = '<div class="mk-vacio">No hay actividades públicas que coincidan con la búsqueda.<br>¡Sé el primero en publicar la tuya desde «Mis publicaciones»!</div>';
                return;
            }
            grid.innerHTML = '';
            items.forEach(item => {
                const docente = [item.docente_nombre, item.docente_apellido].filter(Boolean).join(' ') || item.author || 'Docente TRIVIAX';
                const card = document.createElement('div');
                card.className = 'mk-card';
                card.innerHTML = `
                    <div><span class="mk-chip">${esc(item.nivel || 'Sin nivel')}</span></div>
                    <div class="mk-card-title">${esc(item.title)}</div>
                    <div class="mk-card-meta">
                        <span>👤 ${esc(docente)}</span>
                        <span>❓ ${item.num_desafios} desafíos</span>
                        <span>⬇️ ${item.descargas_count} clones</span>
                    </div>
                    <div class="mk-obs">${esc((item.obs || '').slice(0, 180))}</div>
                    <div class="mk-actions"></div>`;
                const acciones = card.querySelector('.mk-actions');
                const btn = document.createElement('button');
                btn.className = 'mk-btn mk-btn-primary';
                if (item.es_mio) {
                    btn.textContent = 'Es tuya';
                    btn.disabled = true;
                } else {
                    btn.textContent = '📥 Clonar a mi catálogo';
                    btn.addEventListener('click', async () => {
                        btn.disabled = true;
                        btn.textContent = 'Clonando…';
                        try {
                            const res = await api('marketplace_clone', null, { project_id: item.id });
                            avisar(`Actividad clonada como «${res.nuevo_id}». Ya está en tu catálogo y en el Panel de Actividades.`, 'ok');
                            btn.textContent = '✅ Clonada';
                        } catch (e) {
                            avisar('No se pudo clonar: ' + e.message, 'error');
                            btn.disabled = false;
                            btn.textContent = '📥 Clonar a mi catálogo';
                        }
                    });
                }
                acciones.appendChild(btn);
                grid.appendChild(card);
            });
        } catch (e) {
            grid.innerHTML = `<div class="mk-vacio">⚠️ ${esc(e.message)}</div>`;
        }
    }

    let debounce = null;
    document.getElementById('mk-q').addEventListener('input', () => {
        clearTimeout(debounce);
        debounce = setTimeout(cargarLista, 350);
    });
    document.getElementById('mk-nivel').addEventListener('change', cargarLista);
    document.getElementById('mk-orden').addEventListener('change', cargarLista);

    // ── Mis publicaciones ────────────────────────────────────────
    const miasGrid = document.getElementById('mk-mias-grid');

    async function cargarMias() {
        miasGrid.innerHTML = '<div class="mk-vacio">Cargando…</div>';
        try {
            const data = await api('marketplace_mine');
            const items = data.items || [];
            if (!items.length) {
                miasGrid.innerHTML = '<div class="mk-vacio">Todavía no tienes proyectos propios. Crea uno en el Panel de Actividades.</div>';
                return;
            }
            miasGrid.innerHTML = '';
            items.forEach(item => {
                const card = document.createElement('div');
                card.className = 'mk-card';
                card.innerHTML = `
                    <div><span class="mk-chip">${esc(item.nivel || 'Sin nivel')}</span> ${item.clonado_desde_id ? '<span class="mk-chip">clon</span>' : ''}</div>
                    <div class="mk-card-title">${esc(item.title)}</div>
                    <div class="mk-card-meta">
                        <span>❓ ${item.num_desafios} desafíos</span>
                        <span>⬇️ ${item.descargas_count} clones</span>
                        <span>${item.es_publico ? '🌐 Pública' : '🔒 Privada'}</span>
                    </div>
                    <div class="mk-actions"></div>`;
                const btn = document.createElement('button');
                btn.className = item.es_publico ? 'mk-btn mk-btn-outline' : 'mk-btn mk-btn-primary';
                btn.textContent = item.es_publico ? 'Retirar del marketplace' : '🌐 Publicar en el marketplace';
                btn.addEventListener('click', async () => {
                    btn.disabled = true;
                    try {
                        await api('marketplace_publish', null, { project_id: item.id, publico: !item.es_publico });
                        avisar(item.es_publico ? 'Actividad retirada del marketplace.' : 'Actividad publicada. Otros docentes ya pueden clonarla.', 'ok');
                        cargarMias();
                    } catch (e) {
                        avisar('No se pudo cambiar la publicación: ' + e.message, 'error');
                        btn.disabled = false;
                    }
                });
                card.querySelector('.mk-actions').appendChild(btn);
                miasGrid.appendChild(card);
            });
        } catch (e) {
            miasGrid.innerHTML = `<div class="mk-vacio">⚠️ ${esc(e.message)}</div>`;
        }
    }

    cargarNiveles();
    cargarLista();
})();
</script>
<script src="<?= TRIVIAX_BASE ?>/js/brand.js?v=5.0.6"></script>
</body>
</html>
