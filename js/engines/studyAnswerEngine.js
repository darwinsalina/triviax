import { ApiClient } from '../services/apiClient.js';

const qs = (selector, root = document) => root.querySelector(selector);

function el(tag, className = '', text = '') {
    const node = document.createElement(tag);
    if (className) {
        node.className = className;
    }
    if (text !== '') {
        node.textContent = text;
    }
    return node;
}

function clear(node) {
    while (node.firstChild) {
        node.removeChild(node.firstChild);
    }
}

function formatTime(ms) {
    const total = Math.max(0, Math.round((ms || 0) / 1000));
    const min = Math.floor(total / 60);
    const sec = String(total % 60).padStart(2, '0');
    return `${min}:${sec}`;
}

function pendingCount(progress) {
    const st = progress?.by_status || {};
    return (st.new || 0) + (st.learning || 0) + (st.review || 0);
}

function closedCount(progress) {
    const st = progress?.by_status || {};
    return (st.mastered || 0) + (st.failed || 0) + (st.skipped || 0);
}

function makeIdempotencyKey() {
    const random = Math.random().toString(36).slice(2, 12);
    return `study.${Date.now()}.${random}`;
}

class StudyAnswerApp {
    constructor() {
        this.root = qs('[data-study-app]');
        this.catalog = qs('[data-study-catalog]');
        this.workspace = qs('[data-study-workspace]');
        this.status = qs('[data-study-status]');
        this.progressBar = qs('[data-study-progress-bar]');
        this.progressText = qs('[data-study-progress-text]');
        this.metricCards = qs('[data-study-metrics]');
        this.finishButton = qs('[data-study-finish]');
        this.restartButton = qs('[data-study-restart]');

        this.decks = [];
        this.deck = null;
        this.session = null;
        this.currentCard = null;
        this.readStartedAt = 0;
        this.answerStartedAt = 0;
        this.selectedAnswer = null;
    }

    init() {
        const csrf = this.root?.dataset.csrf || window.TRIVIAX_CSRF_TOKEN || '';
        if (csrf) {
            ApiClient.csrfToken = csrf;
        }

        this.finishButton?.addEventListener('click', () => this.finish());
        this.restartButton?.addEventListener('click', () => {
            this.clearStoredSession();
            window.location.href = 'study.php';
        });

        const url = new URL(window.location.href);
        const deckId = url.searchParams.get('deck_id');
        const slug = url.searchParams.get('slug') || '';
        this.loadCatalog().then(() => {
            if (deckId || slug) {
                this.startDeck({ deckId: deckId ? Number(deckId) : null, slug });
            }
        });
    }

    setStatus(message, tone = '') {
        if (!this.status) {
            return;
        }
        this.status.textContent = message;
        this.status.dataset.tone = tone;
    }

    async loadCatalog() {
        try {
            this.setStatus('Cargando mazos publicados...');
            const data = await ApiClient.studyListPublished();
            this.decks = data.decks || [];
            this.renderCatalog();
            this.setStatus(this.decks.length ? 'Elige un mazo para comenzar.' : 'Todavía no hay mazos publicados.', this.decks.length ? '' : 'warn');
        } catch (err) {
            this.setStatus(err.message || 'No se pudo cargar el catálogo.', 'error');
        }
    }

    renderCatalog() {
        clear(this.catalog);
        if (!this.decks.length) {
            const empty = el('div', 'study-empty-state');
            empty.append(el('h2', '', 'Sin mazos publicados'));
            empty.append(el('p', '', 'Cuando un docente publique un mazo, aparecerá acá para practicar.'));
            this.catalog.append(empty);
            return;
        }

        this.decks.forEach((deck) => {
            const card = el('article', 'study-deck-card glass-card');
            const title = el('h2', '', deck.titulo || 'Mazo sin título');
            const desc = el('p', '', deck.descripcion || 'Práctica de lectura breve y respuesta.');
            const meta = el('div', 'study-deck-meta');
            meta.append(el('span', '', `${deck.cards || 0} cartas`));
            if (deck.slug) {
                meta.append(el('span', '', deck.slug));
            }
            const button = el('button', 'btn btn-primary', 'Comenzar');
            button.type = 'button';
            button.addEventListener('click', () => this.startDeck({ deckId: Number(deck.id) }));

            card.append(title, desc, meta, button);
            this.catalog.append(card);
        });
    }

