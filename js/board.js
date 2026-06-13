/**
 * Controlador del Tablero de TRIVIAX
 */

import { BOARD_SIZE } from './config.js';
import { delay, qs } from './utils.js';
import { playStepSound } from './sound.js';

/**
 * Calcula las coordenadas (x%, y%) de una casilla dada (0 a 50)
 * @param {number} index - Número de casilla
 * @param {boolean} isPortrait - Si la orientación es vertical (retrato)
 * @returns {Object} { x, y } coordenadas en porcentaje
 */
export function getSpaceCoords(index, isPortrait = false) {
    if (isPortrait) {
        if (index === 0) {
            // Casilla 0 (Salida) - ubicada al final, debajo de la primera columna
            return { x: 12, y: 95.5 };
        }
        
        const idx = index - 1; // 0 a 49
        const rowIndexFromBottom = Math.floor(idx / 5);
        const row = 9 - rowIndexFromBottom; // Fila 9 (abajo) a Fila 0 (arriba)
        
        // Alternar dirección en serpentina vertical (5 columnas)
        const col = (rowIndexFromBottom % 2 === 0) ? (idx % 5) : (4 - (idx % 5));
        
        // Coordenadas para Portrait (5 columnas x 10 filas)
        const x = 12 + col * 19;
        const y = 8 + row * 9.1;
        
        return { x, y };
    } else {
        if (index === 0) {
            // Casilla 0 (Salida) - ubicada a la izquierda de la casilla 1
            return { x: 3.5, y: 84 };
        }
        
        const idx = index - 1; // 0 a 49
        const row = 4 - Math.floor(idx / 10); // Fila 4 (abajo) a Fila 0 (arriba)
        
        // Alternar dirección (serpentina horizontal)
        const isRowEven = row % 2 === 0;
        const col = isRowEven ? (idx % 10) : (9 - (idx % 10));
        
        // Coordenadas con márgenes para centrar y encajar sobre fondo.png
        const x = 9.5 + col * 9;
        const y = 16 + row * 16.5;
        
        return { x, y };
    }
}


export class Board {
    /**
     * @param {HTMLElement} containerEl - Contenedor del tablero (.board-container)
     */
    constructor(containerEl) {
        this.container = containerEl;
        this.spacesLayer = null;
        this.svgLayer = null;
        this.tokensLayer = null;
        this.lastPlayersList = [];
        this.isPortrait = false;
        
        this.handleResize = this.handleResize.bind(this);
    }

    /**
     * Inicializa y dibuja la estructura base del tablero
     * @param {string} bgImageSrc - URL de fondo.png
     */
    init(bgImageSrc) {
        // Limpiar el contenedor
        this.container.innerHTML = '';
        
        // Determinar orientación actual
        this.updateLayoutMode();
        
        // Crear elemento de imagen de fondo
        const bgImg = document.createElement('img');
        bgImg.src = bgImageSrc;
        bgImg.className = 'board-background';
        bgImg.alt = 'Fondo del Tablero';
        this.container.appendChild(bgImg);
        
        // Crear capa SVG para el camino
        this.svgLayer = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        this.svgLayer.setAttribute('class', 'board-svg-layer');
        // Configurar viewBox 0 0 100 100 para coordenadas virtuales relativas %
        this.svgLayer.setAttribute('viewBox', '0 0 100 100');
        this.svgLayer.setAttribute('preserveAspectRatio', 'none');
        this.container.appendChild(this.svgLayer);

        // Crear capa para las casillas
        this.spacesLayer = document.createElement('div');
        this.spacesLayer.className = 'board-spaces-layer';
        this.container.appendChild(this.spacesLayer);
        
        // Crear capa para las fichas
        this.tokensLayer = document.createElement('div');
        this.tokensLayer.className = 'board-tokens-layer';
        this.container.appendChild(this.tokensLayer);

        // Renderizar elementos
        this.renderPath();
        this.renderSpaces();
        
        // Registrar evento resize asegurando no duplicarlo
        window.removeEventListener('resize', this.handleResize);
        window.addEventListener('resize', this.handleResize);
    }

    /**
     * Evalúa si la pantalla está en modo retrato (vertical)
     * @returns {boolean} true si cambió de orientación
     */
    updateLayoutMode() {
        const portrait = window.innerHeight > window.innerWidth;
        if (portrait !== this.isPortrait) {
            this.isPortrait = portrait;
            if (this.isPortrait) {
                this.container.classList.add('board-portrait');
            } else {
                this.container.classList.remove('board-portrait');
            }
            return true;
        }
        return false;
    }

    /**
     * Redibuja el tablero si cambia la orientación de pantalla
     */
    handleResize() {
        if (!this.spacesLayer) return;
        if (this.updateLayoutMode()) {
            // Re-dibujar camino SVG
            if (this.svgLayer) this.svgLayer.innerHTML = '';
            this.renderPath();
            
            // Re-dibujar casillas
            if (this.spacesLayer) this.spacesLayer.innerHTML = '';
            this.renderSpaces();
            
            // Re-dibujar fichas
            this.updateTokens(this.lastPlayersList);
        }
    }

