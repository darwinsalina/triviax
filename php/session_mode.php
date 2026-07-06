<?php
/**
 * TRIVIAX+ — Modo de sincronía de sesiones (Épica 2: Modo Tarea).
 *
 * Helpers puros (testeables sin BD) sobre la fila de `sesiones`.
 * Antes de la migración 6.8 las columnas no existen: toda función debe
 * comportarse como modo síncrono clásico si faltan.
 */

/** true si la sesión es una tarea asíncrona (sin turnos en vivo). */
function triviax_sesion_es_asincrona(array $sesion): bool {
    return ($sesion['modalidad_sincronia'] ?? 'sincrono') === 'asincrono_tarea';
}

/**
 * true si la sesión es una tarea con fecha límite ya vencida.
 * $ahora permite inyectar el reloj en pruebas ('Y-m-d H:i:s').
 */
function triviax_sesion_tarea_vencida(array $sesion, ?string $ahora = null): bool {
    if (!triviax_sesion_es_asincrona($sesion)) {
        return false;
    }
    $limite = (string)($sesion['fecha_limite_tarea'] ?? '');
    if ($limite === '') {
        return false; // sin fecha límite: la tarea no vence
    }
    $ahora = $ahora ?: date('Y-m-d H:i:s');
    return strtotime($ahora) > strtotime($limite);
}