    sessionStorageKey(deckId) {
        return `triviax_study_${deckId || 'slug'}_session`;
    }

    storeSession() {
        if (!this.deck || !this.session) {
            return;
        }
        sessionStorage.setItem(this.sessionStorageKey(this.deck.id), JSON.stringify({
            study_session_id: this.session.study_session_id,
            session_token: this.session.session_token,
            deck: this.deck
        }));
    }

    clearStoredSession() {
        if (this.deck?.id) {
            sessionStorage.removeItem(this.sessionStorageKey(this.deck.id));
        }
    }

    async startDeck({ deckId = null, slug = '' } = {}) {
        try {
            this.setStatus('Iniciando práctica...');
            const data = await ApiClient.studyStart({ deckId, slug });
            this.session = {
                study_session_id: data.study_session_id,
                session_token: data.session_token
            };
            this.deck = data.deck;
            this.storeSession();
            this.catalog.classList.add('study-hidden');
            this.workspace.classList.remove('study-hidden');
            this.restartButton?.classList.remove('study-hidden');
            this.finishButton?.classList.remove('study-hidden');
            this.updateProgress(data.progress);
            await this.loadNextCard();
        } catch (err) {
            this.setStatus(err.message || 'No se pudo iniciar la práctica.', 'error');
        }
    }

    async loadNextCard() {
        try {
            this.setStatus('Preparando la próxima carta...');
            const data = await ApiClient.studyStartCard(this.session.study_session_id, this.session.session_token);
            this.updateProgress(data.progress);
            if (data.deck_complete || !data.card) {
                await this.finish();
                return;
            }
            this.currentCard = data.card;
            this.renderReadStep(data.card);
            this.setStatus('Leé la carta y luego respondé.', '');
        } catch (err) {
            this.setStatus(err.message || 'No se pudo cargar la carta.', 'error');
        }
    }

    updateProgress(progress) {
        if (!progress) {
            return;
        }
        const total = progress.total_cards || 0;
        const closed = closedCount(progress);
        const pct = total > 0 ? Math.round((closed * 100) / total) : 0;
        if (this.progressBar) {
            this.progressBar.style.width = `${pct}%`;
        }
        if (this.progressText) {
            this.progressText.textContent = `${closed} de ${total} cerradas`;
        }
        if (this.metricCards) {
            const st = progress.by_status || {};
            qs('[data-metric="points"]', this.metricCards).textContent = String(progress.points || 0);
            qs('[data-metric="accuracy"]', this.metricCards).textContent = `${progress.accuracy || 0}%`;
            qs('[data-metric="pending"]', this.metricCards).textContent = String(pendingCount(progress));
            qs('[data-metric="mastered"]', this.metricCards).textContent = String(st.mastered || 0);
        }
    }

