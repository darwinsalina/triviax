/**
 * Utilidades de ayuda para TRIVIAX
 */

/**
 * Mezcla de forma aleatoria un array (Algoritmo Fisher-Yates) sin modificar el original.
 * @param {Array} array 
 * @returns {Array} nuevo array mezclado
 */
export function shuffle(array) {
    const arr = [...array];
    for (let i = arr.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [arr[i], arr[j]] = [arr[j], arr[i]];
    }
    return arr;
}

/**
 * Escapa caracteres HTML para prevenir XSS al renderizar nombres de usuario
 * @param {string} str 
 * @returns {string}
 */
export function escapeHTML(str) {
    if (!str) return '';
    return str
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

/**
 * Genera una pausa asíncrona para retrasar la ejecución de animaciones.
 * @param {number} ms - Milisegundos
 * @returns {Promise}
 */
export function delay(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

/**
 * Atajo para document.querySelector
 */
export function qs(selector, parent = document) {
    return parent.querySelector(selector);
}

/**
 * Atajo para document.querySelectorAll
 */
export function qsa(selector, parent = document) {
    return parent.querySelectorAll(selector);
}
