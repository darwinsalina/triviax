<?php
/**
 * TRIVIAX+ — Motor del metajuego (Épica 1: XP, monedas, rachas y tienda).
 *
 * Filosofía: el metajuego NUNCA puede romper el flujo de juego. Toda función
 * pública falla en silencio (devuelve null) si la migración 6.7 no está
 * aplicada o si la BD tiene problemas; el llamador simplemente omite el bloque
 * `metagame` de la respuesta.
 *
 * Reglas de economía (funciones puras, testeables sin BD):
 *   - Nivel:   nivel = floor(sqrt(xp / 100)) + 1  (100 XP → nivel 2, 400 → 3…)
 *   - Racha:   días consecutivos con actividad. Multiplica la XP ganada:
 *              x1.0 el día 1, +0.1 por día extra, tope x1.6 (racha ≥ 7).
 *   - XP:      respuestas correctas: max(points, 10) · multiplicador.
 *              incorrectas/timeout: 2 XP fijos por participar (sin multiplicador).
 *   - Monedas: floor(XP ganada / 5) + 2 extra si fue correcta.
 */

/** Nivel correspondiente a una cantidad de XP acumulada. */
function triviax_metagame_level(int $xp): int {
    if ($xp <= 0) {
        return 1;
    }
    return (int)floor(sqrt($xp / 100)) + 1;
}

/** XP total necesaria para alcanzar un nivel dado (inversa de level). */
function triviax_metagame_xp_for_level(int $level): int {
    if ($level <= 1) {
        return 0;
    }
    return (int)(($level - 1) * ($level - 1) * 100);
}

/** Multiplicador de XP por racha de días consecutivos (x1.0 … x1.6). */
function triviax_metagame_streak_multiplier(int $rachaDias): float {
    $extra = max(0, min($rachaDias - 1, 6));
    return round(1.0 + $extra * 0.1, 1);
}

/**
 * Calcula la racha resultante según la fecha del último acceso registrado.
 * Devuelve la cantidad de días de racha que corresponde HOY.
 */
function triviax_metagame_next_streak(?string $ultimoAcceso, int $rachaActual, ?string $hoy = null): int {
    $hoy = $hoy ?: date('Y-m-d');
    if ($ultimoAcceso === $hoy) {
        return max(1, $rachaActual); // ya jugó hoy: la racha no cambia
    }
    $ayer = date('Y-m-d', strtotime($hoy . ' -1 day'));
    if ($ultimoAcceso === $ayer) {
        return max(1, $rachaActual) + 1; // día consecutivo
    }
    return 1; // racha rota (o primer día)
}

/**
 * Recompensa pura (sin BD) para un intento. Devuelve
 * ['xp' => int, 'monedas' => int, 'multiplicador' => float].
 */
function triviax_metagame_calc_rewards(int $points, string $resultado, int $rachaDias): array {
    if ($resultado === 'correct') {
        $mult = triviax_metagame_streak_multiplier($rachaDias);
        $xp   = (int)round(max($points, 10) * $mult);
        return ['xp' => $xp, 'monedas' => intdiv($xp, 5) + 2, 'multiplicador' => $mult];
    }
    // Participación (incorrect/timeout): premio simbólico fijo.
    return ['xp' => 2, 'monedas' => 0, 'multiplicador' => 1.0];
}

/** true si las tablas del metajuego existen (migración 6.7 aplicada). */
function triviax_metagame_available(PDO $pdo): bool {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    try {
        $pdo->query('SELECT 1 FROM estudiante_perfiles LIMIT 1');
        $cache = true;
    } catch (Throwable $e) {
        $cache = false;
    }
    return $cache;
}

/**
 * Registra la recompensa de un intento para un estudiante autenticado.
 * Actualiza XP, monedas y racha en estudiante_perfiles y devuelve el resumen
 * para el cliente, o null si el metajuego no aplica (sin tablas, sin usuario).
 *
 * NO abre transacción propia: puede ejecutarse dentro de la transacción del
 * llamador (submit_answer). Cualquier error se traga y devuelve null.
 */
