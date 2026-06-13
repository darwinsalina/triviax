/**
 * Aplica el wordmark oficial de TRIVIAX a textos visibles ya renderizados
 * y a contenido que se agregue dinamicamente despues de cargar la pagina.
 */
(function () {
    'use strict';

    const BRAND_PATTERN = /TRIVIAX/gi;
    const SKIP_TAGS = new Set([
        'SCRIPT',
        'STYLE',
        'TEXTAREA',
        'INPUT',
        'SELECT',
        'OPTION',
        'CODE',
        'PRE',
        'TITLE',
        'NOSCRIPT',
        'SVG'
    ]);

    function shouldSkipElement(element) {
        if (!element || element.nodeType !== Node.ELEMENT_NODE) {
            return false;
        }

        if (SKIP_TAGS.has(element.tagName)) {
            return true;
        }

        return Boolean(element.closest('.triviax-logo, .triviax-wordmark'));
    }

    function createWordmark() {
        const wrapper = document.createElement('span');
        wrapper.className = 'triviax-wordmark';
        wrapper.setAttribute('aria-label', 'TRIVIAX');

        const main = document.createElement('span');
        main.className = 'triviax-wordmark__main';
        main.setAttribute('aria-hidden', 'true');
        main.textContent = 'TRIVIA';

        const x = document.createElement('span');
        x.className = 'triviax-wordmark__x';
        x.setAttribute('aria-hidden', 'true');
        x.textContent = 'X';

        wrapper.append(main, x);
        return wrapper;
    }

    function replaceTextNode(node) {
        const text = node.nodeValue;
        if (!text || !BRAND_PATTERN.test(text) || shouldSkipElement(node.parentElement)) {
            BRAND_PATTERN.lastIndex = 0;
            return;
        }
        BRAND_PATTERN.lastIndex = 0;

        const fragment = document.createDocumentFragment();
        let cursor = 0;
        let match;

        while ((match = BRAND_PATTERN.exec(text)) !== null) {
            if (match.index > cursor) {
                fragment.append(document.createTextNode(text.slice(cursor, match.index)));
            }

            fragment.append(createWordmark());
            cursor = match.index + match[0].length;
        }

        if (cursor < text.length) {
            fragment.append(document.createTextNode(text.slice(cursor)));
        }

        node.parentNode.replaceChild(fragment, node);
    }

    function enhanceBrand(root) {
        const scope = root && root.nodeType ? root : document.body;
        if (!scope || shouldSkipElement(scope)) {
            return;
        }

        if (scope.nodeType === Node.TEXT_NODE) {
            replaceTextNode(scope);
            return;
        }

        const walker = document.createTreeWalker(
            scope,
            NodeFilter.SHOW_TEXT,
            {
                acceptNode(node) {
                    if (!node.nodeValue || !BRAND_PATTERN.test(node.nodeValue)) {
                        BRAND_PATTERN.lastIndex = 0;
                        return NodeFilter.FILTER_REJECT;
                    }
                    BRAND_PATTERN.lastIndex = 0;
                    return shouldSkipElement(node.parentElement)
                        ? NodeFilter.FILTER_REJECT
                        : NodeFilter.FILTER_ACCEPT;
                }
            }
        );

        const nodes = [];
        while (walker.nextNode()) {
            nodes.push(walker.currentNode);
        }
        nodes.forEach(replaceTextNode);
    }

    window.triviaxEnhanceBrand = enhanceBrand;

    function init() {
        enhanceBrand(document.body);

        const observer = new MutationObserver((mutations) => {
            mutations.forEach((mutation) => {
                mutation.addedNodes.forEach((node) => enhanceBrand(node));
            });
        });

        observer.observe(document.body, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
})();
