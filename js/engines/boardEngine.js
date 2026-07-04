/**
 * TRIVIAX BoardEngine
 * Renderiza y gestiona múltiples estilos de tableros, posiciones y animaciones de fichas.
 */
import { delay } from '../utils.js';
import { playStepSound } from '../sound.js';

export class BoardEngine {
    constructor(containerEl) {
        this.container = containerEl;
        this.spacesLayer = null;
        this.svgLayer = null;
        this.tokensLayer = null;
        this.lastPlayersList = [];
        this.isPortrait = false;
        
        // Estilo de tablero activo
        this.layoutType = 'serpentine';
        this.boardSize = 50;
        this.loop = false;
        this.customPositions = [];     // Coordenadas manuales si es tipo 'map'
        this.specialCells = [];        // Celdas con efectos especiales [{ cell, type, effect, value }]

        this.handleResize = this.handleResize.bind(this);
    }

    /**
     * Inicializa y dibuja el tablero
     * @param {string} bgImageSrc 
     * @param {Object} boardConfig - { type, specialCells, customPositions }
     */
    init(bgImageSrc, boardConfig = {}) {
        this.layoutType = boardConfig.type || 'serpentine';
        this.boardSize = Number.isInteger(boardConfig.size) ? boardConfig.size : 50;
        this.loop = Boolean(boardConfig.loop);
        this.specialCells = boardConfig.specialCells || [];
        this.customPositions = boardConfig.customPositions || [];
        this.cellShape = boardConfig.cellShape || 'rounded';
        this.smoothPath = Boolean(boardConfig.smoothPath);
        // Tablero "pelado" (editor visual): la imagen ya trae las casillas y los
        // números pintados, así que NUNCA dibujamos casillas ni números encima.
        // Las fichas igualmente se mueven SALTANDO casilla a casilla por las
        // coordenadas guardadas (customPositions); eso siempre se anima.
        this.bareBoard = Boolean(boardConfig.bareBoard);
        // Línea del recorrido: en tableros pelados está oculta por defecto; el
        // docente puede mostrarla con showPath (la imagen ya suele traer su ruta).
        this.showPath = Boolean(boardConfig.showPath);

        // Limpiar
        this.container.innerHTML = '';
        this.updateLayoutMode();

        // Fondo
        const bgImg = document.createElement('img');
        bgImg.src = bgImageSrc;
        bgImg.className = 'board-background';
        bgImg.alt = 'Fondo de Tablero';
        this.container.appendChild(bgImg);

        // Capa SVG
        this.svgLayer = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        this.svgLayer.setAttribute('class', 'board-svg-layer');
        this.svgLayer.setAttribute('viewBox', '0 0 100 100');
        this.svgLayer.setAttribute('preserveAspectRatio', 'none');
        this.container.appendChild(this.svgLayer);

        // Capa Casillas
        this.spacesLayer = document.createElement('div');
        this.spacesLayer.className = 'board-spaces-layer';
        this.container.appendChild(this.spacesLayer);

        // Capa Fichas
        this.tokensLayer = document.createElement('div');
        this.tokensLayer.className = 'board-tokens-layer';
        this.container.appendChild(this.tokensLayer);

        // Línea del recorrido: en pelados solo si showPath. Casillas/números:
        // nunca en pelados (la imagen ya los trae).
        if (!this.bareBoard || this.showPath) {
            this.renderPath();
        }
        if (!this.bareBoard) {
            this.renderSpaces();
        }

        window.removeEventListener('resize', this.handleResize);
        window.addEventListener('resize', this.handleResize);
    }

