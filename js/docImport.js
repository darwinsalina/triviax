/* ═══════════════════════════════════════════════════════════════════
   TRIVIAX — Importación de documentos para el flujo "crear con IA".
   Permite que el docente, en lugar de pegar el texto de estudio,
   adjunte un archivo PDF, Markdown (.md) o texto plano (.txt).

   - .txt / .md : se leen directo en el navegador (FileReader).
   - .pdf       : se extrae el texto con pdf.js, servido LOCALMENTE
                  desde js/vendor/pdfjs/ (funciona sin internet).
                  La librería se carga recién cuando hace falta.

   Uso:
     TriviaxDocImport.bind(inputFile, {
         base:     '../',                        // raíz del sitio (para pdf.js)
         onText:   (texto, nombreArchivo) => {}, // texto extraído
         onStatus: (mensaje, tipo) => {},        // 'info' | 'ok' | 'err'
     });

   Expone `window.TriviaxDocImport`.
   ═══════════════════════════════════════════════════════════════════ */
'use strict';

(function (global) {

    const MAX_TEXT_BYTES = 3 * 1024 * 1024;   // 3 MB para .txt/.md
    const MAX_PDF_BYTES  = 25 * 1024 * 1024;  // 25 MB para .pdf
    const PDF_MAX_PAGES  = 120;

    let pdfjsPromise = null;

    /** Carga pdf.js local una sola vez (lazy). */
    function loadPdfJs(base) {
        if (global.pdfjsLib) return Promise.resolve(global.pdfjsLib);
        if (pdfjsPromise) return pdfjsPromise;
        pdfjsPromise = new Promise((resolve, reject) => {
            const s = document.createElement('script');
            s.src = base + 'js/vendor/pdfjs/pdf.min.js';
            s.onload = () => {
                if (!global.pdfjsLib) { reject(new Error('pdf.js no se inicializó.')); return; }
                global.pdfjsLib.GlobalWorkerOptions.workerSrc = base + 'js/vendor/pdfjs/pdf.worker.min.js';
                resolve(global.pdfjsLib);
            };
            s.onerror = () => reject(new Error('No se pudo cargar la librería local de lectura de PDF (js/vendor/pdfjs).'));
            document.head.appendChild(s);
        });
        return pdfjsPromise;
    }

    function readAsText(file) {
        return new Promise((resolve, reject) => {
            const fr = new FileReader();
            fr.onload = () => resolve(String(fr.result || ''));
            fr.onerror = () => reject(new Error('No se pudo leer el archivo.'));
            fr.readAsText(file, 'UTF-8');
        });
    }

    function readAsArrayBuffer(file) {
        return new Promise((resolve, reject) => {
            const fr = new FileReader();
            fr.onload = () => resolve(fr.result);
            fr.onerror = () => reject(new Error('No se pudo leer el archivo.'));
            fr.readAsArrayBuffer(file);
        });
    }

    async function extractPdfText(file, base, onStatus) {
        const pdfjs = await loadPdfJs(base);
        const buf = await readAsArrayBuffer(file);
        const doc = await pdfjs.getDocument({ data: buf }).promise;
        const pages = Math.min(doc.numPages, PDF_MAX_PAGES);
        const chunks = [];
        for (let p = 1; p <= pages; p++) {
            if (onStatus && (p === 1 || p % 10 === 0)) {
                onStatus(`Extrayendo texto del PDF… página ${p} de ${pages}.`, 'info');
            }
            const page = await doc.getPage(p);
            const content = await page.getTextContent();
            // reconstruye líneas: pdf.js entrega fragmentos con hasEOL
            let line = [];
            const lines = [];
            for (const item of content.items) {
                if (item.str) line.push(item.str);
                if (item.hasEOL) { lines.push(line.join(' ')); line = []; }
            }
            if (line.length) lines.push(line.join(' '));
            chunks.push(lines.join('\n'));
        }
        let text = chunks.join('\n\n').replace(/[ \t]+/g, ' ').replace(/\n{3,}/g, '\n\n').trim();
        if (doc.numPages > PDF_MAX_PAGES) {
            text += `\n\n[Nota: el PDF tiene ${doc.numPages} páginas; se extrajeron las primeras ${PDF_MAX_PAGES}.]`;
        }
        return text;
    }

    /**
     * Procesa un File y devuelve el texto extraído.
     * @param {File} file
     * @param {{base?:string, onStatus?:Function}} opts
     */
    async function extract(file, opts = {}) {
        const base = opts.base || '';
        const onStatus = opts.onStatus || null;
        const name = (file.name || '').toLowerCase();
        const isPdf = name.endsWith('.pdf') || file.type === 'application/pdf';
        const isText = name.endsWith('.txt') || name.endsWith('.md') || name.endsWith('.markdown')
            || file.type === 'text/plain' || file.type === 'text/markdown';

        if (!isPdf && !isText) {
            throw new Error('Formato no admitido. Adjuntá un archivo PDF, MD o TXT.');
        }
        if (isPdf && file.size > MAX_PDF_BYTES) {
            throw new Error('El PDF supera el máximo de 25 MB.');
        }
        if (isText && file.size > MAX_TEXT_BYTES) {
            throw new Error('El archivo de texto supera el máximo de 3 MB.');
        }

        if (isText) {
            const text = (await readAsText(file)).trim();
            if (!text) throw new Error('El archivo está vacío.');
            return text;
        }

        if (onStatus) onStatus('Cargando el lector de PDF…', 'info');
        const text = await extractPdfText(file, base, onStatus);
        if (!text) {
            throw new Error('No se encontró texto en el PDF. Si es un documento escaneado (imágenes), pegá el texto manualmente.');
        }
        return text;
    }

    /**
     * Conecta un <input type="file"> al flujo de extracción.
     * @param {HTMLInputElement} inputEl
     * @param {{base?:string, onText:Function, onStatus?:Function}} opts
     */
    function bind(inputEl, opts) {
        inputEl.addEventListener('change', async () => {
            const file = inputEl.files && inputEl.files[0];
            if (!file) return;
            const onStatus = opts.onStatus || (() => {});
            try {
                onStatus(`Leyendo "${file.name}"…`, 'info');
                const text = await extract(file, opts);
                opts.onText(text, file.name);
                const kb = Math.round(text.length / 100) / 10;
                onStatus(`Se extrajo el texto de "${file.name}" (${kb} k caracteres). Revisalo antes de continuar.`, 'ok');
            } catch (err) {
                onStatus(err.message || 'No se pudo leer el archivo.', 'err');
            } finally {
                inputEl.value = ''; // permite volver a elegir el mismo archivo
            }
        });
    }

    global.TriviaxDocImport = { bind, extract };

})(typeof window !== 'undefined' ? window : globalThis);
