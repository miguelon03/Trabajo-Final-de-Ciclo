<?php

//cabeceras CORS para permitir peticiones desde el frontend de Astro en localhost
header("Access-Control-Allow-Origin: http://localhost:4321");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

//la respuesta es en JSON
header("Content-Type: application/json; charset=utf-8");

//si el navegador hace una peticion previa OPTIONS respondemos OK y terminamos
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit();
}

//datos del servidor MySQL
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

function dmh_is_placeholder_image(string $value): bool {
    $v = strtolower(trim($value));
    if ($v === '') {
        return true;
    }

    return str_starts_with($v, 'placeholder');
}

function dmh_build_image_variant(string $path, int $index): string {
    $path = trim($path);
    if ($path === '' || $index <= 0) {
        return $path;
    }

    // Caso habitual: /productos/slug-1.png o /productos/slug_1.png
    if (preg_match('/^(.*?)([-_])\d+(\.[a-z0-9]+)$/i', $path, $m)) {
        return $m[1] . $m[2] . $index . $m[3];
    }

    // Caso alternativo: /productos/nombre (1).png
    if (preg_match('/^(.*)\(\d+\)(\.[a-z0-9]+)$/i', $path, $m)) {
        return rtrim($m[1]) . ' (' . $index . ')' . $m[2];
    }

    // Fallback: añade -N antes de la extensión.
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

    // Elimina placeholders antes de completar el set.
    $images = array_values(array_filter($images, static fn($img) => !dmh_is_placeholder_image((string)$img)));

    if (count($images) === 0) {
        return [];
    }

    // Ordena por sufijo numérico cuando existe: (1), (2), (3), -1, _2...
    usort($images, static function (string $a, string $b): int {
        $pa = dmh_extract_image_position($a);
        $pb = dmh_extract_image_position($b);

        if ($pa === null && $pb === null) {
            return 0;
        }
        if ($pa === null) {
            return 1;
        }
        if ($pb === null) {
            return -1;
        }

        return $pa <=> $pb;
    });

    $first = $images[0];
    $resolved = [];
    for ($i = 0; $i < 3; $i++) {
        if (isset($images[$i]) && trim((string)$images[$i]) !== '') {
            $resolved[] = trim((string)$images[$i]);
            continue;
        }
        $resolved[] = dmh_build_image_variant($first, $i + 1);
    }

    return array_slice($resolved, 0, 3);
}