    /**
     * Dibuja la línea que conecta todas las casillas del tablero
     */
    renderPath() {
        const polyline = document.createElementNS('http://www.w3.org/2000/svg', 'polyline');
        polyline.setAttribute('class', 'board-path-line');
        
        let pointsStr = '';
        for (let i = 0; i <= BOARD_SIZE; i++) {
            const coords = getSpaceCoords(i, this.isPortrait);
            pointsStr += `${coords.x},${coords.y} `;
        }
        
        polyline.setAttribute('points', pointsStr.trim());
        this.svgLayer.appendChild(polyline);
    }

    /**
     * Dibuja los círculos de las casillas en el tablero
     */
    renderSpaces() {
        for (let i = 0; i <= BOARD_SIZE; i++) {
            const coords = getSpaceCoords(i, this.isPortrait);
            const spaceDiv = document.createElement('div');
            spaceDiv.className = `board-space space-${i}`;
            
            if (i === 0) {
                spaceDiv.classList.add('space-start');
                spaceDiv.innerHTML = '<span class="space-label">Salida</span>';
            } else if (i === BOARD_SIZE) {
                spaceDiv.classList.add('space-meta');
                spaceDiv.innerHTML = '<span class="space-label">Meta</span><span class="space-number">50</span>';
            } else {
                spaceDiv.innerHTML = `<span class="space-number">${i}</span>`;
            }
            
            // Posicionamiento porcentual
            spaceDiv.style.left = `${coords.x}%`;
            spaceDiv.style.top = `${coords.y}%`;
            
            this.spacesLayer.appendChild(spaceDiv);
        }
    }

    /**
     * Actualiza y dibuja las fichas de los jugadores en el tablero
     * @param {Array} players - Lista de jugadores activos
     */
    updateTokens(players) {
        this.lastPlayersList = players;
        if (!this.tokensLayer) return;
        this.tokensLayer.innerHTML = '';
        
        // Agrupar jugadores por casilla para aplicar offset si coinciden
        const spaceOccupants = {};
        players.forEach(player => {
            const pos = player.position;
            if (!spaceOccupants[pos]) {
                spaceOccupants[pos] = [];
            }
            spaceOccupants[pos].push(player);
        });

        // Dibujar las fichas de cada jugador
        Object.entries(spaceOccupants).forEach(([spaceStr, occupants]) => {
            const spaceIndex = parseInt(spaceStr);
            const coords = getSpaceCoords(spaceIndex, this.isPortrait);
            const N = occupants.length;

            occupants.forEach((player, idx) => {
                const token = document.createElement('div');
                token.className = `player-token token-${player.color.id}`;
                token.style.backgroundColor = player.color.hex;
                token.style.color = player.color.text;
                
                // Obtener las iniciales del nombre del jugador (ej. "Luis Darwin" -> "LD")
                const initials = player.name
                    .split(' ')
                    .filter(w => w.length > 0)
                    .map(w => w[0].toUpperCase())
                    .slice(0, 2)
                    .join('');
                token.innerText = initials || player.id;
                
                // Cálculo de offsets si hay múltiples jugadores en la misma casilla
                let dx = 0;
                let dy = 0;
                
                if (N > 1) {
                    // Distribución circular alrededor del centro
                    // En porcentajes relativos al contenedor (aprox 1.4% en landscape, 2.4% en portrait)
                    const r = this.isPortrait ? 2.4 : 1.4; 
                    const angle = (idx * 2 * Math.PI) / N;
                    dx = r * Math.cos(angle);
                    dy = r * Math.sin(angle);
                }

                // Posicionamiento
                token.style.left = `calc(${coords.x}% + ${dx}%)`;
                token.style.top = `calc(${coords.y}% + ${dy}%)`;
                token.title = `${player.name} (${player.color.name}) - Pts: ${player.score}`;

                this.tokensLayer.appendChild(token);
            });
        });
    }

    /**
     * Ejecuta una animación paso a paso de la ficha de un jugador avanzando casilla por casilla
     * @param {Object} player - Jugador que se moverá
     * @param {number} fromSpace - Casilla origen
     * @param {number} toSpace - Casilla destino
     */
    async animateTokenMove(player, fromSpace, toSpace) {
        if (fromSpace === toSpace) return;
        
        // Clonar la lista de jugadores para actualizar la vista sin alterar el estado real inmediatamente
        const tempPlayers = this.lastPlayersList.map(p => {
            if (p.id === player.id) {
                return { ...p, position: fromSpace };
            }
            return { ...p };
        });

        // Avanzar casillas secuencialmente
        const direction = fromSpace < toSpace ? 1 : -1;
        let currentPos = fromSpace;

        while (currentPos !== toSpace) {
            currentPos += direction;
            
            // Reproducir sonido de paso sutil
            playStepSound();

            // Actualizar la posición del clon
            const targetClonedPlayer = tempPlayers.find(p => p.id === player.id);
            if (targetClonedPlayer) {
                targetClonedPlayer.position = currentPos;
            }
            
            // Re-renderizar fichas con el estado temporal
            this.updateTokens(tempPlayers);
            
            // Retraso para simular el paso por la casilla
            await delay(220);
        }
    }
}