function triviax_metagame_add_rewards(PDO $pdo, int $usuarioId, int $points, string $resultado): ?array {
    if ($usuarioId <= 0 || !triviax_metagame_available($pdo)) {
        return null;
    }
    try {
        $pdo->prepare('INSERT IGNORE INTO estudiante_perfiles (usuario_id) VALUES (?)')->execute([$usuarioId]);
        $stmt = $pdo->prepare('SELECT xp, monedas, racha_dias, ultimo_acceso FROM estudiante_perfiles WHERE usuario_id = ? FOR UPDATE');
        $stmt->execute([$usuarioId]);
        $perfil = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$perfil) {
            return null;
        }

        $hoy      = date('Y-m-d');
        $racha    = triviax_metagame_next_streak($perfil['ultimo_acceso'], (int)$perfil['racha_dias'], $hoy);
        $reward   = triviax_metagame_calc_rewards($points, $resultado, $racha);
        $xpAntes  = (int)$perfil['xp'];
        $xpAhora  = $xpAntes + $reward['xp'];
        $monedas  = (int)$perfil['monedas'] + $reward['monedas'];

        $pdo->prepare('UPDATE estudiante_perfiles SET xp = ?, monedas = ?, racha_dias = ?, ultimo_acceso = ? WHERE usuario_id = ?')
            ->execute([$xpAhora, $monedas, $racha, $hoy, $usuarioId]);

        $nivelAntes = triviax_metagame_level($xpAntes);
        $nivelAhora = triviax_metagame_level($xpAhora);
        return [
            'xp_ganada'        => $reward['xp'],
            'monedas_ganadas'  => $reward['monedas'],
            'multiplicador'    => $reward['multiplicador'],
            'xp_total'         => $xpAhora,
            'monedas_total'    => $monedas,
            'racha_dias'       => $racha,
            'nivel'            => $nivelAhora,
            'subio_nivel'      => $nivelAhora > $nivelAntes,
            'xp_siguiente_nivel' => triviax_metagame_xp_for_level($nivelAhora + 1),
        ];
    } catch (Throwable $e) {
        return null;
    }
}

/** Perfil completo del estudiante (para action=metagame_profile). */
function triviax_metagame_profile(PDO $pdo, int $usuarioId): ?array {
    if ($usuarioId <= 0 || !triviax_metagame_available($pdo)) {
        return null;
    }
    try {
        $stmt = $pdo->prepare('SELECT xp, monedas, racha_dias, ultimo_acceso FROM estudiante_perfiles WHERE usuario_id = ?');
        $stmt->execute([$usuarioId]);
        $perfil = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['xp' => 0, 'monedas' => 0, 'racha_dias' => 0, 'ultimo_acceso' => null];

        $stmtInv = $pdo->prepare(
            'SELECT i.item_id, t.nombre, t.tipo, t.url_asset, i.equipado
               FROM estudiante_inventario i JOIN item_tienda t ON t.id = i.item_id
              WHERE i.usuario_id = ? ORDER BY t.tipo, t.nombre'
        );
        $stmtInv->execute([$usuarioId]);

        $xp    = (int)$perfil['xp'];
        $nivel = triviax_metagame_level($xp);
        return [
            'xp'                 => $xp,
            'monedas'            => (int)$perfil['monedas'],
            'racha_dias'         => (int)$perfil['racha_dias'],
            'ultimo_acceso'      => $perfil['ultimo_acceso'],
            'nivel'              => $nivel,
            'xp_nivel_actual'    => triviax_metagame_xp_for_level($nivel),
            'xp_siguiente_nivel' => triviax_metagame_xp_for_level($nivel + 1),
            'multiplicador'      => triviax_metagame_streak_multiplier((int)$perfil['racha_dias']),
            'inventario'         => $stmtInv->fetchAll(PDO::FETCH_ASSOC),
        ];
    } catch (Throwable $e) {
        return null;
    }
}