    /**
     * Calcula coordenadas virtuales (X%, Y%) de una casilla
     * @param {number} index - Numero de casilla
     * @returns {Object} { x, y }
     */
    getSpaceCoords(index) {
        // Coordenadas de mapa personalizadas (editor visual): cubren TODAS las
        // casillas, incluida la 0 (Salida), por eso se comprueba antes que nada.
        if (this.layoutType === 'map' && this.customPositions[index]) {
            return this.customPositions[index];
        }

        if (index === 0) {
            if (this.layoutType === 'rectangular-loop') {
                return { x: 8, y: 88 };
            }
            if (this.layoutType === 'circular-loop') {
                return { x: 50, y: 8 };
            }
            return this.isPortrait ? { x: 12, y: 95.5 } : { x: 3.5, y: 84 };
        }

        const idx = index - 1;
        const boardSize = Math.max(1, this.boardSize);
        const denom = Math.max(1, boardSize - 1);

        if (this.layoutType === 'rectangular-loop') {
            const left = 8;
            const right = 92;
            const top = 10;
            const bottom = 88;
            const w = right - left;
            const h = bottom - top;
            const t = (index / (boardSize + 1)) * 4;

            if (t < 1) {
                return { x: left, y: bottom - (t * h) };
            }
            if (t < 2) {
                return { x: left + ((t - 1) * w), y: top };
            }
            if (t < 3) {
                return { x: right, y: top + ((t - 2) * h) };
            }
            return { x: right - ((t - 3) * w), y: bottom };
        }

        if (this.layoutType === 'circular-loop') {
            const centerX = 50;
            const centerY = 50;
            const radius = this.isPortrait ? 40 : 38;
            const angle = -Math.PI / 2 + (index / (boardSize + 1)) * Math.PI * 2;
            return {
                x: centerX + radius * Math.cos(angle),
                y: centerY + radius * Math.sin(angle)
            };
        }

        // 1. RECORRIDO LINEAL SIMPLE
        if (this.layoutType === 'linear') {
            if (this.isPortrait) {
                // Vertical lineal descendente/ascendente
                return {
                    x: 50,
                    y: 8 + (idx * (82 / denom))
                };
            } else {
                // Horizontal lineal de izquierda a derecha
                return {
                    x: 9.5 + (idx * (80 / denom)),
                    y: 50
                };
            }
        }

        // 2. RECORRIDO CIRCULAR O ESPIRAL
        if (this.layoutType === 'circular') {
            const centerX = 50;
            const centerY = 50;
            
            // Ángulo en radianes. Hacemos 1.5 vueltas
            const angle = (idx / boardSize) * Math.PI * 3.5;
            
            // Radio decreciente hacia el centro
            const r = 40 - (idx * (20 / boardSize));
            
            return {
                x: centerX + r * Math.cos(angle),
                y: centerY + r * Math.sin(angle)
            };
        }

        // 3. RECORRIDO EN SERPENTINA (OCA CLÁSICA)
        if (this.isPortrait) {
            const cols = 5;
            const rows = Math.ceil(boardSize / cols);
            const rowIndexFromBottom = Math.floor(idx / cols);
            const row = (rows - 1) - rowIndexFromBottom;
            const col = (rowIndexFromBottom % 2 === 0) ? (idx % cols) : ((cols - 1) - (idx % cols));
            return {
                x: 12 + col * (76 / Math.max(1, cols - 1)),
                y: 8 + row * (82 / Math.max(1, rows - 1))
            };
        } else {
            const cols = 10;
            const rows = Math.ceil(boardSize / cols);
            const row = (rows - 1) - Math.floor(idx / cols);
            const isRowEven = row % 2 === 0;
            const col = isRowEven ? (idx % cols) : ((cols - 1) - (idx % cols));
            return {
                x: 9.5 + col * (81 / Math.max(1, cols - 1)),
                y: 16 + row * (66 / Math.max(1, rows - 1))
            };
        }
    }

    /**
     * Genera un string de path SVG con curvas suavizadas Bézier usando Catmull-Rom
     */
    getCurvePath(points) {
        if (points.length < 2) return '';
        let d = `M ${points[0].x} ${points[0].y}`;
        for (let i = 0; i < points.length - 1; i++) {
            const p0 = points[i - 1] || points[i];
            const p1 = points[i];
            const p2 = points[i + 1];
            const p3 = points[i + 2] || p2;

            const cp1x = p1.x + (p2.x - p0.x) / 6;
            const cp1y = p1.y + (p2.y - p0.y) / 6;
            const cp2x = p2.x - (p3.x - p1.x) / 6;
            const cp2y = p2.y - (p3.y - p1.y) / 6;

            d += ` C ${cp1x} ${cp1y}, ${cp2x} ${cp2y}, ${p2.x} ${p2.y}`;
        }
        return d;
    }

