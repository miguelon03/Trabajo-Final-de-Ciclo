<?php

$dmhAllowedOrigins = [
    "http://localhost:4321",
    "http://localhost:4322",
    "https://dripmode.com"
];

$dmhOrigin = $_SERVER["HTTP_ORIGIN"] ?? "";
if (in_array($dmhOrigin, $dmhAllowedOrigins, true)) {
    header("Access-Control-Allow-Origin: " . $dmhOrigin);
}

header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=utf-8");

if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "OPTIONS") {
    http_response_code(200);
    exit();
}

$host = "localhost";
$usuario = "root";
$contrasena = "";
$basedatos = "dripmode";

function dmh_slugify(string $value): string {
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/i', '-', $value) ?? '';
    $value = trim($value, '-');
    return $value !== '' ? $value : 'sin-categoria';
}

function dmh_column_exists(PDO $conexion, string $basedatos, string $tabla, string $columna): bool {
    $stmt = $conexion->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = :schema
          AND TABLE_NAME = :tabla
          AND COLUMN_NAME = :columna
    ");
    $stmt->execute([
        "schema" => $basedatos,
        "tabla" => $tabla,
        "columna" => $columna,
    ]);
    return (int)$stmt->fetchColumn() > 0;
}

function dmh_index_exists(PDO $conexion, string $basedatos, string $tabla, string $indice): bool {
    $stmt = $conexion->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = :schema
          AND TABLE_NAME = :tabla
          AND INDEX_NAME = :indice
    ");
    $stmt->execute([
        "schema" => $basedatos,
        "tabla" => $tabla,
        "indice" => $indice,
    ]);
    return (int)$stmt->fetchColumn() > 0;
}

function dmh_fk_exists(PDO $conexion, string $basedatos, string $tabla, string $columna, string $tablaReferencia): bool {
    $stmt = $conexion->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = :schema
          AND TABLE_NAME = :tabla
          AND COLUMN_NAME = :columna
          AND REFERENCED_TABLE_NAME = :tabla_referencia
    ");
    $stmt->execute([
        "schema" => $basedatos,
        "tabla" => $tabla,
        "columna" => $columna,
        "tabla_referencia" => $tablaReferencia,
    ]);
    return (int)$stmt->fetchColumn() > 0;
}

function dmh_strip_color_mentions(string $description): string {
    $text = trim($description);
    if ($text === '') {
        return $text;
    }

    $text = preg_replace('/\s+en\s+color\s+[a-z0-9áéíóúüñ\- ]+(?=[,.;])/iu', '', $text) ?? $text;
    $text = preg_replace('/\s+en\s+color\s+[a-z0-9áéíóúüñ\- ]+$/iu', '', $text) ?? $text;
    $text = preg_replace('/\s{2,}/', ' ', $text) ?? $text;
    $text = preg_replace('/\s+([,.;:])/u', '$1', $text) ?? $text;

    return trim($text);
}

function dmh_is_placeholder_image(string $value): bool {
    $v = strtolower(trim($value));
    return $v === '' || str_starts_with($v, 'placeholder');
}

function dmh_build_image_variant(string $path, int $index): string {
    $path = trim($path);
    if ($path === '' || $index <= 0) {
        return $path;
    }

    if (preg_match('/^(.*?)([-_])\d+(\.[a-z0-9]+)$/i', $path, $m)) {
        return $m[1] . $m[2] . $index . $m[3];
    }

    if (preg_match('/^(.*)\(\d+\)(\.[a-z0-9]+)$/i', $path, $m)) {
        return rtrim($m[1]) . ' (' . $index . ')' . $m[2];
    }

    $ext = pathinfo($path, PATHINFO_EXTENSION);
    if ($ext === '') {
        return rtrim($path, '/\\') . '-' . $index;
    }

    $base = substr($path, 0, -strlen($ext) - 1);
    return $base . '-' . $index . '.' . $ext;
}

function dmh_extract_image_position(string $path): ?int {
    $p = trim($path);
    if ($p === '') {
        return null;
    }

    if (preg_match('/\((\d+)\)(\.[a-z0-9]+)?$/i', $p, $m)) {
        return (int)$m[1];
    }

    if (preg_match('/(?:[-_])(\d+)(\.[a-z0-9]+)?$/i', $p, $m)) {
        return (int)$m[1];
    }

    return null;
}

function dmh_resolve_three_images(array $rawImages, ?string $primaryImage): array {
    $images = array_values(array_filter(array_map(
        static fn($img) => trim((string)$img),
        $rawImages
    )));

    if (count($images) === 0 && $primaryImage !== null && trim($primaryImage) !== '') {
        $images[] = trim($primaryImage);
    }

    $images = array_values(array_filter($images, static fn($img) => !dmh_is_placeholder_image((string)$img)));

    if (count($images) === 0) {
        return [];
    }

    usort($images, static function (string $a, string $b): int {
        $pa = dmh_extract_image_position($a);
        $pb = dmh_extract_image_position($b);

        if ($pa === null && $pb === null) return 0;
        if ($pa === null) return 1;
        if ($pb === null) return -1;

        return $pa <=> $pb;
    });

    $first = $images[0];
    $resolved = [];

    for ($i = 0; $i < 3; $i++) {
        if (isset($images[$i]) && trim((string)$images[$i]) !== '') {
            $resolved[] = trim((string)$images[$i]);
        } else {
            $resolved[] = dmh_build_image_variant($first, $i + 1);
        }
    }

    return array_slice($resolved, 0, 3);
}

