/**
 * TRIVIAX+ TtsService (Épica 5 — Accesibilidad Universal / DUA)
 * Envoltorio global de la Web Speech API (window.speechSynthesis) para leer
 * en voz alta enunciados y opciones de los desafíos.
 *
 * Offline-first y sin dependencias: si el navegador no soporta síntesis de
 * voz, todas las funciones degradan en silencio y los botones 🔊 no se
 * muestran. Preferencia persistida en localStorage (triviax_tts_enabled).
 */
export class TtsService {
    static _voice = null;
    static _voicesLoaded = false;

    /** true si el navegador soporta síntesis de voz. */
    static isSupported() {
        return typeof window !== 'undefined'
            && 'speechSynthesis' in window
            && typeof window.SpeechSynthesisUtterance === 'function';
    }

    /** Preferencia del usuario (activada por defecto si hay soporte). */
    static isEnabled() {
        if (!this.isSupported()) {
            return false;
        }
        try {
            return localStorage.getItem('triviax_tts_enabled') !== '0';
        } catch (e) {
            return true;
        }
    }

    static setEnabled(enabled) {
        try {
            localStorage.setItem('triviax_tts_enabled', enabled ? '1' : '0');
        } catch (e) { /* almacenamiento no disponible */ }
        if (!enabled) {
            this.stop();
        }
    }

    /** Voz en español preferida (es-*, prioriza variantes locales). */
    static _pickVoice() {
        if (this._voice || !this.isSupported()) {
            return this._voice;
        }
        const voces = window.speechSynthesis.getVoices() || [];
        this._voice = voces.find(v => /^es[-_]/i.test(v.lang) && v.localService)
            || voces.find(v => /^es[-_]/i.test(v.lang))
            || null;
        return this._voice;
    }

    /**
     * Lee un texto en voz alta (detiene cualquier lectura previa).
     * @param {string} text
     * @param {Object} [opts] - { rate, onend }
     * @returns {boolean} true si la lectura comenzó
     */
    static speak(text, opts = {}) {
        const limpio = String(text || '').replace(/\s+/g, ' ').trim();
        if (!limpio || !this.isSupported() || !this.isEnabled()) {
            return false;
        }
        try {
            this.stop();
            const u = new SpeechSynthesisUtterance(limpio);
            u.lang = 'es-ES';
            u.rate = Number(opts.rate) || 0.95;
            const voz = this._pickVoice();
            if (voz) {
                u.voice = voz;
                u.lang = voz.lang;
            }
            if (typeof opts.onend === 'function') {
                u.onend = opts.onend;
            }
            window.speechSynthesis.speak(u);
            return true;
        } catch (e) {
            return false;
        }
    }

    /** Detiene cualquier lectura en curso. */
    static stop() {
        if (this.isSupported()) {
            try {
                window.speechSynthesis.cancel();
            } catch (e) { /* nada que detener */ }
        }
    }

    /** true si está leyendo ahora mismo. */
    static isSpeaking() {
        return this.isSupported() && window.speechSynthesis.speaking;
    }

    /**
     * Compone el texto hablado de un desafío: enunciado + opciones visibles.
     * @param {string} prompt
     * @param {string[]} options
     */
    static challengeText(prompt, options = []) {
        const partes = [String(prompt || '').trim()];
        const letras = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];
        options.forEach((texto, i) => {
            const t = String(texto || '').trim();
            if (t) {
                partes.push(`Opción ${letras[i] || i + 1}: ${t}.`);
            }
        });
        return partes.join(' ');
    }

    /**
     * Crea el botón flotante 🔊 accesible que lee el texto dado al pulsarlo.
     * @param {() => string} getText - se evalúa al momento del click
     * @returns {HTMLButtonElement|null} null si no hay soporte TTS
     */
    static createSpeakButton(getText) {
        if (!this.isSupported()) {
            return null;
        }
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'tts-speak-btn';
        btn.innerHTML = '🔊';
        btn.title = 'Escuchar el enunciado y las opciones';
        btn.setAttribute('aria-label', 'Escuchar el enunciado y las opciones en voz alta');
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            if (this.isSpeaking()) {
                this.stop();
                btn.classList.remove('tts-speaking');
                return;
            }
            btn.classList.add('tts-speaking');
            this.speak(getText(), { onend: () => btn.classList.remove('tts-speaking') });
        });
        return btn;
    }
}

// Precarga de voces: algunos navegadores las entregan de forma asíncrona.
if (TtsService.isSupported()) {
    try {
        window.speechSynthesis.addEventListener('voiceschanged', () => {
            TtsService._voice = null;
            TtsService._pickVoice();
        });
        TtsService._pickVoice();
    } catch (e) { /* sin precarga */ }
}
