/**
 * Configuracion global de TRIVIAX.
 */

// Fuente única de la versión para la interfaz. Debe coincidir con APP_VERSION
// de service-worker.js (un SW clásico no puede importar módulos ES).
// Usar `php tools/bump_version.php X.Y.Z` para actualizar ambos a la vez,
// y `php tools/bump_version.php --check` para verificar consistencia.
export const APP_VERSION = '6.0.0';

// ── Rueda de color RYB — 12 posiciones en sentido horario ───────────────────
// Usada para calcular automáticamente el color del texto del splash en cada versión.
export const RYB_WHEEL = [
    '#E31B23',  //  0 · rojo
    '#F15A24',  //  1 · rojo-naranja
    '#F7941D',  //  2 · naranja
    '#FDB913',  //  3 · amarillo-naranja
    '#FFF200',  //  4 · amarillo
    '#8CC63E',  //  5 · amarillo-verde
    '#00A651',  //  6 · verde
    '#35BDB2',  //  7 · verde-azulado
    '#2B62B9',  //  8 · azul
    '#4B4596',  //  9 · azul-violeta
    '#7030A0',  // 10 · violeta
    '#B13E97',  // 11 · rojo-violeta
];

// Opacidad del texto del splash: 0.4 = 40% opaco / 60% transparente.
export const SPLASH_TEXT_ALPHA = 0.4;

/**
 * Convierte un color hexadecimal a formato rgba con el alpha indicado.
 * @param {string} hex    Color en formato "#RRGGBB"
 * @param {number} alpha  Opacidad 0–1
 * @returns {string}      "rgba(r, g, b, alpha)"
 */
export function hexToRgba(hex, alpha = 1) {
    const r = parseInt(hex.slice(1, 3), 16);
    const g = parseInt(hex.slice(3, 5), 16);
    const b = parseInt(hex.slice(5, 7), 16);
    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
}

/**
 * Devuelve el color rgba del texto del splash para la versión dada.
 * Transparencia controlada por SPLASH_TEXT_ALPHA (0.4 por defecto).
 *
 * Regla:
 *   Se toma el número de versión mayor (major) como índice base en la rueda RYB.
 *   Se avanza +7 posiciones: +6 (complementario exacto) y +1 (paso adicional).
 *   Fórmula: RYB_WHEEL[ (major % 12 + 7) % 12 ]
 *
 * Tabla de referencia rápida:
 *   v1  → azul (8)           → rgba(43,  98, 185, 0.4)
 *   v2  → azul-violeta (9)   → rgba(75,  69, 150, 0.4)
 *   v3  → violeta (10)       → rgba(112, 48, 160, 0.4)
 *   v4  → rojo-violeta (11)  → rgba(177, 62, 151, 0.4)
 *   v5  → rojo (0)           → rgba(227, 27,  35, 0.4)   ← ACTUAL
 *   v6  → rojo-naranja (1)   → rgba(241, 90,  36, 0.4)
 *   v7  → naranja (2)        → rgba(247,148,  29, 0.4)
 *   v8  → amar.-naranja (3)  → rgba(253,185,  19, 0.4)
 *   v9  → amarillo (4)       → rgba(255,242,   0, 0.4)
 *   v10 → amar.-verde (5)    → rgba(140,198,  62, 0.4)
 *   v11 → verde (6)          → rgba(0,  166,  81, 0.4)
 *   v12 → verde-azulado (7)  → rgba(53, 189, 178, 0.4)
 *
 * @param {string} version  Versión en formato "major.minor.patch" (ej: "5.0.0")
 * @param {number} [alpha]  Opacidad opcional; usa SPLASH_TEXT_ALPHA si se omite
 * @returns {string}        Color rgba listo para aplicar con element.style.color
 */
export function getSplashTextColor(version, alpha = SPLASH_TEXT_ALPHA) {
    const major      = parseInt((version ?? '0').split('.')[0], 10) || 0;
    const baseIndex  = major % 12;
    const colorIndex = (baseIndex + 7) % 12;
    return hexToRgba(RYB_WHEEL[colorIndex], alpha);
}

export const DEFAULT_PROJECT = 'informatica101';
export const PROJECTS_BASE_PATH = './proyectos/';
export const BOARD_SIZE = 50;
export const MAX_PLAYERS = 4;
export const MIN_PLAYERS = 2;
export const QUESTION_TIMEOUT_SECONDS = 30;

export const SCORE_TARGET_OPTIONS = [50, 100, 120, 150, 180, 200, 250];

export const BOARD_PROFILES = [
    {
        id: 'oca',
        label: 'Oca tradicional',
        description: 'Recorrido clasico de 50 casillas. Permite jugar por meta, meta exacta o puntos.',
        type: 'serpentine',
        size: 50,
        loop: false,
        defaultVictoryMode: 'race',
        allowedVictoryModes: ['race', 'exact', 'points'],
        lapBonus: 20
    },
    {
        id: 'monopoly',
        label: 'Monopoly educativo',
        description: 'Trayecto rectangular de 40 casillas en bucle horario. Se juega por puntaje.',
        type: 'rectangular-loop',
        size: 40,
        loop: true,
        defaultVictoryMode: 'points',
        allowedVictoryModes: ['points'],
        lapBonus: 20
    },
    {
        id: 'circular',
        label: 'Circular',
        description: 'Recorrido circular de 36 casillas en bucle. Se juega por puntaje.',
        type: 'circular-loop',
        size: 36,
        loop: true,
        defaultVictoryMode: 'points',
        allowedVictoryModes: ['points'],
        lapBonus: 20
    }
];

export const DEFAULT_BOARD_PROFILE_ID = 'oca';

export function getBoardProfile(profileId = DEFAULT_BOARD_PROFILE_ID) {
    return BOARD_PROFILES.find(profile => profile.id === profileId) || BOARD_PROFILES[0];
}

// Sistema de puntuacion
export const POINTS_CORRECT = 10;
export const POINTS_INCORRECT = 5; // Se restaran 5
export const POINTS_TIMEOUT = 5; // Se restaran 5

// Definicion de colores de fichas
export const PLAYER_COLORS = [
    { id: 'red', name: 'Rojo', hex: '#ff4b4b', text: '#ffffff' },
    { id: 'blue', name: 'Azul', hex: '#3b82f6', text: '#ffffff' },
    { id: 'green', name: 'Verde', hex: '#10b981', text: '#ffffff' },
    { id: 'yellow', name: 'Amarillo', hex: '#ffd43b', text: '#1a1a1a' },
    { id: 'purple', name: 'Violeta', hex: '#a855f7', text: '#ffffff' },
    { id: 'orange', name: 'Naranja', hex: '#f97316', text: '#ffffff' },
    { id: 'cyan', name: 'Celeste', hex: '#06b6d4', text: '#06202a' },
    { id: 'pink', name: 'Rosa', hex: '#ec4899', text: '#ffffff' }
];
