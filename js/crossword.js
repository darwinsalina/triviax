/* ═══════════════════════════════════════════════════════════════════
   TRIVIAX — motor de crucigramas (sin DOM)
   Integrado desde experimental/crucigramas/js/crossword.js

   Construcción de crucigramas a partir de una lista de {word, clue}:
     1. Normaliza palabras (mayúsculas, sin tildes, conserva Ñ).
     2. Coloca la palabra más larga como semilla (horizontal).
     3. En cada ronda, busca entre las palabras restantes la que logra
        el mejor puntaje: MÁS intersecciones válidas con lo ya colocado,
        y a igualdad de cruces, la colocación que MENOS agranda el
        rectángulo ocupado (grilla compacta, no desparramada).
        Reglas de colocación estándar: las intersecciones solo son
        válidas cruzando una palabra de la dirección opuesta; ninguna
        letra nueva puede tocar otra letra por el costado (evita que
        dos palabras se "peguen" sin querer); debe haber una celda
        vacía (borde) antes y después de cada palabra.
     4. Si una palabra no logra ninguna intersección en ninguna ronda
        queda "no ubicada" — es un límite real de construir crucigramas
        con vocabulario arbitrario (no comparte letras con el resto),
        no un error del algoritmo.
     5. Recorta la grilla exactamente al rectángulo ocupado (sin margen:
        el renderizado es "abierto", las celdas vacías son invisibles)
        y numera las casillas con la convención estándar (across/down).

   Expone `window.CrosswordEngine`.
   ═══════════════════════════════════════════════════════════════════ */
'use strict';

