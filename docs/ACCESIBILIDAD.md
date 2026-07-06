# TRIVIAX+ Accesibilidad Universal — Inclusión DUA Nativa (Épica 5, v7.5)

Capa de accesibilidad del cliente para los desafíos interactivos del tablero:
lectura en voz alta (TTS), navegación completa por teclado y semántica ARIA.
Sin cambios en base de datos ni backend.

## 1. Motor de síntesis de voz (`js/services/ttsService.js`)

`TtsService` envuelve la Web Speech API (`window.speechSynthesis`):

- `speak(text, opts)` / `stop()` / `isSpeaking()` — lectura con voz en
  español (prioriza voces locales `es-*`), velocidad 0.95.
- `isSupported()` / `isEnabled()` / `setEnabled()` — degrada en silencio si
  el navegador no soporta síntesis; preferencia persistida en
  `localStorage` (`triviax_tts_enabled`).
- `challengeText(prompt, options)` — compone el texto hablado: enunciado +
  «Opción A: …», «Opción B: …».
- `createSpeakButton(getText)` — botón flotante 🔊 accesible
  (`aria-label`, indicador visual pulsante mientras lee, toggle
  reproducir/detener). Devuelve `null` sin soporte TTS.

## 2. Inyección en los renderizadores (`js/ui.js`)

En lugar de tocar cada renderer de `js/activityRenderers/`, la inyección se
hace en el punto común `UIManager.showQuestionModal` →
`_setupModalAccessibility()`, por lo que **cubre todos los tipos de desafío**
(opción múltiple, verdadero/falso, completar espacios, secuencias,
emparejar, drag & drop, etc.):

- Botón 🔊 junto al enunciado que lee la consigna y las opciones visibles del
  desafío actual (se recalculan al momento del click).
- La lectura se corta al responder, al agotarse el tiempo y al cerrar el
  modal.

## 3. Navegación por teclado y ARIA

- **Flechas ↑↓←→**: recorren cíclicamente los controles del desafío
  (botones, cards arrastrables, selects…). En campos de texto/select las
  flechas conservan su función nativa.
- **Home/End**: primer/último control. **Enter/Space**: activa el control
  enfocado (nativo en botones; replicado con `click()` en cards con
  `tabindex="0"` inyectado).
- **Gestión de foco**: al abrir el modal, foco en el primer control; al
  responder, foco automático en «Continuar» (flujo completo sin mouse).
- **ARIA**: `aria-live="assertive"` en el panel de feedback,
  `role="group"` + `aria-label` en el contenedor de opciones,
  `aria-live="polite"` en el registro de juego (`#game-logs`), y
  `:focus-visible` de alto contraste en todas las opciones.
- `prefers-reduced-motion` respetado en las animaciones del botón 🔊.

## 4. Verificación

- `node --check` sobre los módulos y suite completa `tests/run.php`
  (13 suites / 0 FAIL, sin regresiones).
- Verificación en navegador con partida real:
  - Botón 🔊 presente con `aria-label`, síntesis activa al pulsarlo e
    indicador visual (probado en opción múltiple y completar espacios).
  - Flechas mueven el foco entre opciones (↓↓↑ y End verificados), Enter
    responde, y al resolverse el foco salta a «Continuar».
  - `aria-live` verificado en feedback y registro.

## 5. Despliegue

Subir por FTPS: `js/services/ttsService.js`, `js/ui.js`, `index.html`,
`js/config.js`, `service-worker.js` y docs. Sin migración SQL.