    renderReadStep(card) {
        clear(this.workspace);
        this.readStartedAt = Date.now();

        const shell = el('article', 'study-card-view glass-card');
        const eyebrow = el('div', 'study-card-eyebrow', `Carta ${card.order || ''} · ${card.difficulty || 'media'} · ${card.points || 0} pts`);
        const title = el('h1', '', card.title || 'Carta de estudio');
        const objective = el('p', 'study-objective', card.learningObjective || '');
        const body = el('p', 'study-text', card.studyText || '');
        shell.append(eyebrow, title);
        if (objective.textContent) {
            shell.append(objective);
        }
        shell.append(body);

        if (card.keyIdea) {
            const key = el('div', 'study-key-idea');
            key.append(el('strong', '', 'Idea clave'));
            key.append(el('span', '', card.keyIdea));
            shell.append(key);
        }

        if (Array.isArray(card.vocabulary) && card.vocabulary.length) {
            const vocab = el('div', 'study-vocabulary');
            vocab.append(el('h2', '', 'Vocabulario'));
            card.vocabulary.forEach((item) => {
                const row = el('div', 'study-vocab-row');
                row.append(el('strong', '', item.term || 'Término'));
                row.append(el('span', '', item.definition || ''));
                vocab.append(row);
            });
            shell.append(vocab);
        }

        const actions = el('div', 'study-actions');
        const answer = el('button', 'btn btn-primary', 'Responder');
        answer.type = 'button';
        answer.addEventListener('click', () => this.renderAnswerStep(card));
        const skip = el('button', 'btn btn-secondary', 'Saltear');
        skip.type = 'button';
        skip.addEventListener('click', () => this.skipCard());
        actions.append(answer, skip);
        shell.append(actions);
        this.workspace.append(shell);
    }

