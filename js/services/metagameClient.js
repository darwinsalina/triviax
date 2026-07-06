/**
 * TRIVIAX+ MetagameClient (Épica 1)
 * Consume el perfil del metajuego (XP, monedas, rachas, nivel) y muestra
 * recompensas flotantes con microanimaciones CSS puras.
 *
 * Filosofía offline-first: NADA de este módulo es crítico. Si el backend no
 * responde o la migración no está aplicada, el juego sigue igual.
 */
export class MetagameClient {
    static API_URL = 'api.php';
    static _stylesReady = false;

    /**
     * Procesa la respuesta de submit_answer / guardar_intento: si trae bloque
     * `metagame`, muestra el toast de recompensa (y la celebración de nivel).
     * @param {Object} response - respuesta JSON del backend
     * @param {string} [playerName] - nombre a mostrar en la celebración
     */
    static handleAnswerResponse(response, playerName = '') {
        const mg = response && response.metagame;
        if (!mg || typeof mg !== 'object') {
            return;
        }
        try {
            this.showRewardToast(mg);
            if (mg.subio_nivel) {
                this.showLevelUp(mg.nivel, playerName);
            }
        } catch (e) {
            console.warn('[Metagame] No se pudo mostrar la recompensa:', e);
        }
    }

    /**
     * Obtiene el perfil del metajuego del estudiante.
     * @param {Object} [identity] - { sesion_id, jugador_id, player_token } si no hay sesión PHP
     * @returns {Promise<Object|null>} perfil o null si no aplica
     */
    static async fetchProfile(identity = null) {
        try {
            const params = new URLSearchParams({ action: 'metagame_profile' });
            if (identity) {
                Object.entries(identity).forEach(([k, v]) => params.set(k, String(v)));
            }
            const response = await fetch(`${this.API_URL}?${params.toString()}`);
            if (!response.ok) {
                return null;
            }
            const data = await response.json();
            return data.perfil || null;
        } catch (e) {
            return null;
        }
    }

    /** Toast flotante con XP/monedas ganadas y racha vigente. */
    static showRewardToast(mg) {
        this._ensureStyles();
        const xp = Number(mg.xp_ganada) || 0;
        const monedas = Number(mg.monedas_ganadas) || 0;
        if (xp <= 0 && monedas <= 0) {
            return;
        }
        const racha = Number(mg.racha_dias) || 0;
        const mult = Number(mg.multiplicador) || 1;

        const toast = document.createElement('div');
        toast.className = 'metagame-toast';
        toast.setAttribute('role', 'status');
        toast.setAttribute('aria-live', 'polite');

        const parts = [`<span class="metagame-toast-xp">+${xp} XP</span>`];
        if (monedas > 0) {
            parts.push(`<span class="metagame-toast-coins">🪙 +${monedas}</span>`);
        }
        if (racha > 1) {
            parts.push(`<span class="metagame-toast-streak">🔥 ${racha} días · x${mult.toFixed(1)}</span>`);
        }
        toast.innerHTML = parts.join('');
        document.body.appendChild(toast);
        setTimeout(() => toast.classList.add('metagame-toast-out'), 2600);
        setTimeout(() => toast.remove(), 3200);
    }

    /** Celebración de subida de nivel a pantalla completa (breve). */
    static showLevelUp(nivel, playerName = '') {
        this._ensureStyles();
        const overlay = document.createElement('div');
        overlay.className = 'metagame-levelup';
        overlay.setAttribute('role', 'status');
        overlay.setAttribute('aria-live', 'assertive');
        const quien = playerName ? `${this._escape(playerName)} subió` : '¡Subiste';
        overlay.innerHTML = `
            <div class="metagame-levelup-card">
                <div class="metagame-levelup-burst">⭐</div>
                <div class="metagame-levelup-title">${quien} al nivel ${Number(nivel) || 1}!</div>
            </div>`;
        document.body.appendChild(overlay);
        setTimeout(() => overlay.classList.add('metagame-toast-out'), 3200);
        setTimeout(() => overlay.remove(), 3800);
    }

    static _escape(text) {
        const div = document.createElement('div');
        div.textContent = String(text);
        return div.innerHTML;
    }

    /** Inyecta una sola vez las microanimaciones CSS del metajuego. */
    static _ensureStyles() {
        if (this._stylesReady || document.getElementById('metagame-styles')) {
            this._stylesReady = true;
            return;
        }
        const style = document.createElement('style');
        style.id = 'metagame-styles';
        style.textContent = `
            .metagame-toast {
                position: fixed;
                right: 18px;
                bottom: 18px;
                z-index: 10050;
                display: flex;
                gap: 12px;
                align-items: center;
                padding: 12px 18px;
                border-radius: 14px;
                background: linear-gradient(135deg, rgba(99, 102, 241, 0.95), rgba(168, 85, 247, 0.95));
                color: #fff;
                font-weight: 700;
                font-size: 1rem;
                box-shadow: 0 8px 30px rgba(99, 102, 241, 0.45);
                backdrop-filter: blur(6px);
                animation: metagameSlideIn 0.35s cubic-bezier(0.22, 1.2, 0.36, 1);
                pointer-events: none;
            }
            .metagame-toast-xp { font-size: 1.15rem; }
            .metagame-toast-coins { color: #fde68a; }
            .metagame-toast-streak { color: #fecaca; font-size: 0.9rem; }
            .metagame-toast-out { animation: metagameFadeOut 0.5s ease forwards; }
            .metagame-levelup {
                position: fixed;
                inset: 0;
                z-index: 10060;
                display: flex;
                align-items: center;
                justify-content: center;
                background: rgba(15, 15, 35, 0.55);
                animation: metagameFadeIn 0.3s ease;
                pointer-events: none;
            }
            .metagame-levelup-card {
                padding: 34px 48px;
                border-radius: 22px;
                text-align: center;
                color: #fff;
                background: linear-gradient(135deg, #6366f1, #a855f7);
                box-shadow: 0 12px 60px rgba(168, 85, 247, 0.6);
                animation: metagamePop 0.5s cubic-bezier(0.22, 1.4, 0.36, 1);
            }
            .metagame-levelup-burst {
                font-size: 3.4rem;
                animation: metagameSpin 1.1s ease-in-out;
            }
            .metagame-levelup-title {
                margin-top: 10px;
                font-size: 1.5rem;
                font-weight: 800;
            }
            @keyframes metagameSlideIn {
                from { transform: translateY(30px) scale(0.9); opacity: 0; }
                to   { transform: translateY(0) scale(1); opacity: 1; }
            }
            @keyframes metagameFadeOut {
                to { transform: translateY(12px); opacity: 0; }
            }
            @keyframes metagameFadeIn {
                from { opacity: 0; }
                to   { opacity: 1; }
            }
            @keyframes metagamePop {
                from { transform: scale(0.6); opacity: 0; }
                to   { transform: scale(1); opacity: 1; }
            }
            @keyframes metagameSpin {
                0%   { transform: rotate(0deg) scale(0.4); }
                60%  { transform: rotate(360deg) scale(1.25); }
                100% { transform: rotate(360deg) scale(1); }
            }
            @media (prefers-reduced-motion: reduce) {
                .metagame-toast, .metagame-levelup, .metagame-levelup-card,
                .metagame-levelup-burst { animation: none; }
            }
        `;
        document.head.appendChild(style);
        this._stylesReady = true;
    }
}