    /**
     * Dibuja la línea que conecta todas las casillas
     */
    renderPath() {
        if (this.smoothPath) {
            const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            path.setAttribute('class', 'board-path-line');
            
            const points = [];
            for (let i = 0; i <= this.boardSize; i++) {
                points.push(this.getSpaceCoords(i));
            }
            if (this.loop) {
                points.push(this.getSpaceCoords(0));
            }
            
            path.setAttribute('d', this.getCurvePath(points));
            this.svgLayer.appendChild(path);
        } else {
            const polyline = document.createElementNS('http://www.w3.org/2000/svg', 'polyline');
            polyline.setAttribute('class', 'board-path-line');
            
            let pointsStr = '';
            for (let i = 0; i <= this.boardSize; i++) {
                const coords = this.getSpaceCoords(i);
                pointsStr += `${coords.x},${coords.y} `;
            }
            if (this.loop) {
                const coords = this.getSpaceCoords(0);
                pointsStr += `${coords.x},${coords.y} `;
            }
            
            polyline.setAttribute('points', pointsStr.trim());
            this.svgLayer.appendChild(polyline);
        }
    }

    /**
     * Dibuja los círculos de las casillas
     */
    renderSpaces() {
        for (let i = 0; i <= this.boardSize; i++) {
            const coords = this.getSpaceCoords(i);
            const spaceDiv = document.createElement('div');
            spaceDiv.className = `board-space space-${i}`;
            
            if (this.cellShape === 'circle') {
                spaceDiv.style.borderRadius = '50%';
            } else if (this.cellShape === 'rounded') {
                spaceDiv.style.borderRadius = '12px';
            }
            
            if (i === 0) {
                spaceDiv.classList.add('space-start');
                spaceDiv.innerHTML = '<span class="space-label">Salida</span>';
            } else if (i === this.boardSize && !this.loop) {
                spaceDiv.classList.add('space-meta');
                spaceDiv.innerHTML = `<span class="space-label">Meta</span><span class="space-number">${this.boardSize}</span>`;
            } else {
                spaceDiv.innerHTML = `<span class="space-number">${i}</span>`;
            }

            // Comprobar si es celda especial para añadir badge o estilo
            const special = this.specialCells.find(c => c.cell === i);
            if (special) {
                spaceDiv.style.border = '2.5px solid var(--accent)';
                spaceDiv.classList.add(`special-${special.type}`);
                spaceDiv.title = `Casilla Especial: ${special.type === 'bonus' ? 'Bonus (+)' : 'Castigo (-)'}`;
                
                const badge = document.createElement('span');
                badge.style.position = 'absolute';
                badge.style.top = '-8px';
                badge.style.right = '-8px';
                badge.style.fontSize = '0.65rem';
                badge.style.background = special.type === 'bonus' ? 'var(--success)' : 'var(--danger)';
                badge.style.borderRadius = '50%';
                badge.style.width = '14px';
                badge.style.height = '14px';
                badge.style.display = 'flex';
                badge.style.alignItems = 'center';
                badge.style.justifyContent = 'center';
                badge.innerText = special.type === 'bonus' ? '⭐' : '💀';
                spaceDiv.appendChild(badge);
            }

            spaceDiv.style.left = `${coords.x}%`;
            spaceDiv.style.top = `${coords.y}%`;
            
            this.spacesLayer.appendChild(spaceDiv);
        }
    }