(function (global) {

    /* ---------- Normalización (idéntica a wordsearch.js) ---------- */
    const ACCENT_MAP = { 'Á': 'A', 'É': 'E', 'Í': 'I', 'Ó': 'O', 'Ú': 'U', 'Ü': 'U' };
    function normalizeWord(raw) {
        let w = String(raw == null ? '' : raw).toUpperCase().trim();
        w = w.replace(/[ÁÉÍÓÚÜ]/g, (ch) => ACCENT_MAP[ch] || ch);
        w = w.replace(/[^A-ZÑ]/g, '');
        return w;
    }

    const key = (r, c) => r + ',' + c;

    function shuffle(arr) {
        const a = arr.slice();
        for (let i = a.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            [a[i], a[j]] = [a[j], a[i]];
        }
        return a;
    }

    /**
     * ¿Se puede colocar `word` empezando en (r,c) con dirección `dir`
     * ('across' | 'down') sobre el mapa disperso `cells`?
     * Devuelve { overlaps } o null si no es válido.
     */
    function canPlace(cells, word, r, c, dir) {
        let overlaps = 0;
        for (let i = 0; i < word.length; i++) {
            const rr = dir === 'down' ? r + i : r;
            const cc = dir === 'across' ? c + i : c;
            const cell = cells.get(key(rr, cc));
            if (cell) {
                if (cell.letter !== word[i]) return null; // chocaría con otra letra
                if (dir === 'across' && cell.across != null) return null; // ya hay un "across" ahí
                if (dir === 'down' && cell.down != null) return null;     // ya hay un "down" ahí
                overlaps++;
            } else {
                // celda nueva: los costados (perpendiculares) deben estar vacíos,
                // si no, esta palabra "tocaría" a otra sin cruzarla a propósito.
                if (dir === 'across') {
                    if (cells.has(key(rr - 1, cc)) || cells.has(key(rr + 1, cc))) return null;
                } else {
                    if (cells.has(key(rr, cc - 1)) || cells.has(key(rr, cc + 1))) return null;
                }
            }
        }
        // debe haber una celda vacía justo antes y justo después de la palabra
        const beforeR = dir === 'down' ? r - 1 : r, beforeC = dir === 'across' ? c - 1 : c;
        const afterR  = dir === 'down' ? r + word.length : r, afterC = dir === 'across' ? c + word.length : c;
        if (cells.has(key(beforeR, beforeC))) return null;
        if (cells.has(key(afterR, afterC))) return null;
        return { overlaps };
    }

    function commitWord(cells, placed, item, r, c, dir) {
        const id = placed.length;
        for (let i = 0; i < item.norm.length; i++) {
            const rr = dir === 'down' ? r + i : r;
            const cc = dir === 'across' ? c + i : c;
            const k = key(rr, cc);
            const existing = cells.get(k) || { letter: item.norm[i], across: null, down: null };
            existing.letter = item.norm[i];
            existing[dir] = id;
            cells.set(k, existing);
        }
        placed.push({ word: item.word, normalized: item.norm, clue: item.clue || '', row: r, col: c, dir, length: item.norm.length, id });
    }

    /** Rectángulo ocupado actual (bounding box) del mapa disperso. */
    function bboxOf(cells) {
        let minR = Infinity, maxR = -Infinity, minC = Infinity, maxC = -Infinity;
        for (const k of cells.keys()) {
            const [r, c] = k.split(',').map(Number);
            if (r < minR) minR = r; if (r > maxR) maxR = r;
            if (c < minC) minC = c; if (c > maxC) maxC = c;
        }
        return { minR, maxR, minC, maxC };
    }

    /** Cuánto crece el área del rectángulo ocupado si se coloca la palabra ahí. */
    function bboxGrowth(bbox, len, r, c, dir) {
        const endR = dir === 'down' ? r + len - 1 : r;
        const endC = dir === 'across' ? c + len - 1 : c;
        const minR = Math.min(bbox.minR, r), maxR = Math.max(bbox.maxR, endR);
        const minC = Math.min(bbox.minC, c), maxC = Math.max(bbox.maxC, endC);
        return (maxR - minR + 1) * (maxC - minC + 1)
             - (bbox.maxR - bbox.minR + 1) * (bbox.maxC - bbox.minC + 1);
    }

    /**
     * Busca la mejor colocación posible para `norm` sobre lo ya puesto.
     * Puntaje: los cruces mandan (×1000); a igual cantidad de cruces gana
     * la colocación que menos agranda el rectángulo ocupado.
     */
    function findBestPlacement(cells, norm, bbox) {
        let best = null;
        // recorrer todas las celdas existentes que comparten alguna letra con `norm`
        const placedCells = shuffle(Array.from(cells.entries()));
        for (const [k, cellData] of placedCells) {
            const [r, c] = k.split(',').map(Number);
            for (let i = 0; i < norm.length; i++) {
                if (norm[i] !== cellData.letter) continue;
                for (const dir of ['across', 'down']) {
                    // la celda coincidente cae en la posición i de la nueva palabra
                    const rr = dir === 'down' ? r - i : r;
                    const cc = dir === 'across' ? c - i : c;
                    const fit = canPlace(cells, norm, rr, cc, dir);
                    if (!fit) continue;
                    const score = fit.overlaps * 1000 - bboxGrowth(bbox, norm.length, rr, cc, dir);
                    if (!best || score > best.score) {
                        best = { r: rr, c: cc, dir, overlaps: fit.overlaps, score };
                    }
                }
            }
        }
        return best;
    }

    /**
     * @param {{word:string, clue?:string}[]} words
     * @returns {{rows, cols, grid, placed, unplaced, acrossClues, downClues}}
     */
    function buildCrossword(words) {
        const items = (words || [])
            .map((w) => ({ word: String(w.word || w), clue: String(w.clue || ''), norm: normalizeWord(w.word || w) }))
            .filter((it) => it.norm.length >= 2);

        // unicidad por palabra normalizada
        const seen = new Set();
        const unique = [];
        for (const it of items) {
            if (seen.has(it.norm)) continue;
            seen.add(it.norm);
            unique.push(it);
        }
        unique.sort((a, b) => b.norm.length - a.norm.length);

        const cells = new Map();
        const placed = [];
        if (!unique.length) return emptyResult();

        const remaining = unique.slice();
        const seed = remaining.shift();
        commitWord(cells, placed, seed, 0, 0, 'across');

        let progressed = true;
        while (remaining.length && progressed) {
            progressed = false;
            const bbox = bboxOf(cells);
            let bestIdx = -1, bestPlacement = null;
            for (let i = 0; i < remaining.length; i++) {
                const placement = findBestPlacement(cells, remaining[i].norm, bbox);
                if (placement && (!bestPlacement || placement.score > bestPlacement.score)) {
                    bestPlacement = placement; bestIdx = i;
                }
            }
            if (bestIdx >= 0) {
                const item = remaining.splice(bestIdx, 1)[0];
                commitWord(cells, placed, item, bestPlacement.r, bestPlacement.c, bestPlacement.dir);
                progressed = true;
            }
        }
        const unplaced = remaining.map((w) => w.word);
        return finalizeGrid(cells, placed, unplaced);
    }

    function emptyResult() {
        return { rows: 0, cols: 0, grid: [], placed: [], unplaced: [], acrossClues: [], downClues: [] };
    }

    function finalizeGrid(cells, placed, unplaced) {
        if (!placed.length) return { rows: 0, cols: 0, grid: [], placed: [], unplaced, acrossClues: [], downClues: [] };
        let minR = Infinity, maxR = -Infinity, minC = Infinity, maxC = -Infinity;
        for (const k of cells.keys()) {
            const [r, c] = k.split(',').map(Number);
            minR = Math.min(minR, r); maxR = Math.max(maxR, r);
            minC = Math.min(minC, c); maxC = Math.max(maxC, c);
        }
        const offR = -minR, offC = -minC; // recorte exacto, sin margen
        const rows = maxR - minR + 1;
        const cols = maxC - minC + 1;
        const grid = Array.from({ length: rows }, () => new Array(cols).fill(null));
        for (const [k, cellData] of cells.entries()) {
            const [r, c] = k.split(',').map(Number);
            grid[r + offR][c + offC] = cellData.letter;
        }
        placed.forEach((p) => { p.row += offR; p.col += offC; });

        // numeración estándar: una celda blanca recibe número si inicia un
        // across (izquierda negra/borde, derecha blanca) o un down (arriba
        // negro/borde, abajo blanco).
        const isWhite = (r, c) => r >= 0 && r < rows && c >= 0 && c < cols && grid[r][c] !== null;
        let n = 1;
        const numberAt = new Map(); // "r,c" -> number
        for (let r = 0; r < rows; r++) {
            for (let c = 0; c < cols; c++) {
                if (!isWhite(r, c)) continue;
                const startsAcross = !isWhite(r, c - 1) && isWhite(r, c + 1);
                const startsDown = !isWhite(r - 1, c) && isWhite(r + 1, c);
                if (startsAcross || startsDown) {
                    numberAt.set(key(r, c), n);
                    n++;
                }
            }
        }
        placed.forEach((p) => { p.number = numberAt.get(key(p.row, p.col)); });
        placed.sort((a, b) => (a.number - b.number) || (a.dir === 'across' ? -1 : 1));

        const acrossClues = placed.filter((p) => p.dir === 'across').map((p) => ({ number: p.number, word: p.word, clue: p.clue, length: p.length }));
        const downClues = placed.filter((p) => p.dir === 'down').map((p) => ({ number: p.number, word: p.word, clue: p.clue, length: p.length }));
        acrossClues.sort((a, b) => a.number - b.number);
        downClues.sort((a, b) => a.number - b.number);

        return { rows, cols, grid, placed, unplaced, acrossClues, downClues };
    }

    global.CrosswordEngine = {
        normalizeWord,
        buildCrossword,
    };

})(typeof window !== 'undefined' ? window : globalThis);
