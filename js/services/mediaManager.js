/**
 * TRIVIAX MediaManager
 * Gestiona la carga, validación, pre-carga offline y renderizado de imágenes, audios y videos.
 * Asegura accesibilidad (transcripciones y texto alternativo).
 */
export class MediaManager {
    static MEDIA_CACHE_NAME = 'triviax-media';

    /**
     * Resuelve la ruta correcta de un recurso multimedia del proyecto
     * @param {string} projectName 
     * @param {string} src - Ruta relativa (ej: "media/foto.png")
     * @returns {string} Ruta absoluta del recurso
     */
    static getAssetUrl(projectName, src) {
        if (!src) return '';
        if (src.startsWith('data:') || src.startsWith('blob:') || src.startsWith('http://') || src.startsWith('https://')) {
            return src;
        }
        // Limpiar barras iniciales
        const cleanSrc = src.startsWith('./') ? src.substring(2) : (src.startsWith('/') ? src.substring(1) : src);
        return `./proyectos/${projectName}/${cleanSrc}`;
    }

    /**
     * Precarga y cachea de forma selectiva un conjunto de assets multimedia para uso offline
     * @param {string} projectName 
     * @param {Array<Object>} assetsList - Lista de assets ({ id, src, type })
     * @param {Function} progressCallback - Callback de progreso
     */
    static async precacheProjectAssets(projectName, assetsList, progressCallback = null) {
        if (!('caches' in window) || !assetsList || assetsList.length === 0) return;

        const cache = await caches.open(this.MEDIA_CACHE_NAME);
        let completed = 0;

        for (const asset of assetsList) {
            if (!asset.src) continue;
            const url = this.getAssetUrl(projectName, asset.src);
            
            try {
                // Descargar y guardar en la cache
                await cache.add(url);
                completed++;
                if (progressCallback) {
                    progressCallback(completed, assetsList.length, asset.id);
                }
            } catch (err) {
                console.warn(`[MediaManager] Error al precachear offline el asset: ${url}`, err);
            }
        }
    }

    /**
     * Valida si un archivo cargado tiene extensiones permitidas y tamaño seguro
     * @param {File} file 
     * @param {string} type - 'image' | 'audio' | 'video' | 'text'
     * @returns {Object} { isValid, error }
     */
    static validateFile(file, type) {
        const limits = {
            image: 2 * 1024 * 1024, // 2MB
            audio: 3 * 1024 * 1024, // 3MB
            video: 10 * 1024 * 1024, // 10MB
            text: 500 * 1024 // 500KB
        };

        const allowedExtensions = {
            image: ['jpg', 'jpeg', 'png', 'webp', 'svg'],
            audio: ['mp3', 'ogg', 'wav'],
            video: ['mp4', 'webm'],
            text: ['txt', 'json']
        };

        const name = file.name.toLowerCase();
        const ext = name.split('.').pop();
        const size = file.size;

        // Validar tamaño
        const maxSize = limits[type] || 2 * 1024 * 1024;
        if (size > maxSize) {
            return {
                isValid: false,
                error: `El archivo supera el tamaño máximo permitido para este tipo (${(maxSize / (1024 * 1024)).toFixed(1)} MB).`
            };
        }

        // Validar extensión
        const allowed = allowedExtensions[type] || [];
        if (!allowed.includes(ext)) {
            return {
                isValid: false,
                error: `Extensión no válida. Las extensiones permitidas son: ${allowed.join(', ')}.`
            };
        }

        return { isValid: true, error: null };
    }

    /**
     * Renderiza un contenedor de imagen accesible
     * @param {string} url 
     * @param {string} altText 
     * @param {string} cssClass 
     * @returns {HTMLImageElement}
     */
    static createImageElement(url, altText = 'Imagen del desafío', cssClass = 'challenge-image') {
        const img = document.createElement('img');
        img.className = cssClass;
        img.alt = altText;
        img.loading = 'lazy'; // Lazy load nativo
        
        // Manejador de error para fallback
        img.onerror = () => {
            img.src = './images/logo.svg';
            img.style.opacity = '0.3';
        };
        img.src = url;
        
        return img;
    }