try {
    //creamos una conexion PDO al servidor como en conexion.php pero sin la base de datos porque no existe, la estamos creando
    $conexion = new PDO(
        "mysql:host=$host;charset=utf8mb4",
        $usuario,
        $contrasena
    );

    //como en conexion.php excepciones para capturar errores SQL y que los resultados sean arrays asociativos
    $conexion->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conexion->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    //diego, en vez de tener los echos que se cargan el archivo te he puesto un array que se vayan metiendo las cosas 
    $pasos = [];

    //creamos la base de datos si no existe
    $conexion->exec("
        CREATE DATABASE IF NOT EXISTS `$basedatos`
        CHARACTER SET utf8mb4
        COLLATE utf8mb4_unicode_ci
    ");
    $pasos[] = "Base de datos creada o ya existente";

    //seleccionamos la base de datos que hemos creado
    $conexion->exec("USE `$basedatos`");

    //Tabla de usuarios
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

    // Compatibilidad: si la tabla usuarios ya existía, añadimos los nuevos campos de perfil.
    // Se consulta INFORMATION_SCHEMA para no depender de versiones concretas de MySQL.
    $camposPerfilUsuarios = [
        "telefono" => "ALTER TABLE usuarios ADD COLUMN telefono VARCHAR(30) NULL",
        "direccion" => "ALTER TABLE usuarios ADD COLUMN direccion VARCHAR(255) NULL",
        "ciudad" => "ALTER TABLE usuarios ADD COLUMN ciudad VARCHAR(120) NULL",
        "codigo_postal" => "ALTER TABLE usuarios ADD COLUMN codigo_postal VARCHAR(20) NULL",
    ];

    $stmtColumna = $conexion->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = :schema
          AND TABLE_NAME = 'usuarios'
          AND COLUMN_NAME = :columna
    ");

    foreach ($camposPerfilUsuarios as $nombreColumna => $sqlAlter) {
        $stmtColumna->execute([
            "schema" => $basedatos,
            "columna" => $nombreColumna,
        ]);

        $existeColumna = (int)$stmtColumna->fetchColumn() > 0;
        if (!$existeColumna) {
            $conexion->exec($sqlAlter);
            $pasos[] = "Columna '$nombreColumna' añadida en usuarios";
        }
    }
    //Tabla de puntos de usuarios
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

    // Saneamiento: si hay multiples filas por usuario, consolidamos en una sola sumando puntos.
    $stmtDuplicadosPuntos = $conexion->query("\n        SELECT COUNT(*)\n        FROM (\n            SELECT usuario_id\n            FROM puntos_usuarios\n            GROUP BY usuario_id\n            HAVING COUNT(*) > 1\n        ) t\n    ");
    $hayDuplicadosPuntos = (int)$stmtDuplicadosPuntos->fetchColumn() > 0;

    if ($hayDuplicadosPuntos) {
        $conexion->exec("\n            CREATE TEMPORARY TABLE tmp_puntos_usuarios AS\n            SELECT usuario_id, SUM(puntos) AS puntos\n            FROM puntos_usuarios\n            GROUP BY usuario_id\n        ");

        $conexion->exec("DELETE FROM puntos_usuarios");

        $conexion->exec("\n            INSERT INTO puntos_usuarios (usuario_id, puntos)\n            SELECT usuario_id, puntos\n            FROM tmp_puntos_usuarios\n        ");

        $conexion->exec("DROP TEMPORARY TABLE tmp_puntos_usuarios");
        $pasos[] = "Saneadas filas duplicadas en puntos_usuarios";
    }

    // Forzamos unicidad por usuario para evitar inconsistencias futuras.
    $stmtUniquePuntos = $conexion->prepare("\n        SELECT COUNT(*)\n        FROM INFORMATION_SCHEMA.STATISTICS\n        WHERE TABLE_SCHEMA = :schema\n          AND TABLE_NAME = 'puntos_usuarios'\n          AND INDEX_NAME = 'uk_puntos_usuario'\n    ");
    $stmtUniquePuntos->execute(["schema" => $basedatos]);
    $existeUniquePuntos = (int)$stmtUniquePuntos->fetchColumn() > 0;

    if (!$existeUniquePuntos) {
        $conexion->exec("ALTER TABLE puntos_usuarios ADD UNIQUE KEY uk_puntos_usuario (usuario_id)");
        $pasos[] = "Indice unico puntos_usuarios.usuario_id creado";
    }

    //Tabla de historial de puntos del usuario
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

    //Tabla de categorias
        // Tabla de cliente Stripe por usuario (1:1)
        $conexion->exec("\n        CREATE TABLE IF NOT EXISTS usuarios_stripe (\n            id INT AUTO_INCREMENT PRIMARY KEY,\n            usuario_id INT NOT NULL UNIQUE,\n            stripe_customer_id VARCHAR(255) NOT NULL UNIQUE,\n            creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n            actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n            FOREIGN KEY (usuario_id) REFERENCES usuarios(id)\n        )\n    ");
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
    //Tabla de productos
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

        // Tarjetas guardadas por miembro para checkout rápido
        $conexion->exec("\n        CREATE TABLE IF NOT EXISTS tarjetas_guardadas (\n            id INT AUTO_INCREMENT PRIMARY KEY,\n            usuario_id INT NOT NULL,\n            stripe_payment_method_id VARCHAR(255) NOT NULL UNIQUE,\n            marca VARCHAR(40) NULL,\n            ultimos4 VARCHAR(4) NULL,\n            exp_mes TINYINT UNSIGNED NULL,\n            exp_ano SMALLINT UNSIGNED NULL,\n            creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n            actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n            UNIQUE KEY uk_tarjeta_usuario_pm (usuario_id, stripe_payment_method_id),\n            FOREIGN KEY (usuario_id) REFERENCES usuarios(id)\n        )\n    ");
        $pasos[] = "Tabla tarjetas_guardadas creada";

    // Carrito persistente por miembro (invitados siguen en localStorage)
    $conexion->exec("\n        CREATE TABLE IF NOT EXISTS carrito_items (\n            id INT AUTO_INCREMENT PRIMARY KEY,\n            usuario_id INT NOT NULL,\n            producto_id INT NULL,\n            slug VARCHAR(150) NOT NULL,\n            nombre_producto VARCHAR(150) NOT NULL,\n            precio_unitario DECIMAL(10,2) NOT NULL,\n            imagen VARCHAR(255) NULL,\n            color VARCHAR(50) NULL,\n            talla VARCHAR(20) NOT NULL DEFAULT 'Única',\n            sku VARCHAR(100) NULL,\n            cantidad INT NOT NULL DEFAULT 1,\n            creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n            actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n            UNIQUE KEY uk_carrito_usuario_slug_talla (usuario_id, slug, talla),\n            FOREIGN KEY (usuario_id) REFERENCES usuarios(id)\n        )\n    ");
    $pasos[] = "Tabla carrito_items creada";
    $camposCatalogoProductos = [
        "precio_original" => "ALTER TABLE productos ADD COLUMN precio_original DECIMAL(10,2) NULL AFTER precio_base",
        "tipo" => "ALTER TABLE productos ADD COLUMN tipo VARCHAR(40) NULL AFTER precio_original",
        "color" => "ALTER TABLE productos ADD COLUMN color VARCHAR(40) NULL AFTER tipo",
        "badge" => "ALTER TABLE productos ADD COLUMN badge VARCHAR(40) NULL AFTER color",
        "fecha_catalogo" => "ALTER TABLE productos ADD COLUMN fecha_catalogo DATE NULL AFTER badge",
    ];

    foreach ($camposCatalogoProductos as $nombreColumna => $sqlAlter) {
                $stmtColCatalogo = $conexion->prepare("
                        SELECT COUNT(*)
                        FROM INFORMATION_SCHEMA.COLUMNS
                        WHERE TABLE_SCHEMA = :schema
                            AND TABLE_NAME = 'productos'
                            AND COLUMN_NAME = :columna
                ");
        $stmtColCatalogo->execute([
            "schema" => $basedatos,
            "columna" => $nombreColumna,
        ]);

        if ((int)$stmtColCatalogo->fetchColumn() === 0) {
            $conexion->exec($sqlAlter);
            $pasos[] = "Columna productos.$nombreColumna añadida";
        }
    }

    // Tabla de favoritos por usuario.
    // Usa slug directamente (productos vienen de JSON estático, no de BD).
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

    // Compatibilidad: si la tabla ya existía con producto_id, migramos a slug.
    // Comprobamos si la columna slug ya existe.
    $stmtCheckSlug = $conexion->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = :schema
          AND TABLE_NAME = 'usuarios_favoritos'
          AND COLUMN_NAME = 'slug'
    ");
    $stmtCheckSlug->execute(["schema" => $basedatos]);
    $slugColumnaExiste = (int)$stmtCheckSlug->fetchColumn() > 0;

    if (!$slugColumnaExiste) {
        // Eliminamos FK y columna producto_id para migrar a slug.
        // Primero eliminamos la FK si existe.
        $conexion->exec("ALTER TABLE usuarios_favoritos DROP FOREIGN KEY IF EXISTS usuarios_favoritos_ibfk_2");
        $conexion->exec("ALTER TABLE usuarios_favoritos DROP COLUMN IF EXISTS producto_id");
        $conexion->exec("ALTER TABLE usuarios_favoritos ADD COLUMN slug VARCHAR(150) NOT NULL DEFAULT '' AFTER usuario_id");
        $conexion->exec("ALTER TABLE usuarios_favoritos ADD UNIQUE KEY uk_usuario_slug (usuario_id, slug)");
        $pasos[] = "Migrada columna producto_id -> slug en usuarios_favoritos";
    }
    //Tabla de opiniones
    $conexion->exec("
        CREATE TABLE IF NOT EXISTS opiniones (
            id INT AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT NOT NULL,
            producto_id INT NOT NULL,
            puntuacion TINYINT NOT NULL CHECK (puntuacion BETWEEN 1 AND 5),
            comentario TEXT NULL,
            creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (usuario_id, producto_id),
            FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
            FOREIGN KEY (producto_id) REFERENCES productos(id)
        )
    ");
    $pasos[] = "Tabla opiniones creada";
    //Tabla de productos por categoria
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
    //Tabla de imagenes de los productos
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

    $stmtIdxImgPos = $conexion->prepare(" 
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = :schema
          AND TABLE_NAME = 'imagenes_productos'
          AND INDEX_NAME = 'uk_imagenes_producto_posicion'
    ");
    $stmtIdxImgPos->execute(['schema' => $basedatos]);
    if ((int)$stmtIdxImgPos->fetchColumn() === 0) {
        $conexion->exec("ALTER TABLE imagenes_productos ADD UNIQUE KEY uk_imagenes_producto_posicion (producto_id, posicion)");
        $pasos[] = "Índice único imagenes_productos(producto_id, posicion) creado";
    }
    //Tabla de variantes de producto
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
    // Tabla de pedidos: usuario_id nullable para permitir compra como invitado.
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

    // Migracion: hacer usuario_id nullable si aun es NOT NULL, y añadir campos de invitado.
    $stmtCheckPedidosNull = $conexion->prepare("
        SELECT IS_NULLABLE
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = :schema
          AND TABLE_NAME = 'pedidos'
          AND COLUMN_NAME = 'usuario_id'
    ");
    $stmtCheckPedidosNull->execute(['schema' => $basedatos]);
    $isNullable = $stmtCheckPedidosNull->fetchColumn();

    if ($isNullable === 'NO') {
        $conexion->exec("ALTER TABLE pedidos MODIFY COLUMN usuario_id INT NULL");
        $pasos[] = "pedidos.usuario_id migrado a NULL";
    }

    foreach (['nombre_invitado' => "ALTER TABLE pedidos ADD COLUMN nombre_invitado VARCHAR(100) NULL AFTER usuario_id",
              'email_invitado'  => "ALTER TABLE pedidos ADD COLUMN email_invitado VARCHAR(150) NULL AFTER nombre_invitado"] as $col => $sql) {
        $stmtColumna->execute(['schema' => $basedatos, 'columna' => $col]);
        // Reutilizamos $stmtColumna pero con tabla pedidos.
        $stmtCol2 = $conexion->prepare("
            SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = 'pedidos' AND COLUMN_NAME = :columna
        ");
        $stmtCol2->execute(['schema' => $basedatos, 'columna' => $col]);
        if ((int)$stmtCol2->fetchColumn() === 0) {
            $conexion->exec($sql);
            $pasos[] = "Columna pedidos.$col añadida";
        }
    }

    // Tabla de items por pedido: guarda los datos del producto directamente
    // (los productos vienen de JSON estático, no de la tabla productos).
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
    //Tabla de devoluciones
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

    $stmtHasColumn = $conexion->prepare(" 
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = :schema
          AND TABLE_NAME = 'devoluciones'
          AND COLUMN_NAME = :columna
    ");

    $stmtHasColumn->execute(['schema' => $basedatos, 'columna' => 'slug']);
    if ((int)$stmtHasColumn->fetchColumn() === 0) {
        $conexion->exec("ALTER TABLE devoluciones ADD COLUMN slug VARCHAR(40) NULL AFTER id");
        $pasos[] = "Columna devoluciones.slug añadida";
    }

    $stmtHasColumn->execute(['schema' => $basedatos, 'columna' => 'item_pedido_id']);
    if ((int)$stmtHasColumn->fetchColumn() === 0) {
        $conexion->exec("ALTER TABLE devoluciones ADD COLUMN item_pedido_id INT NULL AFTER pedido_id");
        $pasos[] = "Columna devoluciones.item_pedido_id añadida";
    }

    $stmtHasColumn->execute(['schema' => $basedatos, 'columna' => 'cantidad_devuelta']);
    if ((int)$stmtHasColumn->fetchColumn() === 0) {
        $conexion->exec("ALTER TABLE devoluciones ADD COLUMN cantidad_devuelta INT NOT NULL DEFAULT 1 AFTER usuario_id");
        $pasos[] = "Columna devoluciones.cantidad_devuelta añadida";
    }

    $stmtHasUniqueSlug = $conexion->prepare(" 
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = :schema
          AND TABLE_NAME = 'devoluciones'
          AND INDEX_NAME = 'uq_devoluciones_slug'
    ");
    $stmtHasUniqueSlug->execute(['schema' => $basedatos]);
    if ((int)$stmtHasUniqueSlug->fetchColumn() === 0) {
        $conexion->exec("UPDATE devoluciones SET slug = CONCAT('DEV-', UPPER(HEX(id + 4096))) WHERE slug IS NULL OR slug = ''");
        $conexion->exec("ALTER TABLE devoluciones MODIFY COLUMN slug VARCHAR(40) NOT NULL");
        $conexion->exec("ALTER TABLE devoluciones ADD UNIQUE KEY uq_devoluciones_slug (slug)");
        $pasos[] = "Índice único devoluciones.slug creado";
    }

    $conexion->exec("ALTER TABLE devoluciones MODIFY COLUMN estado ENUM('solicitada','aprobada','rechazada','procesada','pendiente','aceptada') NOT NULL DEFAULT 'pendiente'");

    $conexion->exec(" 
        UPDATE devoluciones
        SET estado = CASE
            WHEN estado IN ('solicitada', 'procesada') THEN 'pendiente'
            WHEN estado = 'aprobada' THEN 'aceptada'
            WHEN estado = 'rechazada' THEN 'rechazada'
            ELSE 'pendiente'
        END
    ");

    $conexion->exec("ALTER TABLE devoluciones MODIFY COLUMN estado ENUM('pendiente','aceptada','rechazada') NOT NULL DEFAULT 'pendiente'");

    $stmtHasItemFk = $conexion->prepare(" 
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = :schema
          AND TABLE_NAME = 'devoluciones'
          AND COLUMN_NAME = 'item_pedido_id'
          AND REFERENCED_TABLE_NAME = 'items_pedido'
    ");
    $stmtHasItemFk->execute(['schema' => $basedatos]);
    if ((int)$stmtHasItemFk->fetchColumn() === 0) {
        $conexion->exec(" 
            UPDATE devoluciones d
            LEFT JOIN items_pedido i ON i.pedido_id = d.pedido_id
            SET d.item_pedido_id = COALESCE(d.item_pedido_id, i.id)
            WHERE d.item_pedido_id IS NULL
        ");

        $conexion->exec("ALTER TABLE devoluciones MODIFY COLUMN item_pedido_id INT NOT NULL");
        $conexion->exec("ALTER TABLE devoluciones ADD CONSTRAINT fk_devoluciones_item_pedido FOREIGN KEY (item_pedido_id) REFERENCES items_pedido(id)");
        $pasos[] = "FK devoluciones.item_pedido_id creada";
    }
    //Tabla de contenido
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
    //Tabla de carrusel
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
    //Tabla de los logs del admin
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
    //Tabla vistas de un producto
    $conexion->exec("
        CREATE TABLE IF NOT EXISTS vistas_producto (
            id INT AUTO_INCREMENT PRIMARY KEY,
            producto_id INT NOT NULL,
            visto_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (producto_id) REFERENCES productos(id)
        )
    ");
    $pasos[] = "Tabla vistas_producto creada";

    //Creamos el administrador y lo insertamos en la tabla de usuarios
    $password = password_hash("admin123", PASSWORD_DEFAULT);
    $stmt = $conexion->prepare("
        INSERT INTO usuarios (nombre, email, contrasena_hash, rol)
        VALUES (:nombre, :email, :contrasena_hash, 'admin')
        ON DUPLICATE KEY UPDATE
            nombre = VALUES(nombre),
            contrasena_hash = VALUES(contrasena_hash),
            rol = 'admin',
            estado = 'activo'
    ");
    $stmt->execute([
        "nombre" => "Administrador",
        "email" => "admin@dripmode.com",
        "contrasena_hash" => $password
    ]);

    $pasos[] = "Usuario administrador creado o ya existente";

    // Usuarios de prueba para testing de flujos de cliente.
    $usuariosPrueba = [
        [
            "nombre"   => "Carlos Prueba",
            "email"    => "carlos@test.com",
            "password" => "test1234",
            "telefono" => "612345678",
            "ciudad"   => "Madrid",
        ],
        [
            "nombre"   => "Laura Demo",
            "email"    => "laura@test.com",
            "password" => "test1234",
            "telefono" => "698765432",
            "ciudad"   => "Barcelona",
        ],
        [
            "nombre"   => "Marcos Tester",
            "email"    => "marcos@test.com",
            "password" => "test1234",
            "telefono" => "",
            "ciudad"   => "Sevilla",
        ],
    ];

    $stmtUsuario = $conexion->prepare("
        INSERT INTO usuarios (nombre, email, contrasena_hash, rol, telefono, ciudad)
        VALUES (:nombre, :email, :hash, 'cliente', :telefono, :ciudad)
        ON DUPLICATE KEY UPDATE
            nombre = VALUES(nombre),
            contrasena_hash = VALUES(contrasena_hash),
            rol = 'cliente',
            telefono = VALUES(telefono),
            ciudad = VALUES(ciudad),
            estado = 'activo'
    ");

    foreach ($usuariosPrueba as $u) {
        $stmtUsuario->execute([
            "nombre"   => $u["nombre"],
            "email"    => $u["email"],
            "hash"     => password_hash($u["password"], PASSWORD_DEFAULT),
            "telefono" => $u["telefono"] ?: null,
            "ciudad"   => $u["ciudad"],
        ]);
    }

    $pasos[] = "Usuarios de prueba creados (password: test1234)";

    // Seed de catalogo/reseñas/stock desde backend/seed/catalog_seed.php
    $seedPath = __DIR__ . '/seed/catalog_seed.php';
    if (file_exists($seedPath)) {
        $seed = require $seedPath;
        $seedProducts = is_array($seed['products'] ?? null) ? $seed['products'] : [];
        $seedStock = is_array($seed['stock'] ?? null) ? $seed['stock'] : [];
        $seedReviews = is_array($seed['reviews'] ?? null) ? $seed['reviews'] : [];

        $catalogLimit = 6;
        $seedProducts = array_slice($seedProducts, 0, $catalogLimit);
        $allowedSlugs = array_values(array_filter(array_map(
            static fn($product) => trim((string)($product['slug'] ?? '')),
            $seedProducts
        )));
        $allowedSlugMap = array_fill_keys($allowedSlugs, true);
        $seedStock = array_values(array_filter(
            $seedStock,
            static fn($entry) => isset($allowedSlugMap[(string)($entry['productSlug'] ?? '')])
        ));
        $seedReviews = array_values(array_filter(
            $seedReviews,
            static fn($entry) => isset($allowedSlugMap[(string)($entry['productSlug'] ?? '')])
        ));

        $stmtCategoria = $conexion->prepare("INSERT INTO categorias (nombre, slug) VALUES (:nombre, :slug) ON DUPLICATE KEY UPDATE nombre = VALUES(nombre)");
        $stmtProducto = $conexion->prepare("
            INSERT INTO productos (nombre, slug, descripcion, precio_base, precio_original, tipo, color, badge, fecha_catalogo, estado)
            VALUES (:nombre, :slug, :descripcion, :precio, :precio_original, :tipo, :color, :badge, :fecha_catalogo, 'publicado')
            ON DUPLICATE KEY UPDATE
                nombre = VALUES(nombre),
                descripcion = VALUES(descripcion),
                precio_base = VALUES(precio_base),
                precio_original = VALUES(precio_original),
                tipo = VALUES(tipo),
                color = VALUES(color),
                badge = VALUES(badge),
                fecha_catalogo = VALUES(fecha_catalogo),
                estado = 'publicado'
        ");
        $stmtGetCategoria = $conexion->prepare("SELECT id FROM categorias WHERE slug = :slug LIMIT 1");
        $stmtGetProducto = $conexion->prepare("SELECT id FROM productos WHERE slug = :slug LIMIT 1");
        $stmtDeleteOpinionesProducto = $conexion->prepare("DELETE FROM opiniones WHERE producto_id = :pid");
        $stmtDeleteProdCat = $conexion->prepare("DELETE FROM productos_categorias WHERE producto_id = :pid");
        $stmtDeleteProducto = $conexion->prepare("DELETE FROM productos WHERE id = :pid");
        $stmtProdCat = $conexion->prepare("INSERT IGNORE INTO productos_categorias (producto_id, categoria_id) VALUES (:pid, :cid)");
        $stmtDeleteImgs = $conexion->prepare("DELETE FROM imagenes_productos WHERE producto_id = :pid");
        $stmtInsertImg = $conexion->prepare("INSERT INTO imagenes_productos (producto_id, url_imagen, posicion) VALUES (:pid, :url, :pos)");
        $stmtDeleteVariantes = $conexion->prepare("DELETE FROM variantes_producto WHERE producto_id = :pid");
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
            ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), estado = 'activo'
        ");
        $stmtGetUsuarioReview = $conexion->prepare("SELECT id FROM usuarios WHERE email = :email LIMIT 1");
        $stmtOpinion = $conexion->prepare("
            INSERT INTO opiniones (usuario_id, producto_id, puntuacion, comentario, creado_en)
            VALUES (:uid, :pid, :puntuacion, :comentario, :creado_en)
            ON DUPLICATE KEY UPDATE
                puntuacion = VALUES(puntuacion),
                comentario = VALUES(comentario),
                creado_en = VALUES(creado_en)
        ");
        $stmtProductosSobrantes = $conexion->query("SELECT id, slug FROM productos");

        foreach ($stmtProductosSobrantes->fetchAll(PDO::FETCH_ASSOC) as $productoExistente) {
            $slugExistente = trim((string)($productoExistente['slug'] ?? ''));
            if ($slugExistente === '' || isset($allowedSlugMap[$slugExistente])) {
                continue;
            }

            $productoIdSobrante = (int)($productoExistente['id'] ?? 0);
            if ($productoIdSobrante <= 0) {
                continue;
            }

            $stmtDeleteOpinionesProducto->execute(['pid' => $productoIdSobrante]);
            $stmtDeleteImgs->execute(['pid' => $productoIdSobrante]);
            $stmtDeleteVariantes->execute(['pid' => $productoIdSobrante]);
            $stmtDeleteProdCat->execute(['pid' => $productoIdSobrante]);
            $stmtDeleteProducto->execute(['pid' => $productoIdSobrante]);
        }

        $productoIdBySlug = [];
        $colorBySlug = [];
        $categoriaIdBySlug = [];

        foreach ($seedProducts as $product) {
            $nombreCategoria = trim((string)($product['category'] ?? 'General'));
            $slugCategoria = dmh_slugify($nombreCategoria);

            if (!isset($categoriaIdBySlug[$slugCategoria])) {
                $stmtCategoria->execute([
                    'nombre' => $nombreCategoria,
                    'slug' => $slugCategoria,
                ]);

                $stmtGetCategoria->execute(['slug' => $slugCategoria]);
                $categoriaIdBySlug[$slugCategoria] = (int)$stmtGetCategoria->fetchColumn();
            }

            $stmtProducto->execute([
                'nombre' => (string)($product['title'] ?? ''),
                'slug' => (string)($product['slug'] ?? ''),
                'descripcion' => (string)($product['description'] ?? ''),
                'precio' => (float)($product['price'] ?? 0),
                'precio_original' => isset($product['originalPrice']) ? (float)$product['originalPrice'] : null,
                'tipo' => trim((string)($product['type'] ?? '')) ?: null,
                'color' => trim((string)($product['color'] ?? '')) ?: null,
                'badge' => trim((string)($product['badge'] ?? '')) ?: null,
                'fecha_catalogo' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($product['createdAt'] ?? ''))
                    ? (string)$product['createdAt']
                    : null,
            ]);

            $stmtGetProducto->execute(['slug' => (string)$product['slug']]);
            $productoId = (int)$stmtGetProducto->fetchColumn();
            if ($productoId <= 0) {
                continue;
            }

            $productoIdBySlug[(string)$product['slug']] = $productoId;
            $colorBySlug[(string)$product['slug']] = trim((string)($product['color'] ?? '')) ?: null;

            $stmtProdCat->execute([
                'pid' => $productoId,
                'cid' => $categoriaIdBySlug[$slugCategoria],
            ]);

            $stmtDeleteImgs->execute(['pid' => $productoId]);
            $imagenes = dmh_resolve_three_images(
                is_array($product['images'] ?? null) ? $product['images'] : [],
                isset($product['image']) ? (string)$product['image'] : null
            );

            foreach ($imagenes as $index => $img) {
                $stmtInsertImg->execute([
                    'pid' => $productoId,
                    'url' => (string)$img,
                    'pos' => (int)$index,
                ]);
            }
        }

        foreach ($seedStock as $stockEntry) {
            $slug = (string)($stockEntry['productSlug'] ?? '');
            $productoId = $productoIdBySlug[$slug] ?? 0;
            if ($productoId <= 0) {
                continue;
            }

            $stmtDeleteVariantes->execute(['pid' => $productoId]);
            $bySize = is_array($stockEntry['bySize'] ?? null) ? $stockEntry['bySize'] : [];

            foreach ($bySize as $talla => $variant) {
                $stmtInsertVariante->execute([
                    'pid' => $productoId,
                    'talla' => (string)$talla,
                    'color' => $colorBySlug[$slug] ?? null,
                    'sku' => (string)($variant['sku'] ?? ''),
                    'stock' => (int)($variant['stock'] ?? 0),
                ]);
            }
        }

        $reviewerIdByEmail = [];
        foreach ($seedReviews as $reviewGroup) {
            $slug = (string)($reviewGroup['productSlug'] ?? '');
            $productoId = $productoIdBySlug[$slug] ?? 0;
            if ($productoId <= 0) {
                continue;
            }

            $items = is_array($reviewGroup['items'] ?? null) ? $reviewGroup['items'] : [];
            foreach ($items as $review) {
                $reviewerName = trim((string)($review['user'] ?? 'Cliente'));
                $reviewerSlug = dmh_slugify($reviewerName);
                $reviewerEmail = $reviewerSlug . '@reviews.local';

                if (!isset($reviewerIdByEmail[$reviewerEmail])) {
                    $stmtUsuarioReview->execute([
                        'nombre' => $reviewerName,
                        'email' => $reviewerEmail,
                        'hash' => password_hash('review1234', PASSWORD_DEFAULT),
                    ]);

                    $stmtGetUsuarioReview->execute(['email' => $reviewerEmail]);
                    $reviewerIdByEmail[$reviewerEmail] = (int)$stmtGetUsuarioReview->fetchColumn();
                }

                $date = trim((string)($review['date'] ?? ''));
                $createdAt = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? ($date . ' 12:00:00') : date('Y-m-d H:i:s');

                $stmtOpinion->execute([
                    'uid' => $reviewerIdByEmail[$reviewerEmail],
                    'pid' => $productoId,
                    'puntuacion' => (int)max(1, min(5, (int)($review['rating'] ?? 0))),
                    'comentario' => (string)($review['comment'] ?? ''),
                    'creado_en' => $createdAt,
                ]);
            }
        }

        $pasos[] = 'Seed de catalogo/stock/reseñas insertado en BD desde catalog_seed.php';
    } else {
        $pasos[] = 'Semilla catalog_seed.php no encontrada, se omite seed de catalogo';
    }

    //devolvemos un json de que todo ha salido correctamente
    echo json_encode([
        "ok" => true,
        "mensaje" => "Instalación completada correctamente",
        "pasos" => $pasos
    ]);

//si hay algun error devolvemos un json con el error
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "error" => "Error durante la instalación",
        "detalle" => $e->getMessage()
    ]);
}