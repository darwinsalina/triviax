/* ═══════════════════════════════════════════════════════════════════
   EXPERIMENTO sopa-letras — motor del algoritmo (autocontenido)

   Responsabilidades de este módulo (sin tocar el DOM):
     1. Normalizar palabras (mayúsculas, sin tildes, conserva Ñ).
     2. Resolver qué direcciones están activas según las casillas de
        opción del docente (horizontal / vertical / diagonal / inversa).
     3. Estimar por SIMULACIÓN cuántas palabras admite una grilla R×C
        con esa combinación de direcciones (algoritmo de capacidad).
     4. Colocar la lista real de palabras en la grilla (algoritmo de
        colocación con preferencia a intersecciones) y rellenar el
        resto de celdas con letras al azar ponderadas para español.

   Expone `window.WordSearchEngine`.
   ═══════════════════════════════════════════════════════════════════ */
'use strict';

(function (global) {

    /* ---------- Normalización ---------- */
    const ACCENT_MAP = { 'Á': 'A', 'É': 'E', 'Í': 'I', 'Ó': 'O', 'Ú': 'U', 'Ü': 'U' };

    /** Mayúsculas, sin tildes (conserva Ñ), solo letras A-Z y Ñ. */
    function normalizeWord(raw) {
        let w = String(raw == null ? '' : raw).toUpperCase().trim();
        w = w.replace(/[ÁÉÍÓÚÜ]/g, (ch) => ACCENT_MAP[ch] || ch);
        w = w.replace(/[^A-ZÑ]/g, ''); // quita espacios, números, signos
        return w;
    }

    /* ---------- Direcciones ---------- */
    // vector [dRow, dCol]
    const DIRS = {
        E:  [0, 1],   W:  [0, -1],
        S:  [1, 0],   N:  [-1, 0],
        SE: [1, 1],   NW: [-1, -1],
        SW: [1, -1],  NE: [-1, 1],
    };

    /**
     * Devuelve la lista de claves de DIRS activas según las opciones
     * del docente. `reverse` aplica a todas las familias habilitadas.
     */
    function activeDirections(opts) {
        opts = opts || {};
        const list = [];
        if (opts.horizontal) { list.push('E'); if (opts.reverse) list.push('W'); }
        if (opts.vertical)   { list.push('S'); if (opts.reverse) list.push('N'); }
        if (opts.diagonal)   { list.push('SE', 'SW'); if (opts.reverse) list.push('NW', 'NE'); }
        return list.length ? list : ['E']; // nunca dejar la grilla sin ninguna dirección
    }

    /* ---------- Grilla ---------- */
    function makeEmptyGrid(rows, cols) {
        const g = new Array(rows);
        for (let r = 0; r < rows; r++) g[r] = new Array(cols).fill(null);
        return g;
    }

    function inBounds(rows, cols, r, c) {
        return r >= 0 && r < rows && c >= 0 && c < cols;
    }

    /** ¿Cabe `word` empezando en (r,c) con dirección [dr,dc]? Devuelve {overlaps} o null. */
    function fitsAt(grid, rows, cols, word, r, c, dr, dc) {
        let overlaps = 0;
        for (let i = 0; i < word.length; i++) {
            const rr = r + dr * i, cc = c + dc * i;
            if (!inBounds(rows, cols, rr, cc)) return null;
            const cell = grid[rr][cc];
            if (cell !== null) {
                if (cell !== word[i]) return null; // chocaría con otra letra distinta
                overlaps++;
            }
        }
        return { overlaps };
    }

    function commit(grid, word, r, c, dr, dc) {
        for (let i = 0; i < word.length; i++) {
            grid[r + dr * i][c + dc * i] = word[i];
        }
    }

    function shuffle(arr) {
        const a = arr.slice();
        for (let i = a.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            [a[i], a[j]] = [a[j], a[i]];
        }
        return a;
    }

    /**
     * Busca la mejor posición para `word` en `grid`: recorre todas las
     * celdas y direcciones activas (orden aleatorio) y se queda con la
     * que más intersecciones genera (más prolijo / compacto). Si no hay
     * ninguna con intersección, usa la primera posición libre válida.
     */
    function bestPlacement(grid, rows, cols, word, directions) {
        let best = null;
        const cellOrder = shuffle(Array.from({ length: rows * cols }, (_, i) => i));
        const dirOrder = shuffle(directions);
        for (const idx of cellOrder) {
            const r = Math.floor(idx / cols), c = idx % cols;
            for (const dKey of dirOrder) {
                const [dr, dc] = DIRS[dKey];
                const fit = fitsAt(grid, rows, cols, word, r, c, dr, dc);
                if (!fit) continue;
                if (!best || fit.overlaps > best.overlaps) {
                    best = { r, c, dr, dc, dir: dKey, overlaps: fit.overlaps };
                    if (best.overlaps >= 2) return best; // suficientemente bueno, no sigas buscando
                }
            }
        }
        return best;
    }

    /* ---------- Relleno de celdas vacías ---------- */
    // Frecuencia aproximada de letras en español (sin tildes), por bolsa ponderada.
    const FILL_POOL = ('EEEEEEEEEEEEAAAAAAAAAAAOOOOOOOOOSSSSSSSRRRRRRRNNNNNNN' +
        'IIIIIIIIIIDDDDDDLLLLLLCCCCCTTTTTUUUUUUMMMPPPGBVYQHFZJÑX').split('');

    function randomFillLetter() {
        return FILL_POOL[Math.floor(Math.random() * FILL_POOL.length)];
    }

    function fillEmptyCells(grid, rows, cols) {
        for (let r = 0; r < rows; r++) {
            for (let c = 0; c < cols; c++) {
                if (grid[r][c] === null) grid[r][c] = randomFillLetter();
            }
        }
    }

    /* ---------- Algoritmo de colocación real ---------- */
    /**
     * @param {string[]} words   palabras ya elegidas por el docente (sin normalizar)
     * @param {number} rows
     * @param {number} cols
     * @param {object} dirOpts   { horizontal, vertical, diagonal, reverse }
     * @returns {{grid, rows, cols, placed:Array, unplaced:Array}}
     */
    function buildPuzzle(words, rows, cols, dirOpts) {
        const directions = activeDirections(dirOpts);
        const grid = makeEmptyGrid(rows, cols);
        const maxLen = Math.max(rows, cols);

        const items = (words || [])
            .map((w, i) => ({ orig: String(w), word: normalizeWord(w), idx: i }))
            .filter((it) => it.word.length >= 2 && it.word.length <= maxLen);

        // más largas primero: son las más difíciles de ubicar
        items.sort((a, b) => b.word.length - a.word.length);

        const placed = [];
        const unplaced = [];
        for (const it of items) {
            const placement = bestPlacement(grid, rows, cols, it.word, directions);
            if (!placement) { unplaced.push(it.orig); continue; }
            commit(grid, it.word, placement.r, placement.c, placement.dr, placement.dc);
            placed.push({
                word: it.orig,
                normalized: it.word,
                row: placement.r,
                col: placement.c,
                dir: placement.dir,
                length: it.word.length,
            });
        }
        // las que no entraron por longitud (no pasaron el filtro)
        for (const w of (words || [])) {
            const norm = normalizeWord(w);
            if (norm.length < 2 || norm.length > maxLen) {
                if (!unplaced.includes(String(w))) unplaced.push(String(w));
            }
        }

        fillEmptyCells(grid, rows, cols);
        // recuperar el orden original para mostrar la lista
        placed.sort((a, b) => words.indexOf(a.word) - words.indexOf(b.word));

        return { grid, rows, cols, directions, placed, unplaced };
    }

    /* ---------- Algoritmo de estimación de capacidad ---------- */
    /**
     * Simula la colocación de un lote sintético de "palabras" (solo se
     * usa su longitud) hasta que la grilla llega a un porcentaje de
     * llenado objetivo, o se acumulan demasiados intentos fallidos
     * seguidos. La cantidad colocada es la estimación de capacidad.
     *
     * Se usa una distribución de longitudes típica de vocabulario
     * escolar en español (3 a 10 letras, con pico en 5-7).
     */
    const TYPICAL_LENGTHS = [3, 4, 4, 5, 5, 5, 6, 6, 6, 6, 7, 7, 7, 8, 8, 9, 10];

    function randomSyntheticWord(len) {
        // letras "aleatorias" para que las coincidencias de relleno no
        // inflen artificialmente las intersecciones de la simulación
        const letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        let s = '';
        for (let i = 0; i < len; i++) s += letters[Math.floor(Math.random() * letters.length)];
        return s;
    }

    function estimateMaxWords(rows, cols, dirOpts) {
        const directions = activeDirections(dirOpts);
        const totalCells = rows * cols;
        const targetFill = 0.72; // por encima de esto la grilla se ve saturada
        const grid = makeEmptyGrid(rows, cols);
        const maxLen = Math.max(rows, cols);

        let placedCount = 0;
        let filledCells = 0;
        let consecutiveFails = 0;
        const maxConsecutiveFails = 35;
        const hardCapAttempts = 400; // cota de seguridad para grillas grandes

        for (let attempt = 0; attempt < hardCapAttempts; attempt++) {
            if (filledCells / totalCells >= targetFill) break;
            if (consecutiveFails >= maxConsecutiveFails) break;

            const len = Math.min(maxLen, TYPICAL_LENGTHS[Math.floor(Math.random() * TYPICAL_LENGTHS.length)]);
            const word = randomSyntheticWord(len);
            const placement = bestPlacement(grid, rows, cols, word, directions);
            if (!placement) { consecutiveFails++; continue; }

            commit(grid, word, placement.r, placement.c, placement.dr, placement.dc);
            placedCount++;
            consecutiveFails = 0;
            filledCells = 0;
            for (let r = 0; r < rows; r++) for (let c = 0; c < cols; c++) if (grid[r][c] !== null) filledCells++;
        }
        return Math.max(1, placedCount);
    }

    global.WordSearchEngine = {
        normalizeWord,
        activeDirections,
        buildPuzzle,
        estimateMaxWords,
        DIRS,
    };

})(typeof window !== 'undefined' ? window : globalThis);