    /**
     * Crea un elemento de audio accesible con controles y transcripción de apoyo
     * @param {string} url 
     * @param {string} transcriptText - Transcripción obligatoria para accesibilidad
     * @returns {HTMLDivElement} Contenedor con audio y botón de transcripción
     */
    static createAudioElement(url, transcriptText = '') {
        const container = document.createElement('div');
        container.className = 'media-audio-container';
        
        const audio = document.createElement('audio');
        audio.src = url;
        audio.controls = true;
        audio.preload = 'metadata';
        audio.className = 'challenge-audio';
        container.appendChild(audio);

        if (transcriptText) {
            const transcriptBtn = document.createElement('button');
            transcriptBtn.type = 'button';
            transcriptBtn.className = 'btn btn-secondary btn-sm';
            transcriptBtn.style.marginTop = '8px';
            transcriptBtn.innerText = '📖 Mostrar Transcripción';
            
            const transcriptDiv = document.createElement('div');
            transcriptDiv.className = 'audio-transcript-box hidden';
            transcriptDiv.style.marginTop = '8px';
            transcriptDiv.style.padding = '10px';
            transcriptDiv.style.background = 'rgba(0,0,0,0.3)';
            transcriptDiv.style.borderRadius = '8px';
            transcriptDiv.style.fontSize = '0.85rem';
            transcriptDiv.innerText = transcriptText;

            transcriptBtn.onclick = () => {
                const hidden = transcriptDiv.classList.contains('hidden');
                if (hidden) {
                    transcriptDiv.classList.remove('hidden');
                    transcriptBtn.innerText = '📖 Ocultar Transcripción';
                } else {
                    transcriptDiv.classList.add('hidden');
                    transcriptBtn.innerText = '📖 Mostrar Transcripción';
                }
            };

            container.appendChild(transcriptBtn);
            container.appendChild(transcriptDiv);
        }

        return container;
    }

    /**
     * Crea un elemento de video accesible
     * @param {string} url 
     * @param {string} posterUrl 
     * @param {string} transcriptText 
     * @returns {HTMLDivElement}
     */
    static createVideoElement(url, posterUrl = '', transcriptText = '') {
        const container = document.createElement('div');
        container.className = 'media-video-container';

        const video = document.createElement('video');
        video.src = url;
        if (posterUrl) {
            video.poster = posterUrl;
        }
        video.controls = true;
        video.preload = 'none'; // Evitar descarga automática de video pesado
        video.className = 'challenge-video';
        video.style.width = '100%';
        video.style.borderRadius = '10px';
        container.appendChild(video);

        if (transcriptText) {
            const transBtn = document.createElement('button');
            transBtn.type = 'button';
            transBtn.className = 'btn btn-secondary btn-sm';
            transBtn.style.marginTop = '8px';
            transBtn.innerText = '📖 Mostrar Subtítulos / Transcripción';

            const transDiv = document.createElement('div');
            transDiv.className = 'video-transcript-box hidden';
            transDiv.style.marginTop = '8px';
            transDiv.style.padding = '10px';
            transDiv.style.background = 'rgba(0,0,0,0.3)';
            transDiv.style.borderRadius = '8px';
            transDiv.style.fontSize = '0.85rem';
            transDiv.innerText = transcriptText;

            transBtn.onclick = () => {
                const hidden = transDiv.classList.contains('hidden');
                if (hidden) {
                    transDiv.classList.remove('hidden');
                    transBtn.innerText = '📖 Ocultar Transcripción';
                } else {
                    transDiv.classList.add('hidden');
                    transBtn.innerText = '📖 Mostrar Subtítulos / Transcripción';
                }
            };

            container.appendChild(transBtn);
            container.appendChild(transDiv);
        }

        return container;
    }
}
