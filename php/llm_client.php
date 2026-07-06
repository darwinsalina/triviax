<?php
/**
 * TRIVIAX+ — Cliente LLM genérico (Épica 4).
 *
 * Implementa la interfaz prevista en php/lotto_ai.php: se configura en
 * triviax.env (fuera del webroot) con:
 *
 *   LLM_PROVIDER=anthropic | gemini
 *   LLM_API_KEY=…
 *   LLM_MODEL=…            (opcional; hay defaults razonables)
 *
 * Sin proveedor configurado, triviax_llm_available() es false y ninguna
 * función hace llamadas externas (regla del proyecto: nada de Internet salvo
 * herramientas docentes explícitas).
 */

require_once __DIR__ . '/auth.php';

function triviax_llm_available(): bool {
    return _triviax_env('LLM_PROVIDER', '') !== '' && _triviax_env('LLM_API_KEY', '') !== '';
}

/**
 * Genera texto con el proveedor configurado.
 * @throws RuntimeException si no hay proveedor o la llamada falla.
 */
function triviax_llm_generate(string $prompt, int $maxTokens = 4096): string {
    $provider = strtolower(_triviax_env('LLM_PROVIDER', ''));
    $apiKey   = _triviax_env('LLM_API_KEY', '');
    if ($provider === '' || $apiKey === '') {
        throw new RuntimeException('No hay un proveedor de IA configurado (LLM_PROVIDER / LLM_API_KEY en triviax.env).');
    }
    switch ($provider) {
        case 'anthropic':
        case 'claude':
            return _triviax_llm_anthropic($prompt, $apiKey, _triviax_env('LLM_MODEL', 'claude-sonnet-5'), $maxTokens);
        case 'gemini':
        case 'google':
            return _triviax_llm_gemini($prompt, $apiKey, _triviax_env('LLM_MODEL', 'gemini-2.0-flash'), $maxTokens);
        default:
            throw new RuntimeException("Proveedor LLM desconocido: {$provider}.");
    }
}

function _triviax_llm_anthropic(string $prompt, string $apiKey, string $model, int $maxTokens): string {
    $payload = json_encode([
        'model' => $model,
        'max_tokens' => $maxTokens,
        'messages' => [['role' => 'user', 'content' => $prompt]],
    ], JSON_UNESCAPED_UNICODE);
    $respuesta = _triviax_llm_post('https://api.anthropic.com/v1/messages', $payload, [
        'Content-Type: application/json',
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01',
    ]);
    $texto = '';
    foreach (($respuesta['content'] ?? []) as $bloque) {
        if (($bloque['type'] ?? '') === 'text') {
            $texto .= $bloque['text'];
        }
    }
    if ($texto === '') {
        throw new RuntimeException('La API de Anthropic no devolvió texto.');
    }
    return $texto;
}

function _triviax_llm_gemini(string $prompt, string $apiKey, string $model, int $maxTokens): string {
    $payload = json_encode([
        'contents' => [['parts' => [['text' => $prompt]]]],
        'generationConfig' => ['maxOutputTokens' => $maxTokens],
    ], JSON_UNESCAPED_UNICODE);
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
        . rawurlencode($model) . ':generateContent?key=' . rawurlencode($apiKey);
    $respuesta = _triviax_llm_post($url, $payload, ['Content-Type: application/json']);
    $texto = (string)($respuesta['candidates'][0]['content']['parts'][0]['text'] ?? '');
    if ($texto === '') {
        throw new RuntimeException('La API de Gemini no devolvió texto.');
    }
    return $texto;
}

function _triviax_llm_post(string $url, string $payload, array $headers): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_CONNECTTIMEOUT => 15,
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($body === false) {
        throw new RuntimeException('No se pudo contactar al proveedor de IA: ' . $err);
    }
    $json = json_decode((string)$body, true);
    if ($code < 200 || $code >= 300) {
        $detalle = is_array($json) ? (string)($json['error']['message'] ?? $body) : (string)$body;
        throw new RuntimeException("El proveedor de IA respondió HTTP {$code}: " . mb_substr($detalle, 0, 300));
    }
    if (!is_array($json)) {
        throw new RuntimeException('El proveedor de IA devolvió una respuesta no válida.');
    }
    return $json;
}

/**
 * Extrae el primer objeto JSON de una respuesta de LLM que puede venir con
 * texto extra o vallas markdown (```json … ```).
 */
function triviax_llm_extract_json(string $texto): ?array {
    $texto = trim($texto);
    $texto = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $texto);
    $inicio = strpos($texto, '{');
    $fin    = strrpos($texto, '}');
    if ($inicio === false || $fin === false || $fin <= $inicio) {
        return null;
    }
    $json = json_decode(substr($texto, $inicio, $fin - $inicio + 1), true);
    return is_array($json) ? $json : null;
}
