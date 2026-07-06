/**
 * TRIVIAX+ BotEngine (Épica 2)
 * Máquina de estados finitos que simula "compañeros fantasma": tiran el dado
 * y responden con una tasa de acierto basada en la estadística histórica de
 * la actividad (`stats_desafios` vía action=project_accuracy).
 *
 * Los bots son SOLO locales: no se registran en la sesión de BD ni envían
 * intentos al servidor. Mantienen la tensión competitiva cuando un estudiante
 * juega solo (modo tarea o práctica individual).
 *
 * Estados por bot: idle → rolling → answering → resolved → idle …
 */

const BOT_NAMES = ['Robi 🤖', 'Chispa ⚡', 'Turbo 🚀', 'Luna 🌙', 'Botón 🎈', 'Pixel 👾'];
const DEFAULT_ACCURACY = 0.6;

export class BotEngine {
    /**
     * @param {number} accuracy - probabilidad de acierto [0.05, 0.95]
     */
    constructor(accuracy = DEFAULT_ACCURACY) {
        this.accuracy = Math.min(0.95, Math.max(0.05, Number(accuracy) || DEFAULT_ACCURACY));
        this._states = new Map(); // playerId → estado FSM
    }

    /**
     * Crea el motor consultando la tasa de acierto histórica de la actividad.
     * Si no hay datos suficientes (o falla la red) usa el valor por defecto.
     * @param {string} projectName
     * @returns {Promise<BotEngine>}
     */
    static async forProject(projectName) {
        let accuracy = DEFAULT_ACCURACY;
        try {
            const response = await fetch(`api.php?action=project_accuracy&project=${encodeURIComponent(projectName)}`);
            if (response.ok) {
                const data = await response.json();
                if (typeof data.accuracy === 'number' && data.accuracy > 0) {
                    accuracy = data.accuracy;
                }
            }
        } catch (e) { /* offline-first: valor por defecto */ }
        return new BotEngine(accuracy);
    }

    /**
     * Nombres para `count` bots, sin repetir.
     * @param {number} count
     * @returns {string[]}
     */
    static botNames(count) {
        return BOT_NAMES.slice(0, Math.max(0, Math.min(count, BOT_NAMES.length)));
    }

    /** Estado FSM actual de un bot ('idle' si nunca jugó). */
    stateOf(playerId) {
        return this._states.get(playerId) || 'idle';
    }

    /**
     * Ejecuta el turno completo de un bot como secuencia de estados con
     * tiempos "humanos". Devuelve la jugada decidida; el llamador aplica el
     * movimiento/puntaje y las animaciones.
     *
     * @param {Object} player - jugador bot (usa player.id)
     * @param {Object} [hooks] - callbacks por transición: onRolling(bot),
     *                           onAnswering(bot, diceValue), tras cada delay
     * @returns {Promise<{dice: number, isCorrect: boolean, thinkMs: number}>}
     */
    async playTurn(player, hooks = {}) {
        this._states.set(player.id, 'rolling');
        if (hooks.onRolling) hooks.onRolling(player);
        await this._wait(700 + Math.random() * 600);

        const dice = 1 + Math.floor(Math.random() * 6);
        this._states.set(player.id, 'answering');
        if (hooks.onAnswering) hooks.onAnswering(player, dice);
        // "Piensa" entre 1.2 y 3 segundos, como un compañero real
        const thinkMs = 1200 + Math.random() * 1800;
        await this._wait(thinkMs);

        const isCorrect = Math.random() < this.accuracy;
        this._states.set(player.id, 'resolved');
        return { dice, isCorrect, thinkMs: Math.round(thinkMs) };
    }

    /** Marca el turno del bot como cerrado (vuelve a idle). */
    finishTurn(player) {
        this._states.set(player.id, 'idle');
    }

    _wait(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }
}
