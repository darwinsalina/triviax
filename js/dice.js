/**
 * Controlador del Dado Virtual 3D de TRIVIAX
 */

import { delay } from './utils.js';

export class Dice {
    /**
     * @param {HTMLElement} el - Elemento del dado (.dice-element)
     */
    constructor(el) {
        this.el = el;
        this.isRolling = false;
        this.currentValue = 1;
        this.rotX = 0;
        this.rotY = 0;
        
        // Crear las 6 caras del dado 3D en el constructor
        this.init3D();
    }

    /**
     * Inicializa la estructura de 6 caras del cubo 3D
     */
    init3D() {
        this.el.innerHTML = '';
        this.el.style.transformStyle = 'preserve-3d';
        
        // Crear las 6 caras
        for (let faceNum = 1; faceNum <= 6; faceNum++) {
            const faceDiv = document.createElement('div');
            faceDiv.className = `dice-face face-${faceNum}`;
            this.renderFace(faceDiv, faceNum);
            this.el.appendChild(faceDiv);
        }
        
        // Establecer rotación inicial para que muestre la cara 1
        this.rotX = 0;
        this.rotY = 0;
        this.el.style.transform = `rotateX(0deg) rotateY(0deg)`;
    }

    /**
     * Genera un número aleatorio entre 1 y 6 y ejecuta la animación de giro en 3D
     * @returns {Promise<number>} Valor obtenido del dado
     */
    async roll() {
        if (this.isRolling) return this.currentValue;
        
        this.isRolling = true;

        // Obtener el valor definitivo
        const finalValue = Math.floor(Math.random() * 6) + 1;
        
        // Mapear los ángulos correspondientes a cada cara para que mire de frente
        const faceAngles = {
            1: { x: 0, y: 0 },
            2: { x: 0, y: 180 },
            3: { x: 0, y: -90 },
            4: { x: 0, y: 90 },
            5: { x: -90, y: 0 },
            6: { x: 90, y: 0 }
        };

        const target = faceAngles[finalValue];

        // Calcular diferencias positivas para que siempre gire hacia adelante en 3D
        const diffX = ((target.x - this.rotX) % 360 + 360) % 360;
        const diffY = ((target.y - this.rotY) % 360 + 360) % 360;

        // Añadir entre 2 y 4 vueltas completas para que el giro se vea dinámico
        const spinsX = (Math.floor(Math.random() * 2) + 2) * 360;
        const spinsY = (Math.floor(Math.random() * 2) + 2) * 360;

        this.rotX += diffX + spinsX;
        this.rotY += diffY + spinsY;

        // Aplicar la transición y la transformación 3D
        this.el.style.transition = 'transform 1.4s cubic-bezier(0.2, 0.85, 0.25, 1.05)';
        this.el.style.transform = `rotateX(${this.rotX}deg) rotateY(${this.rotY}deg)`;

        // Esperar a que la animación de rotación termine
        await delay(1400);

        this.currentValue = finalValue;
        this.isRolling = false;
        
        this.el.setAttribute('data-value', finalValue);

        return finalValue;
    }

    /**
     * Dibuja los puntos correspondientes a una cara del dado en un grid de 3x3
     * @param {HTMLElement} faceEl - Elemento de la cara
     * @param {number} value - Valor de la cara (1 a 6)
     */
    renderFace(faceEl, value) {
        // Mapear los puntos en un grid de 3x3 (1-indexed de izquierda a derecha, arriba a abajo)
        const dotPositions = {
            1: [5],
            2: [1, 9],
            3: [1, 5, 9],
            4: [1, 3, 7, 9],
            5: [1, 3, 5, 7, 9],
            6: [1, 3, 4, 6, 7, 9]
        };

        const activeDots = dotPositions[value] || [5];

        // Crear 9 celdas para el grid del dado y agregar puntos en las activas
        for (let cell = 1; cell <= 9; cell++) {
            const cellDiv = document.createElement('div');
            cellDiv.className = 'dice-grid-cell';
            
            if (activeDots.includes(cell)) {
                const dot = document.createElement('span');
                dot.className = 'dice-dot';
                cellDiv.appendChild(dot);
            }
            
            faceEl.appendChild(cellDiv);
        }
    }
}