    renderAnswerStep(card) {
        clear(this.workspace);
        this.answerStartedAt = Date.now();
        this.selectedAnswer = null;

        const shell = el('article', 'study-card-view glass-card study-card-view--answer');
        shell.append(el('div', 'study-card-eyebrow', this.labelForType(card.assessment?.type)));
        shell.append(el('h1', '', card.assessment?.prompt || 'Respondé la consigna'));

        const form = el('form', 'study-answer-form');
        const answerArea = el('div', 'study-answer-area');
        this.renderAssessment(card.assessment || {}, answerArea);
        const actions = el('div', 'study-actions');
        const submit = el('button', 'btn btn-primary', 'Enviar respuesta');
        submit.type = 'submit';
        const back = el('button', 'btn btn-secondary', 'Volver a leer');
        back.type = 'button';
        back.addEventListener('click', () => this.renderReadStep(card));
        actions.append(submit, back);
        form.append(answerArea, actions);
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            this.submitCurrentAnswer();
        });

        shell.append(form);
        this.workspace.append(shell);
    }

    labelForType(type) {
        const labels = {
            multiple_choice: 'Opción múltiple',
            true_false: 'Verdadero o falso',
            fill_blank: 'Completar espacio',
            short_answer: 'Respuesta breve',
            matching_pairs: 'Asociar pares',
            classification: 'Clasificar',
            sequence_order: 'Ordenar secuencia'
        };
        return labels[type] || 'Pregunta';
    }

    renderAssessment(assessment, target) {
        switch (assessment.type) {
            case 'multiple_choice':
                this.renderOptions(assessment.options || [], target, 'selectedOption');
                break;
            case 'true_false':
                this.renderOptions(['Verdadero', 'Falso'], target, 'value', [true, false]);
                break;
            case 'fill_blank':
            case 'short_answer':
                this.renderTextAnswer(target);
                break;
            case 'matching_pairs':
                this.renderMatching(assessment, target);
                break;
            case 'classification':
                this.renderClassification(assessment, target);
                break;
            case 'sequence_order':
                this.renderSequence(assessment, target);
                break;
            default:
                target.append(el('p', 'study-warning', 'Este tipo de pregunta todavía no tiene interfaz.'));
        }
    }

    renderOptions(options, target, key, values = null) {
        const list = el('div', 'study-option-grid');
        options.forEach((option, index) => {
            const button = el('button', 'study-option-button', option);
            button.type = 'button';
            button.addEventListener('click', () => {
                list.querySelectorAll('.study-option-button').forEach((n) => n.classList.remove('is-selected'));
                button.classList.add('is-selected');
                this.selectedAnswer = { [key]: values ? values[index] : option };
            });
            list.append(button);
        });
        target.append(list);
    }

    renderTextAnswer(target) {
        const label = el('label', 'study-field-label', 'Tu respuesta');
        const input = el('textarea', 'study-textarea');
        input.rows = 4;
        input.maxLength = 500;
        input.addEventListener('input', () => {
            this.selectedAnswer = { text: input.value };
        });
        label.append(input);
        target.append(label);
        input.focus();
    }

    renderMatching(assessment, target) {
        const rights = assessment.rights || [];
        const rows = el('div', 'study-pair-list');
        (assessment.lefts || []).forEach((left) => {
            const row = el('label', 'study-pair-row');
            row.append(el('span', '', left));
            const select = document.createElement('select');
            select.append(new Option('Elige...', ''));
            rights.forEach((right) => select.append(new Option(right, right)));
            select.addEventListener('change', () => {
                const pairs = Array.from(rows.querySelectorAll('select')).map((node) => ({
                    left: node.dataset.left,
                    right: node.value
                })).filter((p) => p.right !== '');
                this.selectedAnswer = { pairs };
            });
            select.dataset.left = left;
            row.append(select);
            rows.append(row);
        });
        target.append(rows);
    }

    renderClassification(assessment, target) {
        const categories = assessment.categories || [];
        const rows = el('div', 'study-pair-list');
        (assessment.items || []).forEach((item) => {
            const row = el('label', 'study-pair-row');
            row.append(el('span', '', item));
            const select = document.createElement('select');
            select.append(new Option('Elige categoría...', ''));
            categories.forEach((cat) => select.append(new Option(cat.label || cat.id, cat.id)));
            select.addEventListener('change', () => {
                const assignments = Array.from(rows.querySelectorAll('select')).map((node) => ({
                    item: node.dataset.item,
                    categoryId: node.value
                })).filter((p) => p.categoryId !== '');
                this.selectedAnswer = { assignments };
            });
            select.dataset.item = item;
            row.append(select);
            rows.append(row);
        });
        target.append(rows);
    }

    renderSequence(assessment, target) {
        const list = el('ol', 'study-sequence-list');
        const items = [...(assessment.items || [])];
        const rerender = () => {
            clear(list);
            items.forEach((item, index) => {
                const row = el('li', 'study-sequence-row');
                row.append(el('span', '', item));
                const controls = el('div', 'study-sequence-controls');
                const up = el('button', 'study-icon-button', '↑');
                up.type = 'button';
                up.disabled = index === 0;
                up.addEventListener('click', () => {
                    [items[index - 1], items[index]] = [items[index], items[index - 1]];
                    this.selectedAnswer = { order: items };
                    rerender();
                });
                const down = el('button', 'study-icon-button', '↓');
                down.type = 'button';
                down.disabled = index === items.length - 1;
                down.addEventListener('click', () => {
                    [items[index + 1], items[index]] = [items[index], items[index + 1]];
                    this.selectedAnswer = { order: items };
                    rerender();
                });
                controls.append(up, down);
                row.append(controls);
                list.append(row);
            });
        };
        this.selectedAnswer = { order: items };
        rerender();
        target.append(list);
    }

    async submitCurrentAnswer() {
        if (!this.currentCard || !this.selectedAnswer) {
            this.setStatus('Elige o escribe una respuesta antes de enviar.', 'warn');
            return;
        }
        try {
            this.setStatus('Enviando respuesta...');
            const now = Date.now();
            const data = await ApiClient.studySubmitAnswer({
                study_session_id: this.session.study_session_id,
                session_token: this.session.session_token,
                card_id: this.currentCard.card_id,
                answer_payload: this.selectedAnswer,
                time_ms: now - this.answerStartedAt,
                read_time_ms: this.answerStartedAt - this.readStartedAt,
                idempotency_key: makeIdempotencyKey()
            });
            this.updateProgress(data.progress);
            this.renderFeedback(data);
            this.setStatus(data.deck_complete ? 'Mazo completado.' : 'Respuesta registrada.', data.feedback?.is_correct ? 'ok' : 'warn');
        } catch (err) {
            this.setStatus(err.message || 'No se pudo registrar la respuesta.', 'error');
        }
    }

    renderFeedback(data) {
        clear(this.workspace);
        const fb = data.feedback || {};
        const shell = el('article', `study-card-view glass-card study-feedback ${fb.is_correct ? 'is-correct' : 'is-incorrect'}`);
        shell.append(el('div', 'study-card-eyebrow', fb.is_correct ? 'Correcto' : 'A repasar'));
        shell.append(el('h1', '', fb.message || 'Respuesta registrada'));
        if (fb.explanation) {
            shell.append(el('p', 'study-text', fb.explanation));
        }
        const meta = el('div', 'study-feedback-meta');
        meta.append(el('span', '', `Puntos: +${data.points_delta || 0}`));
        meta.append(el('span', '', `Estado: ${data.card_status || 'registrado'}`));
        meta.append(el('span', '', `Tiempo: ${formatTime(Date.now() - this.answerStartedAt)}`));
        shell.append(meta);

        const actions = el('div', 'study-actions');
        if (data.deck_complete) {
            const finish = el('button', 'btn btn-primary', 'Ver resumen');
            finish.type = 'button';
            finish.addEventListener('click', () => this.finish());
            actions.append(finish);
        } else {
            const next = el('button', 'btn btn-primary', data.returns_to_deck ? 'Seguir repasando' : 'Siguiente carta');
            next.type = 'button';
            next.addEventListener('click', () => this.loadNextCard());
            actions.append(next);
        }
        shell.append(actions);
        this.workspace.append(shell);
    }

    async skipCard() {
        if (!this.currentCard) {
            return;
        }
        try {
            this.setStatus('Saltando carta...');
            const data = await ApiClient.studySkipCard(
                this.session.study_session_id,
                this.session.session_token,
                this.currentCard.card_id
            );
            this.updateProgress(data.progress);
            await this.loadNextCard();
        } catch (err) {
            this.setStatus(err.message || 'No se pudo saltear la carta.', 'error');
        }
    }

    async finish() {
        try {
            this.setStatus('Cerrando práctica...');
            const data = await ApiClient.studyFinish(this.session.study_session_id, this.session.session_token);
            this.clearStoredSession();
            this.renderSummary(data.summary || {});
            this.finishButton?.classList.add('study-hidden');
            this.setStatus('Práctica finalizada.', 'ok');
        } catch (err) {
            this.setStatus(err.message || 'No se pudo finalizar la práctica.', 'error');
        }
    }

    renderSummary(summary) {
        this.updateProgress(summary);
        clear(this.workspace);
        const shell = el('article', 'study-card-view glass-card study-summary');
        shell.append(el('div', 'study-card-eyebrow', 'Resumen'));
        shell.append(el('h1', '', 'Práctica completada'));
        const grid = el('div', 'study-summary-grid');
        [
            ['Puntaje', summary.points || 0],
            ['Precisión', `${summary.accuracy || 0}%`],
            ['Intentos', summary.attempts || 0],
            ['Dominadas', summary.by_status?.mastered || 0]
        ].forEach(([label, value]) => {
            const item = el('div', 'study-summary-item');
            item.append(el('strong', '', String(value)));
            item.append(el('span', '', label));
            grid.append(item);
        });
        shell.append(grid);

        if (Array.isArray(summary.hardest_cards) && summary.hardest_cards.length) {
            const hard = el('div', 'study-hard-list');
            hard.append(el('h2', '', 'Para repasar'));
            summary.hardest_cards.forEach((card) => {
                hard.append(el('p', '', `${card.title} (${card.wrong_count} fallos)`));
            });
            shell.append(hard);
        }

        const actions = el('div', 'study-actions');
        const again = el('button', 'btn btn-primary', 'Elegir otro mazo');
        again.type = 'button';
        again.addEventListener('click', () => window.location.href = 'study.php');
        actions.append(again);
        shell.append(actions);
        this.workspace.append(shell);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const app = new StudyAnswerApp();
    app.init();
});