try {
    $conexion = new PDO(
        "mysql:host=$host;charset=utf8mb4",
        $usuario,
        $contrasena
    );

    $conexion->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conexion->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $pasos = [];

    $conexion->exec("
        CREATE DATABASE IF NOT EXISTS `$basedatos`
        CHARACTER SET utf8mb4
        COLLATE utf8mb4_unicode_ci
    ");
    $pasos[] = "Base de datos creada o ya existente";

    $conexion->exec("USE `$basedatos`");

    $conexion->exec("
        CREATE TABLE IF NOT EXISTS usuarios (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nombre VARCHAR(100) NOT NULL,
            email VARCHAR(150) NOT NULL UNIQUE,
            contrasena_hash VARCHAR(255) NOT NULL,
            rol ENUM('cliente','admin') NOT NULL DEFAULT 'cliente',
            estado ENUM('activo','desactivado') NOT NULL DEFAULT 'activo',
            telefono VARCHAR(30) NULL,
            direccion VARCHAR(255) NULL,
            ciudad VARCHAR(120) NULL,
            codigo_postal VARCHAR(20) NULL,
            creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");
    $pasos[] = "Tabla usuarios creada";

    $camposUsuarios = [
        "telefono" => "ALTER TABLE usuarios ADD COLUMN telefono VARCHAR(30) NULL",
        "direccion" => "ALTER TABLE usuarios ADD COLUMN direccion VARCHAR(255) NULL",
        "ciudad" => "ALTER TABLE usuarios ADD COLUMN ciudad VARCHAR(120) NULL",
        "codigo_postal" => "ALTER TABLE usuarios ADD COLUMN codigo_postal VARCHAR(20) NULL",
    ];

    foreach ($camposUsuarios as $columna => $sql) {
        if (!dmh_column_exists($conexion, $basedatos, "usuarios", $columna)) {
            $conexion->exec($sql);
            $pasos[] = "Columna usuarios.$columna añadida";
        }
    }

    $conexion->exec("
        CREATE TABLE IF NOT EXISTS puntos_usuarios (
            id INT AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT NOT NULL,
            puntos INT NOT NULL DEFAULT 0,
            actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        )
    ");
    $pasos[] = "Tabla puntos_usuarios creada";

    $stmtDuplicadosPuntos = $conexion->query("
        SELECT COUNT(*)
        FROM (
            SELECT usuario_id
            FROM puntos_usuarios
            GROUP BY usuario_id
            HAVING COUNT(*) > 1
        ) t
    ");

    if ((int)$stmtDuplicadosPuntos->fetchColumn() > 0) {
        $conexion->exec("
            CREATE TEMPORARY TABLE tmp_puntos_usuarios AS
            SELECT usuario_id, SUM(puntos) AS puntos
            FROM puntos_usuarios
            GROUP BY usuario_id
        ");

        $conexion->exec("DELETE FROM puntos_usuarios");

        $conexion->exec("
            INSERT INTO puntos_usuarios (usuario_id, puntos)
            SELECT usuario_id, puntos
            FROM tmp_puntos_usuarios
        ");

        $conexion->exec("DROP TEMPORARY TABLE tmp_puntos_usuarios");
        $pasos[] = "Saneadas filas duplicadas en puntos_usuarios";
    }

    if (!dmh_index_exists($conexion, $basedatos, "puntos_usuarios", "uk_puntos_usuario")) {
        $conexion->exec("ALTER TABLE puntos_usuarios ADD UNIQUE KEY uk_puntos_usuario (usuario_id)");
        $pasos[] = "Índice único puntos_usuarios.usuario_id creado";
    }

    $conexion->exec("
        CREATE TABLE IF NOT EXISTS historial_puntos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT NOT NULL,
            pedido_id INT NULL,
            cambio INT NOT NULL,
            motivo VARCHAR(255) NOT NULL,
            creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        )
    ");
    $pasos[] = "Tabla historial_puntos creada";

    $conexion->exec("
        CREATE TABLE IF NOT EXISTS usuarios_stripe (
            id INT AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT NOT NULL UNIQUE,
            stripe_customer_id VARCHAR(255) NOT NULL UNIQUE,
            creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        )
    ");
    $pasos[] = "Tabla usuarios_stripe creada";

    $conexion->exec("
        CREATE TABLE IF NOT EXISTS categorias (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nombre VARCHAR(100) NOT NULL,
            slug VARCHAR(150) NOT NULL UNIQUE,
            categoria_padre_id INT NULL,
            creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (categoria_padre_id) REFERENCES categorias(id)
        )
    ");
    $pasos[] = "Tabla categorias creada";

    $conexion->exec("
        CREATE TABLE IF NOT EXISTS productos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nombre VARCHAR(150) NOT NULL,
            slug VARCHAR(150) NOT NULL UNIQUE,
            descripcion TEXT NULL,
            precio_base DECIMAL(10,2) NOT NULL,
            precio_original DECIMAL(10,2) NULL,
            tipo VARCHAR(40) NULL,
            color VARCHAR(40) NULL,
            badge VARCHAR(40) NULL,
            fecha_catalogo DATE NULL,
            estado ENUM('borrador','publicado','archivado') NOT NULL DEFAULT 'borrador',
            creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");
    $pasos[] = "Tabla productos creada";

    $camposProductos = [
        "precio_original" => "ALTER TABLE productos ADD COLUMN precio_original DECIMAL(10,2) NULL AFTER precio_base",
        "tipo" => "ALTER TABLE productos ADD COLUMN tipo VARCHAR(40) NULL AFTER precio_original",
        "color" => "ALTER TABLE productos ADD COLUMN color VARCHAR(40) NULL AFTER tipo",
        "badge" => "ALTER TABLE productos ADD COLUMN badge VARCHAR(40) NULL AFTER color",
        "fecha_catalogo" => "ALTER TABLE productos ADD COLUMN fecha_catalogo DATE NULL AFTER badge",
    ];

    foreach ($camposProductos as $columna => $sql) {
        if (!dmh_column_exists($conexion, $basedatos, "productos", $columna)) {
            $conexion->exec($sql);
            $pasos[] = "Columna productos.$columna añadida";
        }
    }

    $conexion->exec("
        CREATE TABLE IF NOT EXISTS tarjetas_guardadas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT NOT NULL,
            stripe_payment_method_id VARCHAR(255) NOT NULL UNIQUE,
            marca VARCHAR(40) NULL,
            ultimos4 VARCHAR(4) NULL,
            exp_mes TINYINT UNSIGNED NULL,
            exp_ano SMALLINT UNSIGNED NULL,
            creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_tarjeta_usuario_pm (usuario_id, stripe_payment_method_id),
            FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        )
    ");
    $pasos[] = "Tabla tarjetas_guardadas creada";

    $conexion->exec("
        CREATE TABLE IF NOT EXISTS carrito_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT NOT NULL,
            producto_id INT NULL,
            slug VARCHAR(150) NOT NULL,
            nombre_producto VARCHAR(150) NOT NULL,
            precio_unitario DECIMAL(10,2) NOT NULL,
            imagen VARCHAR(255) NULL,
            color VARCHAR(50) NULL,
            talla VARCHAR(20) NOT NULL DEFAULT 'Única',
            sku VARCHAR(100) NULL,
            cantidad INT NOT NULL DEFAULT 1,
            creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_carrito_usuario_slug_talla (usuario_id, slug, talla),
            FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        )
    ");
    $pasos[] = "Tabla carrito_items creada";

    $conexion->exec("
        CREATE TABLE IF NOT EXISTS usuarios_favoritos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT NOT NULL,
            slug VARCHAR(150) NOT NULL,
            creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_usuario_slug (usuario_id, slug),
            FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        )
    ");
    $pasos[] = "Tabla usuarios_favoritos creada";

    if (!dmh_column_exists($conexion, $basedatos, "usuarios_favoritos", "slug")) {
        $conexion->exec("ALTER TABLE usuarios_favoritos ADD COLUMN slug VARCHAR(150) NOT NULL DEFAULT '' AFTER usuario_id");
        $pasos[] = "Columna usuarios_favoritos.slug añadida";
    }

    $conexion->exec("
        CREATE TABLE IF NOT EXISTS opiniones (
            id INT AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT NOT NULL,
            producto_id INT NOT NULL,
            puntuacion TINYINT NOT NULL CHECK (puntuacion BETWEEN 1 AND 5),
            comentario TEXT NULL,
            estado ENUM('pendiente','aprobada','rechazada') NOT NULL DEFAULT 'pendiente',
            moderada_por INT NULL,
            moderada_en TIMESTAMP NULL DEFAULT NULL,
            creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_opinion_usuario_producto (usuario_id, producto_id),
            FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
            FOREIGN KEY (producto_id) REFERENCES productos(id)
        )
    ");
    $pasos[] = "Tabla opiniones creada";

    $camposOpiniones = [
        "estado" => "ALTER TABLE opiniones ADD COLUMN estado ENUM('pendiente','aprobada','rechazada') NOT NULL DEFAULT 'pendiente' AFTER comentario",
        "moderada_por" => "ALTER TABLE opiniones ADD COLUMN moderada_por INT NULL AFTER estado",
        "moderada_en" => "ALTER TABLE opiniones ADD COLUMN moderada_en TIMESTAMP NULL DEFAULT NULL AFTER moderada_por",
    ];

    foreach ($camposOpiniones as $columna => $sql) {
        if (!dmh_column_exists($conexion, $basedatos, "opiniones", $columna)) {
            $conexion->exec($sql);
            $pasos[] = "Columna opiniones.$columna añadida";
        }
    }

    if (!dmh_fk_exists($conexion, $basedatos, "opiniones", "moderada_por", "usuarios")) {
        try {
            $conexion->exec("ALTER TABLE opiniones ADD CONSTRAINT fk_opiniones_moderada_por FOREIGN KEY (moderada_por) REFERENCES usuarios(id)");
            $pasos[] = "FK opiniones.moderada_por creada";
        } catch (Throwable $e) {
            $pasos[] = "FK opiniones.moderada_por omitida: " . $e->getMessage();
        }
    }

    $conexion->exec("
        CREATE TABLE IF NOT EXISTS productos_categorias (
            producto_id INT NOT NULL,
            categoria_id INT NOT NULL,
            PRIMARY KEY (producto_id, categoria_id),
            FOREIGN KEY (producto_id) REFERENCES productos(id),
            FOREIGN KEY (categoria_id) REFERENCES categorias(id)
        )
    ");
    $pasos[] = "Tabla productos_categorias creada";

    $conexion->exec("
        CREATE TABLE IF NOT EXISTS imagenes_productos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            producto_id INT NOT NULL,
            url_imagen VARCHAR(255) NOT NULL,
            posicion INT NOT NULL DEFAULT 0,
            FOREIGN KEY (producto_id) REFERENCES productos(id)
        )
    ");
    $pasos[] = "Tabla imagenes_productos creada";

    if (!dmh_index_exists($conexion, $basedatos, "imagenes_productos", "uk_imagenes_producto_posicion")) {
        try {
            $conexion->exec("ALTER TABLE imagenes_productos ADD UNIQUE KEY uk_imagenes_producto_posicion (producto_id, posicion)");
            $pasos[] = "Índice único imagenes_productos(producto_id, posicion) creado";
        } catch (Throwable $e) {
            $pasos[] = "Índice imagenes_productos omitido: " . $e->getMessage();
        }
    }

    $conexion->exec("
        CREATE TABLE IF NOT EXISTS variantes_producto (
            id INT AUTO_INCREMENT PRIMARY KEY,
            producto_id INT NOT NULL,
            talla VARCHAR(20) NOT NULL,
            color VARCHAR(50) NULL,
            sku VARCHAR(100) NOT NULL UNIQUE,
            stock INT NOT NULL DEFAULT 0,
            precio_extra DECIMAL(10,2) NOT NULL DEFAULT 0,
            FOREIGN KEY (producto_id) REFERENCES productos(id)
        )
    ");
    $pasos[] = "Tabla variantes_producto creada";

    $conexion->exec("
        CREATE TABLE IF NOT EXISTS pedidos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT NULL,
            nombre_invitado VARCHAR(100) NULL,
            email_invitado VARCHAR(150) NULL,
            estado ENUM('pendiente','pagado','enviado','entregado','cancelado','devuelto') NOT NULL DEFAULT 'pendiente',
            importe_total DECIMAL(10,2) NOT NULL,
            puntos_usados INT NOT NULL DEFAULT 0,
            puntos_ganados INT NOT NULL DEFAULT 0,
            direccion_envio TEXT NOT NULL,
            creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        )
    ");
    $pasos[] = "Tabla pedidos creada";

    $stmtNullable = $conexion->prepare("
        SELECT IS_NULLABLE
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = :schema
          AND TABLE_NAME = 'pedidos'
          AND COLUMN_NAME = 'usuario_id'
    ");
    $stmtNullable->execute(["schema" => $basedatos]);
    if ($stmtNullable->fetchColumn() === "NO") {
        $conexion->exec("ALTER TABLE pedidos MODIFY COLUMN usuario_id INT NULL");
        $pasos[] = "pedidos.usuario_id migrado a NULL";
    }

    $camposPedidos = [
        "nombre_invitado" => "ALTER TABLE pedidos ADD COLUMN nombre_invitado VARCHAR(100) NULL AFTER usuario_id",
        "email_invitado" => "ALTER TABLE pedidos ADD COLUMN email_invitado VARCHAR(150) NULL AFTER nombre_invitado",
    ];

    foreach ($camposPedidos as $columna => $sql) {
        if (!dmh_column_exists($conexion, $basedatos, "pedidos", $columna)) {
            $conexion->exec($sql);
            $pasos[] = "Columna pedidos.$columna añadida";
        }
    }

    $conexion->exec("
        CREATE TABLE IF NOT EXISTS items_pedido (
            id INT AUTO_INCREMENT PRIMARY KEY,
            pedido_id INT NOT NULL,
            slug VARCHAR(150) NOT NULL,
            nombre_producto VARCHAR(150) NOT NULL,
            talla VARCHAR(20) NOT NULL DEFAULT 'Única',
            color VARCHAR(50) NULL,
            sku VARCHAR(100) NULL,
            cantidad INT NOT NULL,
            precio_unitario DECIMAL(10,2) NOT NULL,
            subtotal DECIMAL(10,2) NOT NULL,
            FOREIGN KEY (pedido_id) REFERENCES pedidos(id)
        )
    ");
    $pasos[] = "Tabla items_pedido creada";

    $conexion->exec("
        CREATE TABLE IF NOT EXISTS devoluciones (
            id INT AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(40) NOT NULL UNIQUE,
            pedido_id INT NOT NULL,
            item_pedido_id INT NOT NULL,
            usuario_id INT NOT NULL,
            cantidad_devuelta INT NOT NULL DEFAULT 1,
            estado ENUM('pendiente','aceptada','rechazada') NOT NULL DEFAULT 'pendiente',
            motivo TEXT NULL,
            creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (pedido_id) REFERENCES pedidos(id),
            FOREIGN KEY (item_pedido_id) REFERENCES items_pedido(id),
            FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        )
    ");
    $pasos[] = "Tabla devoluciones creada";

    $camposDevoluciones = [
        "slug" => "ALTER TABLE devoluciones ADD COLUMN slug VARCHAR(40) NULL AFTER id",
        "item_pedido_id" => "ALTER TABLE devoluciones ADD COLUMN item_pedido_id INT NULL AFTER pedido_id",
        "cantidad_devuelta" => "ALTER TABLE devoluciones ADD COLUMN cantidad_devuelta INT NOT NULL DEFAULT 1 AFTER usuario_id",
    ];

    foreach ($camposDevoluciones as $columna => $sql) {
        if (!dmh_column_exists($conexion, $basedatos, "devoluciones", $columna)) {
            $conexion->exec($sql);
            $pasos[] = "Columna devoluciones.$columna añadida";
        }
    }

    if (!dmh_index_exists($conexion, $basedatos, "devoluciones", "uq_devoluciones_slug")) {
        $conexion->exec("UPDATE devoluciones SET slug = CONCAT('DEV-', UPPER(HEX(id + 4096))) WHERE slug IS NULL OR slug = ''");
        $conexion->exec("ALTER TABLE devoluciones MODIFY COLUMN slug VARCHAR(40) NOT NULL");
        try {
            $conexion->exec("ALTER TABLE devoluciones ADD UNIQUE KEY uq_devoluciones_slug (slug)");
            $pasos[] = "Índice único devoluciones.slug creado";
        } catch (Throwable $e) {
            $pasos[] = "Índice devoluciones.slug omitido: " . $e->getMessage();
        }
    }

    /*
     * IMPORTANTE:
     * Este bloque es el arreglo principal.
     * Antes el instalador convertía cualquier estado que no fuese legacy a pendiente,
     * por eso las aceptadas volvían a pendiente al cerrar sesión y cargar la home.
     */
    $conexion->exec("
        ALTER TABLE devoluciones
        MODIFY COLUMN estado ENUM('solicitada','aprobada','rechazada','procesada','pendiente','aceptada') NOT NULL DEFAULT 'pendiente'
    ");

    $stmtLegacyReturns = $conexion->query("
        SELECT COUNT(*)
        FROM devoluciones
        WHERE estado IN ('solicitada','aprobada','procesada')
    ");

    if ((int)$stmtLegacyReturns->fetchColumn() > 0) {
        $conexion->exec("
            UPDATE devoluciones
            SET estado = CASE
                WHEN estado IN ('solicitada','procesada') THEN 'pendiente'
                WHEN estado = 'aprobada' THEN 'aceptada'
                WHEN estado = 'aceptada' THEN 'aceptada'
                WHEN estado = 'rechazada' THEN 'rechazada'
                WHEN estado = 'pendiente' THEN 'pendiente'
                ELSE estado
            END
        ");
        $pasos[] = "Estados legacy de devoluciones normalizados";
    } else {
        $pasos[] = "Devoluciones sin estados legacy: no se modifica ningún estado";
    }

    $conexion->exec("
        ALTER TABLE devoluciones
        MODIFY COLUMN estado ENUM('pendiente','aceptada','rechazada') NOT NULL DEFAULT 'pendiente'
    ");

    if (!dmh_fk_exists($conexion, $basedatos, "devoluciones", "item_pedido_id", "items_pedido")) {
        $conexion->exec("
            UPDATE devoluciones d
            LEFT JOIN items_pedido i ON i.pedido_id = d.pedido_id
            SET d.item_pedido_id = COALESCE(d.item_pedido_id, i.id)
            WHERE d.item_pedido_id IS NULL
        ");

        try {
            $conexion->exec("ALTER TABLE devoluciones MODIFY COLUMN item_pedido_id INT NOT NULL");
            $conexion->exec("ALTER TABLE devoluciones ADD CONSTRAINT fk_devoluciones_item_pedido FOREIGN KEY (item_pedido_id) REFERENCES items_pedido(id)");
            $pasos[] = "FK devoluciones.item_pedido_id creada";
        } catch (Throwable $e) {
            $pasos[] = "FK devoluciones.item_pedido_id omitida: " . $e->getMessage();
        }
    }

    $conexion->exec("
        CREATE TABLE IF NOT EXISTS contenido (
            id INT AUTO_INCREMENT PRIMARY KEY,
            titulo VARCHAR(200) NOT NULL,
            slug VARCHAR(200) NOT NULL UNIQUE,
            tipo ENUM('noticia','evento','promocion','lanzamiento','tienda','campaña','editorial') NOT NULL,
            resumen TEXT NULL,
            cuerpo TEXT NULL,
            imagen VARCHAR(255) NULL,
            fecha_inicio DATE NULL,
            fecha_fin DATE NULL,
            estado ENUM('borrador','publicado','archivado') NOT NULL DEFAULT 'borrador',
            creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");
    $pasos[] = "Tabla contenido creada";

    $conexion->exec("
        CREATE TABLE IF NOT EXISTS carrusel (
            id INT AUTO_INCREMENT PRIMARY KEY,
            titulo VARCHAR(200) NOT NULL,
            imagen VARCHAR(255) NOT NULL,
            url_destino VARCHAR(255) NOT NULL,
            posicion INT NOT NULL DEFAULT 0,
            estado ENUM('activo','inactivo') NOT NULL DEFAULT 'activo',
            creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");
    $pasos[] = "Tabla carrusel creada";

    $conexion->exec("
        CREATE TABLE IF NOT EXISTS logs_admin (
            id INT AUTO_INCREMENT PRIMARY KEY,
            admin_id INT NOT NULL,
            accion VARCHAR(255) NOT NULL,
            tipo_objetivo VARCHAR(100) NOT NULL,
            objetivo_id INT NOT NULL,
            creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (admin_id) REFERENCES usuarios(id)
        )
    ");
    $pasos[] = "Tabla logs_admin creada";

    $conexion->exec("
        CREATE TABLE IF NOT EXISTS vistas_producto (
            id INT AUTO_INCREMENT PRIMARY KEY,
            producto_id INT NOT NULL,
            visto_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (producto_id) REFERENCES productos(id)
        )
    ");
    $pasos[] = "Tabla vistas_producto creada";

    /*
     * No reseteamos admin ni clientes si ya existen.
     * Antes el instalador podía sobrescribir contraseña, rol y estado.
     */
    $passwordAdmin = password_hash("admin123", PASSWORD_DEFAULT);
    $stmtAdmin = $conexion->prepare("
        INSERT INTO usuarios (nombre, email, contrasena_hash, rol, estado)
        VALUES (:nombre, :email, :contrasena_hash, 'admin', 'activo')
        ON DUPLICATE KEY UPDATE
            id = id
    ");
    $stmtAdmin->execute([
        "nombre" => "Administrador",
        "email" => "admin@dripmode.com",
        "contrasena_hash" => $passwordAdmin,
    ]);
    $pasos[] = "Usuario administrador creado o ya existente sin resetear datos";

    $usuariosPrueba = [
        [
            "nombre" => "Carlos Prueba",
            "email" => "carlos@test.com",
            "password" => "test1234",
            "telefono" => "612345678",
            "ciudad" => "Madrid",
        ],
        [
            "nombre" => "Laura Demo",
            "email" => "laura@test.com",
            "password" => "test1234",
            "telefono" => "698765432",
            "ciudad" => "Barcelona",
        ],
        [
            "nombre" => "Marcos Tester",
            "email" => "marcos@test.com",
            "password" => "test1234",
            "telefono" => "",
            "ciudad" => "Sevilla",
        ],
    ];

    $stmtUsuario = $conexion->prepare("
        INSERT INTO usuarios (nombre, email, contrasena_hash, rol, telefono, ciudad, estado)
        VALUES (:nombre, :email, :hash, 'cliente', :telefono, :ciudad, 'activo')
        ON DUPLICATE KEY UPDATE
            telefono = COALESCE(NULLIF(telefono, ''), VALUES(telefono)),
            ciudad = COALESCE(NULLIF(ciudad, ''), VALUES(ciudad))
    ");

    foreach ($usuariosPrueba as $u) {
        $stmtUsuario->execute([
            "nombre" => $u["nombre"],
            "email" => $u["email"],
            "hash" => password_hash($u["password"], PASSWORD_DEFAULT),
            "telefono" => $u["telefono"] ?: null,
            "ciudad" => $u["ciudad"],
        ]);
    }

    $pasos[] = "Usuarios de prueba creados o ya existentes sin resetear contraseña/rol";

    /*
     * Seed del catálogo:
     * Solo se ejecuta si productos está vacío.
     * Si ya hay productos, no borra, no actualiza y no resetea stock/opiniones.
     */
    $seedPath = __DIR__ . "/seed/catalog_seed.php";

    if (file_exists($seedPath)) {
        $stmtCountProductos = $conexion->query("SELECT COUNT(*) FROM productos");
        $totalProductosExistentes = (int)$stmtCountProductos->fetchColumn();

        if ($totalProductosExistentes > 0) {
            $pasos[] = "Catálogo ya tiene productos; seed omitido para no resetear datos";
        } else {
            $seed = require $seedPath;

            $seedProducts = is_array($seed["products"] ?? null) ? $seed["products"] : [];
            $seedStock = is_array($seed["stock"] ?? null) ? $seed["stock"] : [];
            $seedReviews = is_array($seed["reviews"] ?? null) ? $seed["reviews"] : [];

            $catalogLimit = 6;
            $seedProducts = array_slice($seedProducts, 0, $catalogLimit);

            $allowedSlugs = array_values(array_filter(array_map(
                static fn($product) => trim((string)($product["slug"] ?? "")),
                $seedProducts
            )));

            $allowedSlugMap = array_fill_keys($allowedSlugs, true);

            $seedStock = array_values(array_filter(
                $seedStock,
                static fn($entry) => isset($allowedSlugMap[(string)($entry["productSlug"] ?? "")])
            ));

            $seedReviews = array_values(array_filter(
                $seedReviews,
                static fn($entry) => isset($allowedSlugMap[(string)($entry["productSlug"] ?? "")])
            ));

            $stmtCategoria = $conexion->prepare("
                INSERT INTO categorias (nombre, slug)
                VALUES (:nombre, :slug)
                ON DUPLICATE KEY UPDATE nombre = VALUES(nombre)
            ");

            $stmtProducto = $conexion->prepare("
                INSERT INTO productos (
                    nombre,
                    slug,
                    descripcion,
                    precio_base,
                    precio_original,
                    tipo,
                    color,
                    badge,
                    fecha_catalogo,
                    estado
                )
                VALUES (
                    :nombre,
                    :slug,
                    :descripcion,
                    :precio,
                    :precio_original,
                    :tipo,
                    :color,
                    :badge,
                    :fecha_catalogo,
                    'publicado'
                )
                ON DUPLICATE KEY UPDATE
                    id = id
            ");

            $stmtGetCategoria = $conexion->prepare("
                SELECT id
                FROM categorias
                WHERE slug = :slug
                LIMIT 1
            ");

            $stmtGetProducto = $conexion->prepare("
                SELECT id
                FROM productos
                WHERE slug = :slug
                LIMIT 1
            ");

            $stmtProdCat = $conexion->prepare("
                INSERT IGNORE INTO productos_categorias (producto_id, categoria_id)
                VALUES (:pid, :cid)
            ");

            $stmtInsertImg = $conexion->prepare("
                INSERT INTO imagenes_productos (producto_id, url_imagen, posicion)
                VALUES (:pid, :url, :pos)
                ON DUPLICATE KEY UPDATE
                    url_imagen = VALUES(url_imagen)
            ");

            $stmtInsertVariante = $conexion->prepare("
                INSERT INTO variantes_producto (producto_id, talla, color, sku, stock, precio_extra)
                VALUES (:pid, :talla, :color, :sku, :stock, 0)
                ON DUPLICATE KEY UPDATE
                    talla = VALUES(talla),
                    color = VALUES(color),
                    stock = VALUES(stock)
            ");

            $stmtUsuarioReview = $conexion->prepare("
                INSERT INTO usuarios (nombre, email, contrasena_hash, rol, estado)
                VALUES (:nombre, :email, :hash, 'cliente', 'activo')
                ON DUPLICATE KEY UPDATE id = id
            ");

            $stmtGetUsuarioReview = $conexion->prepare("
                SELECT id
                FROM usuarios
                WHERE email = :email
                LIMIT 1
            ");

            $stmtOpinion = $conexion->prepare("
                INSERT INTO opiniones (
                    usuario_id,
                    producto_id,
                    puntuacion,
                    comentario,
                    estado,
                    creado_en
                )
                VALUES (
                    :uid,
                    :pid,
                    :puntuacion,
                    :comentario,
                    'aprobada',
                    :creado_en
                )
                ON DUPLICATE KEY UPDATE id = id
            ");

            $productoIdBySlug = [];
            $colorBySlug = [];
            $categoriaIdBySlug = [];

            foreach ($seedProducts as $product) {
                $nombreCategoria = trim((string)($product["category"] ?? "General"));
                $slugCategoria = dmh_slugify($nombreCategoria);

                if (!isset($categoriaIdBySlug[$slugCategoria])) {
                    $stmtCategoria->execute([
                        "nombre" => $nombreCategoria,
                        "slug" => $slugCategoria,
                    ]);

                    $stmtGetCategoria->execute([
                        "slug" => $slugCategoria,
                    ]);

                    $categoriaIdBySlug[$slugCategoria] = (int)$stmtGetCategoria->fetchColumn();
                }

                $productSlug = (string)($product["slug"] ?? "");

                $stmtProducto->execute([
                    "nombre" => (string)($product["title"] ?? ""),
                    "slug" => $productSlug,
                    "descripcion" => dmh_strip_color_mentions((string)($product["description"] ?? "")),
                    "precio" => (float)($product["price"] ?? 0),
                    "precio_original" => isset($product["originalPrice"]) ? (float)$product["originalPrice"] : null,
                    "tipo" => trim((string)($product["type"] ?? "")) ?: null,
                    "color" => trim((string)($product["color"] ?? "")) ?: null,
                    "badge" => trim((string)($product["badge"] ?? "")) ?: null,
                    "fecha_catalogo" => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($product["createdAt"] ?? ""))
                        ? (string)$product["createdAt"]
                        : null,
                ]);

                $stmtGetProducto->execute([
                    "slug" => $productSlug,
                ]);

                $productoId = (int)$stmtGetProducto->fetchColumn();

                if ($productoId <= 0) {
                    continue;
                }

                $productoIdBySlug[$productSlug] = $productoId;
                $colorBySlug[$productSlug] = trim((string)($product["color"] ?? "")) ?: null;

                $stmtProdCat->execute([
                    "pid" => $productoId,
                    "cid" => $categoriaIdBySlug[$slugCategoria],
                ]);

                $imagenes = dmh_resolve_three_images(
                    is_array($product["images"] ?? null) ? $product["images"] : [],
                    isset($product["image"]) ? (string)$product["image"] : null
                );

                foreach ($imagenes as $index => $img) {
                    $stmtInsertImg->execute([
                        "pid" => $productoId,
                        "url" => (string)$img,
                        "pos" => (int)$index,
                    ]);
                }
            }

            foreach ($seedStock as $stockEntry) {
                $slug = (string)($stockEntry["productSlug"] ?? "");
                $productoId = $productoIdBySlug[$slug] ?? 0;

                if ($productoId <= 0) {
                    continue;
                }

                $bySize = is_array($stockEntry["bySize"] ?? null) ? $stockEntry["bySize"] : [];

                foreach ($bySize as $talla => $variant) {
                    $stmtInsertVariante->execute([
                        "pid" => $productoId,
                        "talla" => (string)$talla,
                        "color" => $colorBySlug[$slug] ?? null,
                        "sku" => (string)($variant["sku"] ?? ""),
                        "stock" => (int)($variant["stock"] ?? 0),
                    ]);
                }
            }

            $reviewerIdByEmail = [];

            foreach ($seedReviews as $reviewGroup) {
                $slug = (string)($reviewGroup["productSlug"] ?? "");
                $productoId = $productoIdBySlug[$slug] ?? 0;

                if ($productoId <= 0) {
                    continue;
                }

                $items = is_array($reviewGroup["items"] ?? null) ? $reviewGroup["items"] : [];

                foreach ($items as $review) {
                    $reviewerName = trim((string)($review["user"] ?? "Cliente"));
                    $reviewerSlug = dmh_slugify($reviewerName);
                    $reviewerEmail = $reviewerSlug . "@reviews.local";

                    if (!isset($reviewerIdByEmail[$reviewerEmail])) {
                        $stmtUsuarioReview->execute([
                            "nombre" => $reviewerName,
                            "email" => $reviewerEmail,
                            "hash" => password_hash("review1234", PASSWORD_DEFAULT),
                        ]);

                        $stmtGetUsuarioReview->execute([
                            "email" => $reviewerEmail,
                        ]);

                        $reviewerIdByEmail[$reviewerEmail] = (int)$stmtGetUsuarioReview->fetchColumn();
                    }

                    $date = trim((string)($review["date"] ?? ""));
                    $createdAt = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
                        ? ($date . " 12:00:00")
                        : date("Y-m-d H:i:s");

                    $stmtOpinion->execute([
                        "uid" => $reviewerIdByEmail[$reviewerEmail],
                        "pid" => $productoId,
                        "puntuacion" => (int)max(1, min(5, (int)($review["rating"] ?? 0))),
                        "comentario" => (string)($review["comment"] ?? ""),
                        "creado_en" => $createdAt,
                    ]);
                }
            }

            $pasos[] = "Seed de catálogo/stock/reseñas insertado porque productos estaba vacío";
        }
    } else {
        $pasos[] = "Semilla catalog_seed.php no encontrada, se omite seed de catálogo";
    }

    echo json_encode([
        "ok" => true,
        "mensaje" => "Instalación completada correctamente",
        "pasos" => $pasos,
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "error" => "Error durante la instalación",
        "detalle" => $e->getMessage(),
    ]);
}