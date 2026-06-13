/**
 * Sistema de Sonidos de TRIVIAX (Sintetizador Web Audio API)
 * Funciona 100% Offline, sin archivos de audio externos.
 */

let audioCtx = null;
let soundEnabled = localStorage.getItem('triviax_sound_enabled') !== 'false';

/**
 * Retorna si los sonidos están activos
 * @returns {boolean}
 */
export function isSoundEnabled() {
    return soundEnabled;
}

/**
 * Activa o desactiva los sonidos y los guarda en localStorage
 * @param {boolean} enabled 
 */
export function setSoundEnabled(enabled) {
    soundEnabled = enabled;
    localStorage.setItem('triviax_sound_enabled', enabled ? 'true' : 'false');
}

/**
 * Obtiene o inicializa el contexto de audio del navegador
 */
function getAudioContext() {
    if (!audioCtx) {
        audioCtx = new (window.AudioContext || window.webkitAudioContext)();
    }
    // Si el navegador suspendió el audio (política de seguridad de interacción), lo resumimos
    if (audioCtx.state === 'suspended') {
        audioCtx.resume();
    }
    return audioCtx;
}

/**
 * Reproduce un pitido corto de advertencia para el temporizador (3s o menos)
 */
export function playWarningBeep() {
    if (!soundEnabled) return;
    try {
        const ctx = getAudioContext();
        const now = ctx.currentTime;
        
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        
        osc.type = 'sine';
        osc.frequency.setValueAtTime(880, now); // Nota La5 (alta y clara)
        
        gain.gain.setValueAtTime(0.12, now);
        gain.gain.exponentialRampToValueAtTime(0.01, now + 0.12);
        
        osc.connect(gain);
        gain.connect(ctx.destination);
        
        osc.start();
        osc.stop(now + 0.12);
    } catch (e) {
        console.warn("No se pudo reproducir el sonido de advertencia:", e);
    }
}

/**
 * Reproduce un arpegio ascendente triunfal de éxito
 */
export function playSuccessSound() {
    if (!soundEnabled) return;
    try {
        const ctx = getAudioContext();
        const now = ctx.currentTime;
        
        // Notas alegres ascendentes (Do5, Mi5, Sol5, Do6)
        const notes = [523.25, 659.25, 783.99, 1046.50];
        
        notes.forEach((freq, index) => {
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            
            osc.type = 'triangle'; // Tono suave retro
            osc.frequency.setValueAtTime(freq, now + index * 0.08);
            
            gain.gain.setValueAtTime(0.15, now + index * 0.08);
            gain.gain.exponentialRampToValueAtTime(0.01, now + index * 0.08 + 0.25);
            
            osc.connect(gain);
            gain.connect(ctx.destination);
            
            osc.start(now + index * 0.08);
            osc.stop(now + index * 0.08 + 0.25);
        });
    } catch (e) {
        console.warn("No se pudo reproducir el sonido de acierto:", e);
    }
}

/**
 * Reproduce un tono grave descendente para respuestas incorrectas o timeout
 */
export function playErrorSound() {
    if (!soundEnabled) return;
    try {
        const ctx = getAudioContext();
        const now = ctx.currentTime;
        
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        
        osc.type = 'sawtooth'; // Sonido zumbido retro
        osc.frequency.setValueAtTime(140, now);
        osc.frequency.linearRampToValueAtTime(70, now + 0.4); // Descenso dramático
        
        gain.gain.setValueAtTime(0.15, now);
        gain.gain.exponentialRampToValueAtTime(0.01, now + 0.4);
        
        osc.connect(gain);
        gain.connect(ctx.destination);
        
        osc.start();
        osc.stop(now + 0.4);
    } catch (e) {
        console.warn("No se pudo reproducir el sonido de error:", e);
    }
}

/**
 * Reproduce un sonido de paso sutil (click/tip) para el movimiento de la ficha
 */
export function playStepSound() {
    if (!soundEnabled) return;
    try {
        const ctx = getAudioContext();
        const now = ctx.currentTime;
        
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        
        osc.type = 'sine';
        osc.frequency.setValueAtTime(1200, now); // Frecuencia alta para un "tip/click" limpio
        
        gain.gain.setValueAtTime(0.08, now); // Volumen bajo para que no sea molesto
        gain.gain.exponentialRampToValueAtTime(0.01, now + 0.05); // Muy corto (50ms)
        
        osc.connect(gain);
        gain.connect(ctx.destination);
        
        osc.start();
        osc.stop(now + 0.05);
    } catch (e) {
        console.warn("No se pudo reproducir el sonido del paso:", e);
    }
}

/**
 * Reproduce un sonido simpático y de burbuja cuando se presenta la pregunta
 */