/** Catálogo de la tienda; marca posesión/equipado si se pasa un usuario. */
function triviax_metagame_shop_items(PDO $pdo, int $usuarioId = 0): ?array {
    if (!triviax_metagame_available($pdo)) {
        return null;
    }
    try {
        $stmt = $pdo->prepare(
            'SELECT t.id, t.nombre, t.tipo, t.costo, t.url_asset,
                    (i.usuario_id IS NOT NULL) AS adquirido,
                    COALESCE(i.equipado, 0) AS equipado
               FROM item_tienda t
               LEFT JOIN estudiante_inventario i ON i.item_id = t.id AND i.usuario_id = ?
              WHERE t.activo = 1
              ORDER BY t.tipo, t.costo, t.nombre'
        );
        $stmt->execute([$usuarioId]);
        return array_map(static function (array $row): array {
            $row['id']        = (int)$row['id'];
            $row['costo']     = (int)$row['costo'];
            $row['adquirido'] = (bool)$row['adquirido'];
            $row['equipado']  = (bool)$row['equipado'];
            return $row;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Compra un ítem de la tienda descontando monedas de forma atómica.
 * Devuelve ['ok' => bool, 'error' => code|null, 'monedas_total' => int|null].
 */
function triviax_metagame_buy(PDO $pdo, int $usuarioId, int $itemId): array {
    if ($usuarioId <= 0 || !triviax_metagame_available($pdo)) {
        return ['ok' => false, 'error' => 'NO_DISPONIBLE'];
    }
    $ownTx = !$pdo->inTransaction();
    try {
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        $stmtItem = $pdo->prepare('SELECT id, costo FROM item_tienda WHERE id = ? AND activo = 1');
        $stmtItem->execute([$itemId]);
        $item = $stmtItem->fetch(PDO::FETCH_ASSOC);
        if (!$item) {
            if ($ownTx) { $pdo->rollBack(); }
            return ['ok' => false, 'error' => 'ITEM_NO_EXISTE'];
        }
        $pdo->prepare('INSERT IGNORE INTO estudiante_perfiles (usuario_id) VALUES (?)')->execute([$usuarioId]);
        $stmtPerfil = $pdo->prepare('SELECT monedas FROM estudiante_perfiles WHERE usuario_id = ? FOR UPDATE');
        $stmtPerfil->execute([$usuarioId]);
        $monedas = (int)$stmtPerfil->fetchColumn();

        $stmtOwned = $pdo->prepare('SELECT 1 FROM estudiante_inventario WHERE usuario_id = ? AND item_id = ?');
        $stmtOwned->execute([$usuarioId, $itemId]);
        if ($stmtOwned->fetch()) {
            if ($ownTx) { $pdo->rollBack(); }
            return ['ok' => false, 'error' => 'YA_ADQUIRIDO'];
        }
        if ($monedas < (int)$item['costo']) {
            if ($ownTx) { $pdo->rollBack(); }
            return ['ok' => false, 'error' => 'MONEDAS_INSUFICIENTES'];
        }
        $pdo->prepare('UPDATE estudiante_perfiles SET monedas = monedas - ? WHERE usuario_id = ?')
            ->execute([(int)$item['costo'], $usuarioId]);
        $pdo->prepare('INSERT INTO estudiante_inventario (usuario_id, item_id) VALUES (?, ?)')
            ->execute([$usuarioId, $itemId]);
        if ($ownTx) {
            $pdo->commit();
        }
        return ['ok' => true, 'error' => null, 'monedas_total' => $monedas - (int)$item['costo']];
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'error' => 'SERVER_ERROR'];
    }
}

/**
 * Equipa un ítem del inventario (desequipa cualquier otro del mismo tipo).
 * Con $equipar=false solo lo desequipa.
 */
function triviax_metagame_equip(PDO $pdo, int $usuarioId, int $itemId, bool $equipar = true): array {
    if ($usuarioId <= 0 || !triviax_metagame_available($pdo)) {
        return ['ok' => false, 'error' => 'NO_DISPONIBLE'];
    }
    $ownTx = !$pdo->inTransaction();
    try {
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        $stmt = $pdo->prepare(
            'SELECT t.tipo FROM estudiante_inventario i JOIN item_tienda t ON t.id = i.item_id
              WHERE i.usuario_id = ? AND i.item_id = ?'
        );
        $stmt->execute([$usuarioId, $itemId]);
        $tipo = $stmt->fetchColumn();
        if ($tipo === false) {
            if ($ownTx) { $pdo->rollBack(); }
            return ['ok' => false, 'error' => 'NO_ADQUIRIDO'];
        }
        if ($equipar) {
            $pdo->prepare(
                'UPDATE estudiante_inventario i JOIN item_tienda t ON t.id = i.item_id
                    SET i.equipado = 0 WHERE i.usuario_id = ? AND t.tipo = ?'
            )->execute([$usuarioId, $tipo]);
        }
        $pdo->prepare('UPDATE estudiante_inventario SET equipado = ? WHERE usuario_id = ? AND item_id = ?')
            ->execute([$equipar ? 1 : 0, $usuarioId, $itemId]);
        if ($ownTx) {
            $pdo->commit();
        }
        return ['ok' => true, 'error' => null];
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'error' => 'SERVER_ERROR'];
    }
}