    /**
     * Dibuja las fichas con offsets para celdas ocupadas
     * @param {Array} players 
     */
    updateTokens(players) {
        this.lastPlayersList = players;
        if (!this.tokensLayer) return;
        this.tokensLayer.innerHTML = '';

        const occupantsMap = {};
        players.forEach(p => {
            if (!occupantsMap[p.position]) occupantsMap[p.position] = [];
            occupantsMap[p.position].push(p);
        });

        Object.entries(occupantsMap).forEach(([spaceStr, occupants]) => {
            const spaceIdx = parseInt(spaceStr);
            const coords = this.getSpaceCoords(spaceIdx);
            const N = occupants.length;

            occupants.forEach((player, idx) => {
                const token = document.createElement('div');
                const specialToken = player.token && player.token.type === 'special' && player.token.file_path;
                token.className = specialToken
                    ? `player-token token-${player.color.id} token-special`
                    : `player-token token-${player.color.id}`;
                token.style.backgroundColor = specialToken ? 'transparent' : player.color.hex;
                token.style.color = player.color.text;

                const initials = player.name
                    .split(' ')
                    .filter(w => w.length > 0)
                    .map(w => w[0].toUpperCase())
                    .slice(0, 2)
                    .join('');
                if (specialToken) {
                    const img = document.createElement('img');
                    img.className = 'token-img';
                    img.src = player.token.file_path;
                    img.alt = player.token.label || `Ficha de ${player.name}`;
                    token.appendChild(img);
                } else {
                    token.innerText = initials || player.id;
                }

                let dx = 0;
                let dy = 0;
                if (N > 1) {
                    const r = this.isPortrait ? 2.4 : 1.4;
                    const angle = (idx * 2 * Math.PI) / N;
                    dx = r * Math.cos(angle);
                    dy = r * Math.sin(angle);
                }

                token.style.left = `calc(${coords.x}% + ${dx}%)`;
                token.style.top = `calc(${coords.y}% + ${dy}%)`;
                token.title = specialToken
                    ? `${player.name} (${player.token.label || 'ficha especial'}) - Pts: ${player.score}`
                    : `${player.name} (${player.color.name}) - Pts: ${player.score}`;

                this.tokensLayer.appendChild(token);
            });
        });
    }

    /**
     * Animación paso a paso de la ficha del jugador
     * @param {Object} player 
     * @param {number} from 
     * @param {number} to 
     */
    async animateTokenMove(player, from, to) {
        if (from === to) return;

        const clonedPlayers = this.lastPlayersList.map(p => {
            if (p.id === player.id) return { ...p, position: from };
            return { ...p };
        });

        const direction = from < to ? 1 : -1;
        let current = from;

        while (current !== to) {
            current += direction;
            playStepSound();

            const pClone = clonedPlayers.find(p => p.id === player.id);
            if (pClone) pClone.position = current;

            this.updateTokens(clonedPlayers);
            await delay(220);
        }
    }

    /**
     * Anima una ruta calculada paso a paso, útil para rebotes o vueltas al tablero.
     * @param {Object} player
     * @param {Array<number>} path
     */
    async animateTokenPath(player, path = []) {
        if (!Array.isArray(path) || path.length === 0) return;

        const clonedPlayers = this.lastPlayersList.map(p => ({ ...p }));
        const pClone = clonedPlayers.find(p => p.id === player.id);
        if (!pClone) return;

        for (const pos of path) {
            playStepSound();
            pClone.position = pos;
            this.updateTokens(clonedPlayers);
            await delay(220);
        }
    }

    /**
     * Determina la orientación y redibuja si es necesario
     */
    updateLayoutMode() {
        const portrait = window.innerHeight > window.innerWidth;
        if (portrait !== this.isPortrait) {
            this.isPortrait = portrait;
            if (portrait) {
                this.container.classList.add('board-portrait');
            } else {
                this.container.classList.remove('board-portrait');
            }
            return true;
        }
        return false;
    }

    handleResize() {
        if (!this.spacesLayer) return;
        if (this.updateLayoutMode()) {
            if (this.svgLayer) this.svgLayer.innerHTML = '';
            if (!this.bareBoard || this.showPath) this.renderPath();
            if (!this.bareBoard) {
                if (this.spacesLayer) this.spacesLayer.innerHTML = '';
                this.renderSpaces();
            }
            this.updateTokens(this.lastPlayersList);
        }
    }
}
