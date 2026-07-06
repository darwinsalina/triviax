-- TRIVIAX 6.7 - Metajuego: perfiles de estudiante (XP, monedas, rachas),
-- tienda de cosméticos e inventario (TRIVIAX+ v8.0, Épica 1).
-- Ejecutar en phpMyAdmin sobre la base triviax.

CREATE TABLE IF NOT EXISTS estudiante_perfiles (
    usuario_id INT UNSIGNED PRIMARY KEY,
    xp INT NOT NULL DEFAULT 0,
    monedas INT NOT NULL DEFAULT 0,
    racha_dias INT NOT NULL DEFAULT 0,
    ultimo_acceso DATE NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_estudiante_perfiles_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS item_tienda (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    tipo ENUM('avatar','ficha_tablero','borde') NOT NULL,
    costo INT NOT NULL DEFAULT 0,
    url_asset VARCHAR(255) NOT NULL DEFAULT '',
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_item_tienda_activo (activo, tipo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS estudiante_inventario (
    usuario_id INT UNSIGNED NOT NULL,
    item_id INT NOT NULL,
    equipado TINYINT(1) NOT NULL DEFAULT 0,
    adquirido_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (usuario_id, item_id),
    CONSTRAINT fk_estudiante_inventario_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    CONSTRAINT fk_estudiante_inventario_item FOREIGN KEY (item_id) REFERENCES item_tienda(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Catálogo inicial de cosméticos (idempotente: solo inserta si la tienda está vacía).
INSERT INTO item_tienda (nombre, tipo, costo, url_asset)
SELECT * FROM (
    SELECT 'Borde dorado' AS nombre, 'borde' AS tipo, 150 AS costo, 'images/tienda/borde_dorado.svg' AS url_asset UNION ALL
    SELECT 'Borde arcoíris', 'borde', 300, 'images/tienda/borde_arcoiris.svg' UNION ALL
    SELECT 'Avatar búho sabio', 'avatar', 200, 'images/tienda/avatar_buho.svg' UNION ALL
    SELECT 'Avatar cohete', 'avatar', 200, 'images/tienda/avatar_cohete.svg' UNION ALL
    SELECT 'Ficha estrella', 'ficha_tablero', 250, 'images/tienda/ficha_estrella.svg' UNION ALL
    SELECT 'Ficha trébol', 'ficha_tablero', 250, 'images/tienda/ficha_trebol.svg'
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM item_tienda LIMIT 1);