export function playQuestionPopupSound() {
    if (!soundEnabled) return;
    try {
        const ctx = getAudioContext();
        const now = ctx.currentTime;
        
        // Un tono doble alegre (Sol5 seguido rápidamente por Do6)
        const osc1 = ctx.createOscillator();
        const gain1 = ctx.createGain();
        osc1.type = 'sine';
        osc1.frequency.setValueAtTime(783.99, now); // Sol5
        gain1.gain.setValueAtTime(0.06, now);
        gain1.gain.exponentialRampToValueAtTime(0.005, now + 0.15);
        osc1.connect(gain1);
        gain1.connect(ctx.destination);
        osc1.start(now);
        osc1.stop(now + 0.15);

        const osc2 = ctx.createOscillator();
        const gain2 = ctx.createGain();
        osc2.type = 'sine';
        osc2.frequency.setValueAtTime(1046.50, now + 0.08); // Do6
        gain2.gain.setValueAtTime(0.06, now + 0.08);
        gain2.gain.exponentialRampToValueAtTime(0.005, now + 0.23);
        osc2.connect(gain2);
        gain2.connect(ctx.destination);
        osc2.start(now + 0.08);
        osc2.stop(now + 0.23);
    } catch (e) {
        console.warn("No se pudo reproducir el sonido de popup de pregunta:", e);
    }
}

/**
 * Reproduce un sonido corto tipo "blip" cuando el usuario selecciona/hace clic en una opción
 */
export function playOptionSelectSound() {
    if (!soundEnabled) return;
    try {
        const ctx = getAudioContext();
        const now = ctx.currentTime;
        
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        
        osc.type = 'sine';
        osc.frequency.setValueAtTime(600, now);
        osc.frequency.exponentialRampToValueAtTime(300, now + 0.08); // Blip rápido descendente
        
        gain.gain.setValueAtTime(0.04, now);
        gain.gain.exponentialRampToValueAtTime(0.005, now + 0.08);
        
        osc.connect(gain);
        gain.connect(ctx.destination);
        
        osc.start();
        osc.stop(now + 0.08);
    } catch (e) {
        console.warn("No se pudo reproducir el sonido de selección:", e);
    }
}

/**
 * Reproduce una fanfarria moderna de sintetizador bronce (brass synth arpeggiated)
 */
export function playHomepageFanfareSound() {
    if (!soundEnabled) return;
    try {
        const ctx = getAudioContext();
        const now = ctx.currentTime;
        
        // Secuencia de notas arpegiadas de la fanfarria (Do4, Sol4, Do5, Mi5, Sol5)
        const notes = [
            { freq: 261.63, time: 0 },      // Do4
            { freq: 392.00, time: 0.12 },   // Sol4
            { freq: 523.25, time: 0.24 },   // Do5
            { freq: 659.25, time: 0.36 },   // Mi5
            { freq: 783.99, time: 0.48 }    // Sol5
        ];

        notes.forEach(note => {
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            
            // Sierra filtrada para dar timbre tipo sintetizador moderno
            osc.type = 'sawtooth';
            osc.frequency.setValueAtTime(note.freq, now + note.time);
            
            const filter = ctx.createBiquadFilter();
            filter.type = 'lowpass';
            filter.frequency.setValueAtTime(1000, now + note.time);
            filter.Q.setValueAtTime(1, now + note.time);
            
            gain.gain.setValueAtTime(0.0, now + note.time);
            gain.gain.linearRampToValueAtTime(0.06, now + note.time + 0.05); // Suave fade-in para el ataque
            gain.gain.exponentialRampToValueAtTime(0.005, now + note.time + 0.5);
            
            osc.connect(filter);
            filter.connect(gain);
            gain.connect(ctx.destination);
            
            osc.start(now + note.time);
            osc.stop(now + note.time + 0.5);
        });

        // Acorde final de resolución sostenida (Do5, Mi5, Sol5, Do6)
        const chord = [523.25, 659.25, 783.99, 1046.50];
        const chordStartTime = now + 0.55;
        
        chord.forEach((freq, idx) => {
            // Dos osciladores desfasados ligeramente para simular un sonido estéreo/coro grueso
            [freq - 2, freq + 2].forEach(detunedFreq => {
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                const filter = ctx.createBiquadFilter();
                
                osc.type = 'sawtooth';
                osc.frequency.setValueAtTime(detunedFreq, chordStartTime);
                
                filter.type = 'lowpass';
                filter.frequency.setValueAtTime(1200, chordStartTime);
                
                gain.gain.setValueAtTime(0.0, chordStartTime);
                gain.gain.linearRampToValueAtTime(0.04, chordStartTime + 0.1); // Ataque del acorde
                gain.gain.exponentialRampToValueAtTime(0.001, chordStartTime + 1.2); // Sostenido y caída larga
                
                osc.connect(filter);
                filter.connect(gain);
                gain.connect(ctx.destination);
                
                osc.start(chordStartTime);
                osc.stop(chordStartTime + 1.2);
            });
        });
    } catch (e) {
        console.warn("No se pudo reproducir la fanfarria de inicio:", e);
    }
}
