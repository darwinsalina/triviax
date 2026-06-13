<?php
/**
 * TRIVIAX — Adaptador de IA para la modalidad "TRIVIAX Lotto" (lotto_oral).
 *
 * ESTADO v1: sin proveedor LLM configurado. La generación con IA se hace
 * mediante el asistente del panel docente, que genera un prompt para que
 * el docente lo use en su chatbot y pegue el JSON resultante (mismo patrón
 * que "Estudia y responde").
 *
 * Este adaptador deja preparada la interfaz para que, en una versión futura,
 * la aplicación llame directamente a una API de LLM (p. ej. la API de Claude)
 * sin tocar el resto del código:
 *
 *   1. Definir en triviax.env:  LLM_PROVIDER, LLM_API_KEY, LLM_MODEL
 *   2. Implementar _lotto_ai_call_provider() con la llamada HTTP.
 *   3. triviax_lotto_ai_generate() ya valida el resultado con el validador
 *      oficial antes de devolverlo.
 *
 * Nada de este archivo hace llamadas externas mientras no haya proveedor.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lotto_validator.php';

/** ¿Hay un proveedor LLM configurado para generación directa? */
function triviax_lotto_ai_available(): bool {
    return _triviax_env('LLM_PROVIDER', '') !== '' && _triviax_env('LLM_API_KEY', '') !== '';
}

/**
 * Genera una actividad Lotto a partir del documento fuente usando el
 * proveedor LLM configurado. El resultado SIEMPRE pasa por el validador.
 *
 * @throws RuntimeException si no hay proveedor o la generación falla.
 */
function triviax_lotto_ai_generate(string $sourceText, array $students, array $settings): array {
    if (!triviax_lotto_ai_available()) {
        throw new RuntimeException('No hay un proveedor de IA configurado. Usa el asistente del panel: genera un prompt para tu chatbot y pega el JSON resultante.');
    }
    $raw = _lotto_ai_call_provider($sourceText, $students, $settings);
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        throw new RuntimeException('La IA no devolvió un JSON válido.');
    }
    $validation = triviax_lotto_validate_payload($payload);
    if (!$validation['ok']) {
        throw new RuntimeException('El contenido generado por la IA tiene errores: ' . implode(' | ', $validation['errors']));
    }
    return $payload;
}

/**
 * Punto de integración futuro con el proveedor LLM.
 * Implementar aquí la llamada HTTP cuando se configure LLM_PROVIDER.
 */
function _lotto_ai_call_provider(string $sourceText, array $students, array $settings): string {
    throw new RuntimeException('Proveedor LLM no implementado todavía.');
}
