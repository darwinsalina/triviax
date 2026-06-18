/**
 * TRIVIAX ActivityRendererRegistry
 * Registro y renderizado dinámico de tipos de desafíos (actividades).
 * Implementado de forma accesible y adaptada a móviles (Click-to-Place).
 */
import { MediaManager } from '../services/mediaManager.js';
import { shuffle } from '../utils.js';
import { normalizeChallenge, normalizeChallengeType } from '../validators/challengeValidators.js';

export class ActivityRendererRegistry {
    constructor() {
        this.renderers = new Map();
        this.registerDefaultRenderers();
    }

    /**
     * Registra un nuevo renderizador de actividad
     * @param {string} type 
     * @param {Object} renderer - Objeto con { render: (challenge, container, onSubmit) => void }
     */
    register(type, renderer) {
        this.renderers.set(type, renderer);
    }

    /**
     * Renderiza un desafío específico en el contenedor provisto
     * @param {Object} challenge 
     * @param {HTMLElement} container 
     * @param {string} projectName
     * @param {Function} onSubmit - Callback ({ isCorrect, selectedText })
     */
    renderChallenge(challenge, container, projectName, onSubmit) {
        container.innerHTML = '';
        
        // Buscar el tipo de renderizador, caer a multiple_choice por defecto
        const normalizedChallenge = normalizeChallenge(challenge);
        const type = normalizeChallengeType(normalizedChallenge.type);
        const renderer = this.renderers.get(type) || this.renderers.get('multiple_choice');

        if (renderer) {
            renderer.render(normalizedChallenge, container, projectName, onSubmit);
        } else {
            container.innerHTML = `<p style="color: var(--danger);">Error: No se pudo encontrar renderizador para el tipo "${type}".</p>`;
        }
    }

