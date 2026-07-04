/**
 * TRIVIAX ApiClient
 * Centraliza las llamadas HTTP/API al backend PHP (api.php)
 */
export class ApiClient {
    static API_URL = 'api.php';
    static csrfToken = null;

    /**
     * Comprueba si el servidor local PHP está disponible
     * @returns {Promise<boolean>}
     */
    static async checkConnection() {
        try {
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 2000);
            
            const response = await fetch(`${this.API_URL}?action=list`, { 
                method: 'GET',
                signal: controller.signal 
            });
            clearTimeout(timeoutId);
            return response.ok;
        } catch (e) {
            return false;
        }
    }

    /**
     * Obtiene la lista de proyectos disponibles
     * @returns {Promise<Array<string>>}
     */
    static async listProjects() {
        const response = await fetch(`${this.API_URL}?action=list`);
        if (!response.ok) {
            throw new Error(`Error en el servidor: HTTP ${response.status}`);
        }
        const data = await response.json();
        if (!data.success) {
            throw new Error(data.error || 'Error al listar proyectos');
        }
        if (data.csrf_token) {
            this.csrfToken = data.csrf_token;
        }
        return data.projects || [];
    }

    /**
     * Obtiene el banco de preguntas y metadata de un proyecto
     * @param {string} projectName 
     * @returns {Promise<Object>} { metadata, questions }
     */
    static async getProjectData(projectName) {
        const response = await fetch(`${this.API_URL}?action=get&project=${encodeURIComponent(projectName)}`);
        if (!response.ok) {
            const errData = await response.json().catch(() => ({}));
            throw new Error(errData.error || `Error en el servidor: HTTP ${response.status}`);
        }
        const data = await response.json();
        if (!data.success) {
            throw new Error(data.error || 'Error al cargar los datos del proyecto');
        }
        return {
            metadata: data.metadata,
            questions: data.questions,
            board: data.board || {}
        };
    }

    /**
     * Envía el reporte final de la partida al correo del docente
     * @param {Object} reportPayload 
     * @returns {Promise<Object>} { success, error }
     */
    static async sendReport(reportPayload) {
        const headers = { 'Content-Type': 'application/json' };
        if (this.csrfToken) {
            headers['X-CSRF-Token'] = this.csrfToken;
        }
        const response = await fetch(`${this.API_URL}?action=send_report`, {
            method: 'POST',
            headers: headers,
            body: JSON.stringify(reportPayload)
        });
        if (!response.ok) {
            throw new Error(`Error HTTP ${response.status}`);
        }
        return await response.json();
    }

    /**
     * Registra una estadística de acierto/fallo en tiempo real
     * @param {string} project - Nombre del proyecto
     * @param {number} questionId - ID del desafío
     * @param {string} result - 'correct' | 'incorrect' | 'timeout'
     * @returns {Promise<boolean>}
     */
    static async saveQuestionStat(project, questionId, result, challengeType = 'multiple_choice') {
        try {
            const headers = { 'Content-Type': 'application/json' };
            if (this.csrfToken) {
                headers['X-CSRF-Token'] = this.csrfToken;
            }
            const response = await fetch(`${this.API_URL}?action=save_stat`, {
                method: 'POST',
                headers: headers,
                body: JSON.stringify({ project, questionId, result, challengeType })
            });
            return response.ok;
        } catch (e) {
            console.warn('[ApiClient] No se pudo guardar la estadística en tiempo real:', e);
            return false;
        }
    }

    /**
     * Restablece las estadísticas de un proyecto
     * @param {string} project
     * @returns {Promise<boolean>}
     */
    static async resetStats(project, tkey) {
        const headers = { 'Content-Type': 'application/json' };
        if (this.csrfToken) {
            headers['X-CSRF-Token'] = this.csrfToken;
        }
        const response = await fetch(`${this.API_URL}?action=reset_stats`, {
            method: 'POST',
            headers: headers,
            body: JSON.stringify({ project, tkey })
        });
        return response.ok;
    }

    // ─────────────────────────────────────────────────────────────────
    // MÉTODOS v4.0 — SESIONES CON BASE DE DATOS
    // Todas las llamadas son fire-and-forget: el juego nunca depende
    // de su resultado para funcionar. Los errores se silencian.
    // ─────────────────────────────────────────────────────────────────

    /**
     * Registra los jugadores en una sesión existente por código de acceso.
     * @param {string} codigo - Código de 6 caracteres (ej: "ABC123")
     * @param {string[]} jugadores - Nombres de los jugadores
     * @returns {Promise<{success, sesion_id, jugador_ids, sesion_nombre, proyecto_id}>}
     */
    static async unirseASesion(codigo, jugadores) {
        const headers = { 'Content-Type': 'application/json' };
        if (this.csrfToken) {
            headers['X-CSRF-Token'] = this.csrfToken;
        }
        const response = await fetch(`${this.API_URL}?action=unirse_sesion`, {
            method: 'POST',
            headers: headers,
            body: JSON.stringify({ codigo, jugadores })
        });
        if (!response.ok) {
            const err = await response.json().catch(() => ({}));
            throw new Error(err.error || `Error HTTP ${response.status}`);
        }
        return await response.json();
    }

    /**
     * Guarda un intento de respuesta en la BD (fire-and-forget).
     * Retorna true si se guardó, false si falló (sin lanzar excepción).
     * @param {number} sesionId
     * @param {number} jugadorId
     * @param {Object} datos - { challenge_key, prompt_text, challenge_type, resultado, orden_turno, nombre_jugador }
     * @returns {Promise<boolean>}
     */
    static async guardarIntento(sesionId, jugadorId, datos) {
        try {
            const headers = { 'Content-Type': 'application/json' };
            if (this.csrfToken) {
                headers['X-CSRF-Token'] = this.csrfToken;
            }
            const response = await fetch(`${this.API_URL}?action=guardar_intento`, {
                method: 'POST',
                headers: headers,
                body: JSON.stringify({ sesion_id: sesionId, jugador_id: jugadorId, ...datos })
            });
            return response.ok;
        } catch (e) {
            console.warn('[ApiClient] guardarIntento falló (modo silencioso):', e.message);
            return false;
        }
    }

    /**
     * Guarda los resultados finales de todos los jugadores al terminar la partida (fire-and-forget).
     * @param {number} sesionId
     * @param {Array<{jugador_id, puntaje, correctas, incorrectas, posicion}>} resultados
     * @returns {Promise<boolean>}
     */
    /**
     * Devuelve cualquier player_token almacenado para la sesión dada, leyendo
     * las claves triviax_session_<id>_player_<jid>_token de localStorage.
     * Sirve como prueba de identidad ante el servidor. '' si no hay ninguno.
     * @param {number} sesionId
     * @returns {string}
     */
    static _anyPlayerTokenFor(sesionId) {
        try {
            const prefix = `triviax_session_${sesionId}_player_`;
            for (let i = 0; i < localStorage.length; i++) {
                const key = localStorage.key(i);
                if (key && key.startsWith(prefix) && key.endsWith('_token')) {
                    const token = localStorage.getItem(key);
                    if (token) return token;
                }
            }
        } catch (e) { /* localStorage no disponible */ }
        return '';
    }

    static async guardarResultados(sesionId, resultados) {
        try {
            const headers = { 'Content-Type': 'application/json' };
            if (this.csrfToken) {
                headers['X-CSRF-Token'] = this.csrfToken;
            }
            // Identidad: el servidor exige un token de jugador de esta sesión.
            // Los tokens se guardan en localStorage al unirse (clave
            // triviax_session_<id>_player_<jid>_token); tomamos cualquiera.
            const playerToken = this._anyPlayerTokenFor(sesionId);
            const response = await fetch(`${this.API_URL}?action=guardar_resultados`, {
                method: 'POST',
                headers: headers,
                body: JSON.stringify({ sesion_id: sesionId, resultados, player_token: playerToken })
            });
            return response.ok;
        } catch (e) {
            console.warn('[ApiClient] guardarResultados falló (modo silencioso):', e.message);
            return false;
        }
    }

    static async sessionState(sesionId, jugadorId, playerToken) {
        const params = new URLSearchParams({
            action: 'session_state',
            session_id: String(sesionId),
            jugador_id: String(jugadorId),
            player_token: playerToken
        });
        const response = await fetch(`${this.API_URL}?${params.toString()}`);
        if (!response.ok) {
            const err = await response.json().catch(() => ({}));
            throw new Error(err.error || `Error HTTP ${response.status}`);
        }
        return await response.json();
    }

    static async startTurn(sesionId, jugadorId, playerToken) {
        return this.postSessionAction('start_turn', { sesion_id: sesionId, jugador_id: jugadorId, player_token: playerToken });
    }

    static async rollDice(payload) {
        return this.postSessionAction('roll_dice', payload);
    }

    static async submitAnswer(payload) {
        return this.postSessionAction('submit_answer', payload);
    }

    /**
     * Evaluación autoritativa SIN estado de una respuesta (#1 Etapa 2).
     * Devuelve { correct, gradable, solution } para que el cliente no necesite
     * conocer la respuesta correcta de antemano. Lanza si la petición falla
     * (el llamador decide el fallback al veredicto local).
     * @param {string} project
     * @param {string|number} challengeKey
     * @param {Object} raw - respuesta cruda estructurada del renderer
     */
    static async grade(project, challengeKey, raw) {
        const headers = { 'Content-Type': 'application/json' };
        if (this.csrfToken) {
            headers['X-CSRF-Token'] = this.csrfToken;
        }
        const response = await fetch(`${this.API_URL}?action=grade`, {
            method: 'POST',
            headers,
            body: JSON.stringify({ project, challenge_key: String(challengeKey), raw: raw || {} })
        });
        if (!response.ok) {
            throw new Error(`grade HTTP ${response.status}`);
        }
        return await response.json();
    }

    static async endTurn(sesionId, jugadorId, turnoId, playerToken) {
        return this.postSessionAction('end_turn', { sesion_id: sesionId, jugador_id: jugadorId, turno_id: turnoId, player_token: playerToken });
    }

    static async postSessionAction(action, payload) {
        const headers = { 'Content-Type': 'application/json' };
        if (this.csrfToken) {
            headers['X-CSRF-Token'] = this.csrfToken;
        }
        const response = await fetch(`${this.API_URL}?action=${encodeURIComponent(action)}`, {
            method: 'POST',
            headers: headers,
            body: JSON.stringify(payload)
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok || data.success === false || data.ok === false) {
            throw new Error(data.error || data.message || `Error HTTP ${response.status}`);
        }
        return data;
    }

    // ------------------------------------------------------------------
    // MODALIDAD "TRIVIAX FUTBOL" (football_goal_race)
    // El servidor conserva dado, posiciones, puntajes y pregunta pendiente.
    // ------------------------------------------------------------------

    static async footballBoard() {
        const response = await fetch(`${this.API_URL}?action=football_board`);
        const data = await response.json().catch(() => ({}));
        if (!response.ok || data.success === false || data.ok === false) {
            throw new Error(data.error || data.message || `Error HTTP ${response.status}`);
        }
        return data;
    }

    static async footballDemo() {
        const response = await fetch(`${this.API_URL}?action=football_demo`);
        const data = await response.json().catch(() => ({}));
        if (!response.ok || data.success === false || data.ok === false) {
            throw new Error(data.error || data.message || `Error HTTP ${response.status}`);
        }
        return data;
    }

    static async footballStart(payload) {
        return this.postSessionAction('football_start', Object.assign({ csrf_token: this.csrfToken || '' }, payload || {}));
    }

    static async footballState(sessionId, token) {
        const params = new URLSearchParams({
            action: 'football_state',
            session_id: String(sessionId),
            token: token
        });
        const response = await fetch(`${this.API_URL}?${params.toString()}`);
        const data = await response.json().catch(() => ({}));
        if (!response.ok || data.success === false || data.ok === false) {
            throw new Error(data.error || data.message || `Error HTTP ${response.status}`);
        }
        return data;
    }

    static async footballRoll(sessionId, token) {
        return this.postSessionAction('football_roll', { csrf_token: this.csrfToken || '', session_id: sessionId, token });
    }

    static async footballAnswer(sessionId, token, answer, idempotencyKey) {
        return this.postSessionAction('football_answer', {
            csrf_token: this.csrfToken || '',
            session_id: sessionId,
            token,
            answer,
            idempotency_key: idempotencyKey || `${Date.now()}_${Math.random().toString(36).slice(2)}`
        });
    }

    static async tokenSetPublic(tokenSetId) {
        const params = new URLSearchParams({
            action: 'tokens_public',
            id: String(tokenSetId)
        });
        const response = await fetch(`${this.API_URL}?${params.toString()}`);
        const data = await response.json().catch(() => ({}));
        if (!response.ok || data.success === false || data.ok === false) {
            throw new Error(data.error || data.message || `Error HTTP ${response.status}`);
        }
        return data;
    }

    // ------------------------------------------------------------------
    // MODALIDAD "ESTUDIA Y RESPONDE" (study_answer)
    // El servidor evalua, puntua y decide el estado de dominio.
    // ------------------------------------------------------------------

    static studyHeaders() {
        const headers = { 'Content-Type': 'application/json' };
        if (this.csrfToken) {
            headers['X-CSRF-Token'] = this.csrfToken;
        }
        return headers;
    }

    static async studyRequest(action, payload = null, params = null) {
        const query = new URLSearchParams({ action });
        if (params) {
            Object.entries(params).forEach(([key, value]) => {
                if (value !== undefined && value !== null && value !== '') {
                    query.set(key, String(value));
                }
            });
        }

        const options = payload === null
            ? { method: 'GET' }
            : {
                method: 'POST',
                headers: this.studyHeaders(),
                body: JSON.stringify(payload)
            };

        const response = await fetch(`${this.API_URL}?${query.toString()}`, options);
        const data = await response.json().catch(() => ({}));
        if (!response.ok || data.success === false || data.ok === false) {
            throw new Error(data.error || data.message || `Error HTTP ${response.status}`);
        }
        return data;
    }

    static async studyListPublished() {
        return this.studyRequest('study_list_published');
    }

    static async studyStart({ deckId = null, slug = '' } = {}) {
        return this.studyRequest('study_start', { deck_id: deckId, slug });
    }

    static async studyState(studySessionId, sessionToken) {
        return this.studyRequest('study_state', null, {
            study_session_id: studySessionId,
            session_token: sessionToken
        });
    }

    static async studyStartCard(studySessionId, sessionToken) {
        return this.studyRequest('study_start_card', {
            study_session_id: studySessionId,
            session_token: sessionToken
        });
    }

    static async studySubmitAnswer(payload) {
        return this.studyRequest('study_submit_answer', payload);
    }

    static async studySkipCard(studySessionId, sessionToken, cardId) {
        return this.studyRequest('study_skip_card', {
            study_session_id: studySessionId,
            session_token: sessionToken,
            card_id: cardId
        });
    }

    static async studyFinish(studySessionId, sessionToken) {
        return this.studyRequest('study_finish', {
            study_session_id: studySessionId,
            session_token: sessionToken
        });
    }
}