    /**
     * Registra los tipos de desafíos por defecto
     */
    registerDefaultRenderers() {
        // 1. OPCIÓN MÚLTIPLE
        this.register('multiple_choice', {
            render(challenge, container, projectName, onSubmit) {
                const opts = challenge.options || challenge.answers || [];
                const randomizedOptions = shuffle(opts);

                // Layout 2 columnas cuando hay exactamente 4 opciones
                const optionsGrid = document.createElement('div');
                optionsGrid.className = randomizedOptions.length === 4
                    ? 'options-grid options-grid--2col'
                    : 'options-grid';

                randomizedOptions.forEach(opt => {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'option-btn';
                    btn.innerText = opt.text;

                    const isCorrect = opt.correct || (challenge.answer && challenge.answer.correctOptionId === opt.id);
                    btn.setAttribute('data-correct', isCorrect ? 'true' : 'false');

                    btn.onclick = () => {
                        onSubmit({ isCorrect, selectedText: opt.text, raw: { optionId: opt.id, optionText: opt.text } });
                    };
                    optionsGrid.appendChild(btn);
                });

                container.appendChild(optionsGrid);
            }
        });

        // 2. VERDADERO O FALSO — siempre en 2 columnas
        this.register('true_false', {
            render(challenge, container, projectName, onSubmit) {
                // Siempre 2 columnas: Verdadero | Falso
                const optionsGrid = document.createElement('div');
                optionsGrid.className = 'options-grid options-grid--2col';

                const tfOptions = [
                    { text: 'Verdadero', value: true },
                    { text: 'Falso',     value: false }
                ];

                const correctAnswer = challenge.answer?.value
                    ?? (challenge.answers?.find(a => a.correct)?.text?.toLowerCase() === 'verdadero');

                tfOptions.forEach(opt => {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'option-btn';
                    btn.innerText = opt.text;

                    const isCorrect = (opt.value === correctAnswer);
                    btn.setAttribute('data-correct', isCorrect ? 'true' : 'false');

                    btn.onclick = () => {
                        onSubmit({ isCorrect, selectedText: opt.text, raw: { boolValue: opt.value } });
                    };
                    optionsGrid.appendChild(btn);
                });

                container.appendChild(optionsGrid);
            }
        });

        // 3. ASOCIACIÓN DE PARES (MATCHING PAIRS) - Diseño apto para Drag & Drop y Click-to-Place
        this.register('matching_pairs', {
            render(challenge, container, projectName, onSubmit) {
                const instructions = document.createElement('p');
                instructions.style.fontSize = '0.9rem';
                instructions.style.color = 'var(--text-secondary)';
                instructions.style.marginBottom = '15px';
                instructions.innerText = 'Arrastra los elementos de la izquierda y sueltalos en la ranura correspondiente junto a su pareja de la derecha. Tambien puedes hacer clic para seleccionarlos y colocarlos.';
                container.appendChild(instructions);

                const matchingLayout = document.createElement('div');
                matchingLayout.style.display = 'grid';
                matchingLayout.style.gridTemplateColumns = '1fr 1.2fr';
                matchingLayout.style.gap = '20px';
                matchingLayout.style.marginBottom = '20px';

                // Columna izquierda: pool de tarjetas arrastrables
                const leftCol = document.createElement('div');
                leftCol.style.display = 'flex';
                leftCol.style.flexDirection = 'column';
                leftCol.style.gap = '10px';
                
                const poolTitle = document.createElement('h5');
                poolTitle.innerText = 'Elementos';
                poolTitle.style.marginBottom = '4px';
                poolTitle.style.fontSize = '0.9rem';
                poolTitle.style.color = 'var(--text-muted)';
                leftCol.appendChild(poolTitle);

                const leftPoolContainer = document.createElement('div');
                leftPoolContainer.style.display = 'flex';
                leftPoolContainer.style.flexDirection = 'column';
                leftPoolContainer.style.gap = '10px';
                leftCol.appendChild(leftPoolContainer);

                // Columna derecha: parejas fijas con ranuras de soltado
                const rightCol = document.createElement('div');
                rightCol.style.display = 'flex';
                rightCol.style.flexDirection = 'column';
                rightCol.style.gap = '10px';

                const targetTitle = document.createElement('h5');
                targetTitle.innerText = 'Parejas';
                targetTitle.style.marginBottom = '4px';
                targetTitle.style.fontSize = '0.9rem';
                targetTitle.style.color = 'var(--text-muted)';
                rightCol.appendChild(targetTitle);

                const pairs = challenge.pairs || [];
                
                // Map each pair to include its original index
                const leftElements = pairs.map((p, idx) => ({ val: p.left, idx }));
                const rightElements = pairs.map((p, idx) => ({ val: p.right, idx }));

                // Shuffle elements
                const shuffledLeft = shuffle([...leftElements]);
                const shuffledRight = shuffle([...rightElements]);

                const connections = new Map(); // leftIdx -> rightIdx
                let selectedLeftIdx = null; // store selected left card original index
                const poolButtons = new Map(); // leftIdx -> element card

                // Crear tarjetas en el pool izquierdo
                shuffledLeft.forEach(item => {
                    const card = document.createElement('div');
                    card.className = 'btn btn-secondary draggable-card';
                    card.style.textTransform = 'none';
                    card.style.width = '100%';
                    card.style.padding = '12px';
                    card.innerText = item.val;
                    card.setAttribute('draggable', 'true');
                    card.setAttribute('data-idx', String(item.idx));

                    // Eventos Drag & Drop
                    card.addEventListener('dragstart', (e) => {
                        e.dataTransfer.setData('text/plain', String(item.idx));
                        card.classList.add('dragging');
                    });

                    card.addEventListener('dragend', () => {
                        card.classList.remove('dragging');
                    });

                    // Evento Click (Click-to-Place)
                    card.onclick = () => {
                        leftPoolContainer.querySelectorAll('.draggable-card').forEach(c => c.classList.remove('selected-card'));
                        if (selectedLeftIdx === item.idx) {
                            selectedLeftIdx = null;
                        } else {
                            selectedLeftIdx = item.idx;
                            card.classList.add('selected-card');
                        }
                    };

                    leftPoolContainer.appendChild(card);
                    poolButtons.set(item.idx, card);
                });

                // Crear filas en el lado derecho
                shuffledRight.forEach(item => {
                    const row = document.createElement('div');
                    row.style.display = 'flex';
                    row.style.flexDirection = 'column';
                    row.style.gap = '6px';
                    row.style.background = 'rgba(255, 255, 255, 0.02)';
                    row.style.border = '1px solid rgba(255, 255, 255, 0.04)';
                    row.style.padding = '10px';
                    row.style.borderRadius = '12px';

                    const fixedLabel = document.createElement('div');
                    fixedLabel.style.fontSize = '0.9rem';
                    fixedLabel.style.fontWeight = '700';
                    fixedLabel.innerText = item.val;
                    row.appendChild(fixedLabel);

                    const slot = document.createElement('div');
                    slot.className = 'pair-slot';
                    slot.innerText = 'Arrastra aqui';
                    slot.style.color = 'var(--text-muted)';
                    slot.style.fontSize = '0.8rem';
                    row.appendChild(slot);

                    // Eventos Drag & Drop del slot
                    slot.addEventListener('dragover', (e) => {
                        e.preventDefault();
                    });

                    slot.addEventListener('dragenter', (e) => {
                        e.preventDefault();
                        slot.classList.add('drag-over');
                    });

                    slot.addEventListener('dragleave', () => {
                        slot.classList.remove('drag-over');
                    });

                    const placeItemInSlot = (leftIdx) => {
                        leftIdx = parseInt(leftIdx, 10);
                        // Si ya hay algo en esta slot, devolverlo al pool
                        const oldLeftIdxStr = slot.getAttribute('data-placed');
                        if (oldLeftIdxStr) {
                            const oldLeftIdx = parseInt(oldLeftIdxStr, 10);
                            connections.delete(oldLeftIdx);
                            const oldCard = poolButtons.get(oldLeftIdx);
                            if (oldCard) oldCard.style.display = 'inline-flex';
                        }

                        // Colocar el nuevo
                        connections.set(leftIdx, item.idx);
                        slot.setAttribute('data-placed', String(leftIdx));
                        slot.innerHTML = '';
                        slot.classList.remove('drag-over');

                        const leftVal = pairs[leftIdx].left;
                        const tag = document.createElement('div');
                        tag.className = 'placed-card';
                        tag.innerHTML = `
                            <span>${leftVal}</span>
                            <span class="remove-btn" title="Quitar">&times;</span>
                        `;

                        tag.querySelector('.remove-btn').onclick = (e) => {
                            e.stopPropagation();
                            connections.delete(leftIdx);
                            slot.removeAttribute('data-placed');
                            slot.innerHTML = 'Arrastra aqui';
                            const card = poolButtons.get(leftIdx);
                            if (card) card.style.display = 'inline-flex';
                        };

                        slot.appendChild(tag);
                        
                        // Ocultar de la izquierda
                        const card = poolButtons.get(leftIdx);
                        if (card) {
                            card.style.display = 'none';
                            card.classList.remove('selected-card');
                        }
                        
                        selectedLeftIdx = null;
                        leftPoolContainer.querySelectorAll('.draggable-card').forEach(c => c.classList.remove('selected-card'));
                    };

                    slot.addEventListener('drop', (e) => {
                        e.preventDefault();
                        slot.classList.remove('drag-over');
                        const leftIdxStr = e.dataTransfer.getData('text/plain');
                        if (leftIdxStr !== '' && poolButtons.has(parseInt(leftIdxStr, 10))) {
                            placeItemInSlot(parseInt(leftIdxStr, 10));
                        }
                    });

                    // Clic en la ranura (Click-to-Place)
                    slot.onclick = () => {
                        if (selectedLeftIdx !== null) {
                            placeItemInSlot(selectedLeftIdx);
                        }
                    };

                    rightCol.appendChild(row);
                });

                matchingLayout.appendChild(leftCol);
                matchingLayout.appendChild(rightCol);
                container.appendChild(matchingLayout);

                // Boton de Confirmar Parejas
                const confirmBtn = document.createElement('button');
                confirmBtn.type = 'button';
                confirmBtn.className = 'btn btn-primary';
                confirmBtn.style.width = '100%';
                confirmBtn.innerText = 'Confirmar Parejas';
                confirmBtn.onclick = () => {
                    if (connections.size < pairs.length) {
                        alert('Por favor, asocia todas las parejas antes de confirmar.');
                        return;
                    }

                    let correctCount = 0;
                    connections.forEach((rIdx, lIdx) => {
                        if (lIdx === rIdx) {
                            correctCount++;
                        }
                    });

                    const isAllCorrect = (correctCount === pairs.length);
                    // Respuesta cruda: mapa { textoIzquierda: textoDerecha } elegido por el alumno.
                    const rawPairs = {};
                    connections.forEach((rIdx, lIdx) => { rawPairs[pairs[lIdx].left] = pairs[rIdx].right; });
                    onSubmit({
                        isCorrect: isAllCorrect,
                        selectedText: `Pares formados (${correctCount}/${pairs.length} correctos)`,
                        raw: { pairs: rawPairs }
                    });
                };
                container.appendChild(confirmBtn);
            }
        });

        // 4. ORDENAR SECUENCIA (SEQUENCE ORDER) - Con reordenamiento Drag & Drop y botones de flechas
        this.register('sequence_order', {
            render(challenge, container, projectName, onSubmit) {
                const instructions = document.createElement('p');
                instructions.style.fontSize = '0.9rem';
                instructions.style.color = 'var(--text-secondary)';
                instructions.style.marginBottom = '15px';
                instructions.innerText = 'Arrastra y suelta los elementos para ordenarlos, o utiliza las flechas para cambiarlos de posición de arriba a abajo.';
                container.appendChild(instructions);

                const list = document.createElement('div');
                list.className = 'sequence-list';
                list.style.display = 'flex';
                list.style.flexDirection = 'column';
                list.style.gap = '8px';
                list.style.marginBottom = '20px';

                let currentOrder = shuffle(challenge.items || []);
                if (JSON.stringify(currentOrder) === JSON.stringify(challenge.items)) {
                    currentOrder = shuffle(currentOrder);
                }

                let draggedIdx = null;

                const renderList = () => {
                    list.innerHTML = '';
                    currentOrder.forEach((itemText, idx) => {
                        const row = document.createElement('div');
                        row.className = 'player-input-row';
                        row.style.justifyContent = 'space-between';
                        row.style.padding = '8px 16px';
                        row.setAttribute('draggable', 'true');

                        const label = document.createElement('span');
                        label.innerText = `${idx + 1}. ${itemText}`;
                        label.style.fontWeight = '600';
                        label.style.pointerEvents = 'none'; // Evitar interferencias con el arrastre
                        row.appendChild(label);

                        // Controles de drag & drop
                        row.addEventListener('dragstart', (e) => {
                            draggedIdx = idx;
                            row.classList.add('dragging');
                            e.dataTransfer.effectAllowed = 'move';
                        });

                        row.addEventListener('dragend', () => {
                            row.classList.remove('dragging');
                            list.querySelectorAll('.player-input-row').forEach(r => r.classList.remove('drag-over'));
                        });

                        row.addEventListener('dragover', (e) => {
                            e.preventDefault();
                        });

                        row.addEventListener('dragenter', (e) => {
                            e.preventDefault();
                            if (draggedIdx !== idx) {
                                row.classList.add('drag-over');
                            }
                        });

                        row.addEventListener('dragleave', () => {
                            row.classList.remove('drag-over');
                        });

                        row.addEventListener('drop', (e) => {
                            e.preventDefault();
                            row.classList.remove('drag-over');
                            const targetIdx = idx;
                            if (draggedIdx !== null && draggedIdx !== targetIdx) {
                                const movedItem = currentOrder[draggedIdx];
                                currentOrder.splice(draggedIdx, 1);
                                currentOrder.splice(targetIdx, 0, movedItem);
                                renderList();
                            }
                        });

                        const controls = document.createElement('div');
                        controls.style.display = 'flex';
                        controls.style.gap = '6px';

                        // Botón Arriba
                        const upBtn = document.createElement('button');
                        upBtn.type = 'button';
                        upBtn.className = 'btn btn-secondary';
                        upBtn.style.padding = '4px 8px';
                        upBtn.innerHTML = '🔼';
                        upBtn.disabled = idx === 0;
                        upBtn.onclick = (e) => {
                            e.stopPropagation();
                            const temp = currentOrder[idx];
                            currentOrder[idx] = currentOrder[idx - 1];
                            currentOrder[idx - 1] = temp;
                            renderList();
                        };

                        // Botón Abajo
                        const downBtn = document.createElement('button');
                        downBtn.type = 'button';
                        downBtn.className = 'btn btn-secondary';
                        downBtn.style.padding = '4px 8px';
                        downBtn.innerHTML = '🔽';
                        downBtn.disabled = idx === currentOrder.length - 1;
                        downBtn.onclick = (e) => {
                            e.stopPropagation();
                            const temp = currentOrder[idx];
                            currentOrder[idx] = currentOrder[idx + 1];
                            currentOrder[idx + 1] = temp;
                            renderList();
                        };

                        controls.appendChild(upBtn);
                        controls.appendChild(downBtn);
                        row.appendChild(controls);
                        list.appendChild(row);
                    });
                };

                renderList();
                container.appendChild(list);

                const confirmBtn = document.createElement('button');
                confirmBtn.type = 'button';
                confirmBtn.className = 'btn btn-primary';
                confirmBtn.style.width = '100%';
                confirmBtn.innerText = 'Confirmar Orden';
                confirmBtn.onclick = () => {
                    const expectedOrder = challenge.answer?.order || challenge.items || [];
                    const isCorrect = JSON.stringify(currentOrder) === JSON.stringify(expectedOrder);
                    
                    onSubmit({
                        isCorrect: isCorrect,
                        selectedText: currentOrder.join(' -> '),
                        raw: { order: [...currentOrder] }
                    });
                };
                container.appendChild(confirmBtn);
            }
        });

        // 5. CLASIFICACIÓN / ARRASTRAR Y SOLTAR (DRAG & DROP + CLICK-TO-PLACE + TOQUE)
        this.register('drag_drop', {
            render(challenge, container, projectName, onSubmit) {
                // Instrucción contextual
                const instructions = document.createElement('p');
                instructions.style.cssText = 'font-size:0.9rem;color:var(--text-secondary);margin-bottom:15px;';
                instructions.innerText = 'Arrastra cada elemento a su categoría, o tócalo y luego toca la categoría.';
                container.appendChild(instructions);

                const draggables = challenge.draggables || [];
                const dropzones  = challenge.dropzones  || [];
                const answers    = challenge.answer     || {};

                // Estado compartido drag + click
                let selectedId   = null;          // para click-to-place
                let dragItemId   = null;           // para drag nativo
                const placements = new Map();      // draggableId → zoneId
                const btnMap     = new Map();      // draggableId → botón DOM
                const listMap    = new Map();      // zoneId      → contenedor DOM

                // ── Función central — colocar un elemento en una zona ──
                const placeItem = (itemId, zoneId) => {
                    const dragObj = draggables.find(d => d.id === itemId);
                    if (!dragObj) return;

                    // Quitar colocación previa si la hubiera (permite mover)
                    if (placements.has(itemId)) {
                        const prevZoneId = placements.get(itemId);
                        const prevList   = listMap.get(prevZoneId);
                        if (prevList) {
                            const existing = prevList.querySelector(`[data-drag-id="${CSS.escape(itemId)}"]`);
                            if (existing) existing.remove();
                        }
                    }

                    placements.set(itemId, zoneId);

                    // Ocultar el botón de la bandeja
                    const btn = btnMap.get(itemId);
                    if (btn) {
                        btn.style.display = 'none';
                        btn.classList.remove('drag-selected');
                    }

                    // Crear etiqueta en la zona destino
                    const itemsList = listMap.get(zoneId);
                    if (!itemsList) return;

                    const tag = document.createElement('div');
                    tag.dataset.dragId = itemId;
                    tag.style.cssText = [
                        'background:rgba(99,102,241,0.15)',
                        'border:1px solid var(--accent)',
                        'border-radius:6px',
                        'padding:5px 10px',
                        'font-size:0.82rem',
                        'display:flex',
                        'justify-content:space-between',
                        'align-items:center',
                        'cursor:default',
                    ].join(';');
                    tag.innerHTML = `<span>${dragObj.text}</span>
                        <button type="button" data-remove="${itemId}"
                            style="background:none;border:none;color:var(--danger);font-weight:800;font-size:1rem;
                                   cursor:pointer;padding:0 0 0 8px;line-height:1;" title="Quitar">×</button>`;

                    tag.querySelector('[data-remove]').onclick = (e) => {
                        e.stopPropagation();
                        placements.delete(itemId);
                        tag.remove();
                        if (btn) btn.style.display = '';
                    };

                    itemsList.appendChild(tag);

                    // Limpiar selección click
                    selectedId = null;
                    btnMap.forEach(b => b.classList.remove('drag-selected'));
                };

                // ── Bandeja de elementos ───────────────────────────────
                const tray = document.createElement('div');
                tray.style.cssText = 'display:flex;flex-wrap:wrap;gap:10px;margin-bottom:20px;min-height:44px;padding:8px;background:rgba(0,0,0,0.12);border-radius:10px;border:1px dashed rgba(255,255,255,0.08);';

                shuffle([...draggables]).forEach(drag => {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'btn btn-secondary';
                    btn.style.cssText = 'text-transform:none;padding:8px 14px;cursor:grab;touch-action:none;';
                    btn.innerText = drag.text;
                    btn.setAttribute('draggable', 'true');
                    btn.dataset.dragId = drag.id;
                    btnMap.set(drag.id, btn);

                    // ── Drag nativo ───────────────────────────────────
                    btn.addEventListener('dragstart', (e) => {
                        dragItemId = drag.id;
                        e.dataTransfer.effectAllowed = 'move';
                        e.dataTransfer.setData('text/plain', drag.id);
                        btn.style.opacity = '0.45';
                    });
                    btn.addEventListener('dragend', () => {
                        btn.style.opacity = '';
                        dragItemId = null;
                    });

                    // ── Click-to-place ────────────────────────────────
                    btn.addEventListener('click', () => {
                        if (selectedId === drag.id) {
                            selectedId = null;
                            btn.classList.remove('drag-selected');
                        } else {
                            selectedId = drag.id;
                            btnMap.forEach(b => b.classList.remove('drag-selected'));
                            btn.classList.add('drag-selected');
                        }
                    });

                    tray.appendChild(btn);
                });

                container.appendChild(tray);

                // ── Zonas de destino ──────────────────────────────────
                const cols = Math.min(3, dropzones.length);
                const grid = document.createElement('div');
                grid.style.cssText = `display:grid;grid-template-columns:repeat(${cols},1fr);gap:14px;margin-bottom:20px;`;

                dropzones.forEach(zone => {
                    const zoneCard = document.createElement('div');
                    zoneCard.className = 'glass-card drag-drop-zone';
                    zoneCard.dataset.zoneId = zone.id;
                    zoneCard.style.cssText = [
                        'padding:14px',
                        'min-height:90px',
                        'border:2px dashed rgba(255,255,255,0.13)',
                        'cursor:pointer',
                        'transition:border-color 0.2s,background 0.2s',
                        'position:relative',
                    ].join(';');

                    const zoneTitle = document.createElement('div');
                    zoneTitle.style.cssText = 'font-weight:800;font-size:0.88rem;margin-bottom:10px;text-align:center;color:var(--text-primary);';
                    zoneTitle.innerText = zone.label;
                    zoneCard.appendChild(zoneTitle);

                    const itemsList = document.createElement('div');
                    itemsList.style.cssText = 'display:flex;flex-direction:column;gap:6px;';
                    zoneCard.appendChild(itemsList);
                    listMap.set(zone.id, itemsList);

                    // ── Drop nativo ───────────────────────────────────
                    zoneCard.addEventListener('dragover', (e) => {
                        e.preventDefault();
                        e.dataTransfer.dropEffect = 'move';
                        zoneCard.style.borderColor = 'var(--accent)';
                        zoneCard.style.background   = 'rgba(99,102,241,0.08)';
                    });
                    zoneCard.addEventListener('dragleave', () => {
                        zoneCard.style.borderColor = '';
                        zoneCard.style.background   = '';
                    });
                    zoneCard.addEventListener('drop', (e) => {
                        e.preventDefault();
                        zoneCard.style.borderColor = '';
                        zoneCard.style.background   = '';
                        const id = e.dataTransfer.getData('text/plain') || dragItemId;
                        if (id) placeItem(id, zone.id);
                    });

                    // ── Click-to-place ────────────────────────────────
                    zoneCard.addEventListener('click', () => {
                        if (selectedId) {
                            placeItem(selectedId, zone.id);
                        }
                    });

                    // Highlight al estar seleccionado un elemento
                    zoneCard.addEventListener('mouseenter', () => {
                        if (selectedId) {
                            zoneCard.style.borderColor = 'var(--accent)';
                            zoneCard.style.background   = 'rgba(99,102,241,0.06)';
                        }
                    });
                    zoneCard.addEventListener('mouseleave', () => {
                        if (!dragItemId) {
                            zoneCard.style.borderColor = '';
                            zoneCard.style.background   = '';
                        }
                    });

                    grid.appendChild(zoneCard);
                });

                container.appendChild(grid);

                // ── Botón confirmar ───────────────────────────────────
                const confirmBtn = document.createElement('button');
                confirmBtn.type = 'button';
                confirmBtn.className = 'btn btn-primary';
                confirmBtn.style.width = '100%';
                confirmBtn.innerText = 'Confirmar Categorías';
                confirmBtn.onclick = () => {
                    if (placements.size < draggables.length) {
                        alert('Ubica todos los elementos en una categoría antes de confirmar.');
                        return;
                    }
                    let correctCount = 0;
                    placements.forEach((zoneId, dragId) => {
                        if (answers[dragId] === zoneId) correctCount++;
                    });
                    const isAllCorrect = correctCount === draggables.length;
                    onSubmit({
                        isCorrect: isAllCorrect,
                        selectedText: `Elementos clasificados (${correctCount}/${draggables.length} correctos)`,
                        raw: { placements: Object.fromEntries(placements) }
                    });
                };
                container.appendChild(confirmBtn);
            }
        });

        // 6. MULTIMEDIA CONSIGNE (MEDIA CHOICE - AUDIO/VIDEO)
        this.register('media_choice', {
            render(challenge, container, projectName, onSubmit) {
                const mediaContainer = document.createElement('div');
                mediaContainer.style.marginBottom = '20px';
                mediaContainer.style.display = 'flex';
                mediaContainer.style.justifyContent = 'center';

                // Encontrar el recurso multimedia
                const assetId = challenge.prompt?.media?.assetId;
                const projectAssets = challenge.assets || [];
                const asset = projectAssets.find(a => a.id === assetId) || challenge.prompt?.media;

                if (asset) {
                    const url = MediaManager.getAssetUrl(projectName, asset.src);
                    
                    if (asset.type === 'audio') {
                        const audioEl = MediaManager.createAudioElement(url, asset.transcript || 'Audio de consigna');
                        mediaContainer.appendChild(audioEl);
                    } else if (asset.type === 'video') {
                        const posterUrl = asset.poster ? MediaManager.getAssetUrl(projectName, asset.poster) : '';
                        const videoEl = MediaManager.createVideoElement(url, posterUrl, asset.transcript || 'Video de consigna');
                        mediaContainer.appendChild(videoEl);
                    } else if (asset.type === 'image') {
                        const imgEl = MediaManager.createImageElement(url, asset.alt || 'Imagen del desafío');
                        imgEl.style.maxWidth = '100%';
                        imgEl.style.maxHeight = '200px';
                        imgEl.style.borderRadius = '10px';
                        mediaContainer.appendChild(imgEl);
                    }
                }
                container.appendChild(mediaContainer);

                // Renderizar opciones debajo
                const optionsGrid = document.createElement('div');
                optionsGrid.className = 'options-grid';
                
                const randomizedOptions = shuffle(challenge.options || []);
                randomizedOptions.forEach(opt => {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'option-btn';
                    btn.innerText = opt.text;
                    
                    const isCorrect = (challenge.answer && challenge.answer.correctOptionId === opt.id);
                    btn.setAttribute('data-correct', isCorrect ? 'true' : 'false');

                    btn.onclick = () => {
                        onSubmit({
                            isCorrect: isCorrect,
                            selectedText: opt.text,
                            raw: { optionId: opt.id, optionText: opt.text }
                        });
                    };
                    optionsGrid.appendChild(btn);
                });
                
                container.appendChild(optionsGrid);
            }
        });

        // 7. HOTSPOT SOBRE IMAGEN
        this.register('image_hotspot', {
            render(challenge, container, projectName, onSubmit) {
                const instructions = document.createElement('p');
                instructions.style.fontSize = '0.9rem';
                instructions.style.color = 'var(--text-secondary)';
                instructions.style.marginBottom = '15px';
                instructions.innerText = challenge.prompt?.text || 'Toca sobre la zona correcta de la imagen.';
                container.appendChild(instructions);

                const imageWrapper = document.createElement('div');
                imageWrapper.style.position = 'relative';
                imageWrapper.style.display = 'inline-block';
                imageWrapper.style.cursor = 'crosshair';

                const assetId = challenge.prompt?.imageAssetId || challenge.imageAssetId;
                const projectAssets = challenge.assets || [];
                const asset = projectAssets.find(a => a.id === assetId) || { src: challenge.imageSrc };

                const imgUrl = MediaManager.getAssetUrl(projectName, asset.src);
                const img = MediaManager.createImageElement(imgUrl, asset.alt || 'Zona hotspot');
                img.style.maxWidth = '100%';
                img.style.maxHeight = '350px';
                img.style.borderRadius = '10px';
                imageWrapper.appendChild(img);

                imageWrapper.onclick = (e) => {
                    const rect = img.getBoundingClientRect();
                    const x = ((e.clientX - rect.left) / rect.width) * 100; // Porcentajes
                    const y = ((e.clientY - rect.top) / rect.height) * 100;

                    // Comprobar si está dentro de la zona esperada
                    const target = challenge.answer?.hotspot || {}; // { xMin, xMax, yMin, yMax }
                    
                    const inside = (
                        x >= (target.xMin || 0) &&
                        x <= (target.xMax || 100) &&
                        y >= (target.yMin || 0) &&
                        y <= (target.yMax || 100)
                    );

                    // Colocar un marcador de punto visible
                    const marker = document.createElement('div');
                    marker.style.position = 'absolute';
                    marker.style.left = `${x}%`;
                    marker.style.top = `${y}%`;
                    marker.style.width = '14px';
                    marker.style.height = '14px';
                    marker.style.background = inside ? 'var(--success)' : 'var(--danger)';
                    marker.style.border = '2px solid white';
                    marker.style.borderRadius = '50%';
                    marker.style.transform = 'translate(-50%, -50%)';
                    imageWrapper.appendChild(marker);

                    setTimeout(() => {
                        onSubmit({
                            isCorrect: inside,
                            selectedText: `Clic en coordenadas (${x.toFixed(0)}%, ${y.toFixed(0)}%)`,
                            raw: { point: { x, y } }
                        });
                    }, 800);
                };

                container.appendChild(imageWrapper);
            }
        });

        // 8. COMPLETAR ESPACIOS (FILL IN THE BLANKS)
        this.register('fill_blank', {
            render(challenge, container, projectName, onSubmit) {
                const instructions = document.createElement('p');
                instructions.style.fontSize = '0.9rem';
                instructions.style.color = 'var(--text-secondary)';
                instructions.style.marginBottom = '15px';
                instructions.innerText = 'Selecciona la palabra correcta de cada lista desplegable para completar la frase.';
                container.appendChild(instructions);

                const textBlock = document.createElement('div');
                textBlock.style.fontSize = '1.1rem';
                textBlock.style.lineHeight = '1.8';
                textBlock.style.background = 'rgba(0,0,0,0.15)';
                textBlock.style.padding = '15px 20px';
                textBlock.style.borderRadius = '10px';
                textBlock.style.marginBottom = '20px';

                const promptText = challenge.prompt?.text || '';
                // Ejemplo de texto: "El procesador es el [blank1] de la computadora, mientras que el disco rígido se encarga del [blank2]."
                // Reemplazamos [blankX] por elementos select HTML
                const parts = promptText.split(/(\[blank\d+\])/g);
                const selectElements = [];
                const blanksData = challenge.blanks || {}; // { blank1: { options: [...], correct: "..." } }

                parts.forEach(part => {
                    const match = part.match(/^\[(blank\d+)\]$/);
                    if (match) {
                        const blankId = match[1];
                        const blankConfig = blanksData[blankId] || { options: [], correct: '' };

                        const select = document.createElement('select');
                        select.style.margin = '0 6px';
                        select.style.padding = '4px 10px';
                        select.style.borderRadius = '6px';
                        select.style.background = 'rgba(255,255,255,0.05)';
                        select.style.border = '1px solid rgba(255,255,255,0.15)';
                        select.style.color = 'var(--text-primary)';
                        
                        const defaultOpt = document.createElement('option');
                        defaultOpt.value = '';
                        defaultOpt.innerText = '...';
                        select.appendChild(defaultOpt);

                        const shuffledOpts = shuffle(blankConfig.options || []);
                        shuffledOpts.forEach(opt => {
                            const o = document.createElement('option');
                            o.value = opt;
                            o.innerText = opt;
                            select.appendChild(o);
                        });

                        selectElements.push({ select, correct: blankConfig.correct, blankId });
                        textBlock.appendChild(select);
                    } else {
                        const textSpan = document.createElement('span');
                        textSpan.innerText = part;
                        textBlock.appendChild(textSpan);
                    }
                });

                container.appendChild(textBlock);

                // Botón de confirmación
                const confirmBtn = document.createElement('button');
                confirmBtn.type = 'button';
                confirmBtn.className = 'btn btn-primary';
                confirmBtn.style.width = '100%';
                confirmBtn.innerText = 'Confirmar Respuestas';
                confirmBtn.onclick = () => {
                    let allSelected = true;
                    let correctCount = 0;

                    selectElements.forEach(item => {
                        if (item.select.value === '') {
                            allSelected = false;
                        }
                        if (item.select.value === item.correct) {
                            correctCount++;
                        }
                    });

                    if (!allSelected) {
                        alert('Por favor selecciona una respuesta para cada espacio en blanco.');
                        return;
                    }

                    const isAllCorrect = (correctCount === selectElements.length);
                    const rawBlanks = {};
                    selectElements.forEach(item => { rawBlanks[item.blankId] = item.select.value; });
                    onSubmit({
                        isCorrect: isAllCorrect,
                        selectedText: selectElements.map(item => item.select.value).join(', '),
                        raw: { blanks: rawBlanks }
                    });
                };
                container.appendChild(confirmBtn);
            }
        });
        
        // 9. DESAFÍO DE ALGORITMO O CÓDIGO - Con reordenamiento Drag & Drop y botones de flechas
        this.register('code_challenge', {
            render(challenge, container, projectName, onSubmit) {
                const instructions = document.createElement('p');
                instructions.style.fontSize = '0.9rem';
                instructions.style.color = 'var(--text-secondary)';
                instructions.style.marginBottom = '15px';
                instructions.innerText = 'Ordena las líneas de código arrastrándolas o utilizando las flechas para colocarlas en la secuencia correcta.';
                container.appendChild(instructions);

                const codeList = document.createElement('div');
                codeList.style.fontFamily = 'Courier New, Courier, monospace';
                codeList.style.background = '#05070a';
                codeList.style.border = '1px solid rgba(255,255,255,0.06)';
                codeList.style.borderRadius = '8px';
                codeList.style.padding = '12px';
                codeList.style.display = 'flex';
                codeList.style.flexDirection = 'column';
                codeList.style.gap = '8px';
                codeList.style.marginBottom = '20px';

                let currentLines = shuffle(challenge.lines || []);
                if (JSON.stringify(currentLines) === JSON.stringify(challenge.lines)) {
                    currentLines = shuffle(currentLines);
                }

                let draggedIdx = null;

                const renderLines = () => {
                    codeList.innerHTML = '';
                    currentLines.forEach((lineText, idx) => {
                        const row = document.createElement('div');
                        row.style.background = 'rgba(255,255,255,0.02)';
                        row.style.border = '1px solid rgba(255,255,255,0.04)';
                        row.style.padding = '8px 12px';
                        row.style.borderRadius = '6px';
                        row.style.display = 'flex';
                        row.style.justifyContent = 'space-between';
                        row.style.alignItems = 'center';
                        row.style.fontSize = '0.85rem';
                        row.style.cursor = 'grab';
                        row.setAttribute('draggable', 'true');

                        const codeSpan = document.createElement('span');
                        codeSpan.style.color = '#38bdf8';
                        codeSpan.innerText = lineText;
                        codeSpan.style.pointerEvents = 'none';
                        row.appendChild(codeSpan);

                        // Eventos Drag & Drop
                        row.addEventListener('dragstart', (e) => {
                            draggedIdx = idx;
                            row.classList.add('dragging');
                            e.dataTransfer.effectAllowed = 'move';
                        });

                        row.addEventListener('dragend', () => {
                            row.classList.remove('dragging');
                            codeList.querySelectorAll('div').forEach(r => r.classList.remove('drag-over'));
                        });

                        row.addEventListener('dragover', (e) => {
                            e.preventDefault();
                        });

                        row.addEventListener('dragenter', (e) => {
                            e.preventDefault();
                            if (draggedIdx !== idx) {
                                row.classList.add('drag-over');
                            }
                        });

                        row.addEventListener('dragleave', () => {
                            row.classList.remove('drag-over');
                        });

                        row.addEventListener('drop', (e) => {
                            e.preventDefault();
                            row.classList.remove('drag-over');
                            const targetIdx = idx;
                            if (draggedIdx !== null && draggedIdx !== targetIdx) {
                                const movedLine = currentLines[draggedIdx];
                                currentLines.splice(draggedIdx, 1);
                                currentLines.splice(targetIdx, 0, movedLine);
                                renderLines();
                            }
                        });

                        const controls = document.createElement('div');
                        controls.style.display = 'flex';
                        controls.style.gap = '4px';

                        const upBtn = document.createElement('button');
                        upBtn.type = 'button';
                        upBtn.className = 'btn btn-secondary';
                        upBtn.style.padding = '2px 6px';
                        upBtn.innerHTML = '🔼';
                        upBtn.disabled = idx === 0;
                        upBtn.onclick = (e) => {
                            e.stopPropagation();
                            const temp = currentLines[idx];
                            currentLines[idx] = currentLines[idx - 1];
                            currentLines[idx - 1] = temp;
                            renderLines();
                        };

                        const downBtn = document.createElement('button');
                        downBtn.type = 'button';
                        downBtn.className = 'btn btn-secondary';
                        downBtn.style.padding = '2px 6px';
                        downBtn.innerHTML = '🔽';
                        downBtn.disabled = idx === currentLines.length - 1;
                        downBtn.onclick = (e) => {
                            e.stopPropagation();
                            const temp = currentLines[idx];
                            currentLines[idx] = currentLines[idx + 1];
                            currentLines[idx + 1] = temp;
                            renderLines();
                        };

                        controls.appendChild(upBtn);
                        controls.appendChild(downBtn);
                        row.appendChild(controls);
                        codeList.appendChild(row);
                    });
                };

                renderLines();
                container.appendChild(codeList);

                // Botón de confirmación
                const confirmBtn = document.createElement('button');
                confirmBtn.type = 'button';
                confirmBtn.className = 'btn btn-primary';
                confirmBtn.style.width = '100%';
                confirmBtn.innerText = 'Ejecutar Algoritmo';
                confirmBtn.onclick = () => {
                    const isCorrect = JSON.stringify(currentLines) === JSON.stringify(challenge.answer?.lines || challenge.lines);
                    onSubmit({
                        isCorrect: isCorrect,
                        selectedText: currentLines.join('; '),
                        raw: { lines: [...currentLines] }
                    });
                };
                container.appendChild(confirmBtn);
            }
        });
    }
}
