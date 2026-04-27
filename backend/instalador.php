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
            estado ENUM('borrador','publicado','archivado') NOT NULL DEFAULT 'borrador',
            creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");
    $pasos[] = "Tabla productos creada";

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
            pedido_id INT NOT NULL,
            usuario_id INT NOT NULL,
            estado ENUM('solicitada','aprobada','rechazada','procesada') NOT NULL DEFAULT 'solicitada',
            motivo TEXT NOT NULL,
            creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (pedido_id) REFERENCES pedidos(id),
            FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        )
    ");
    $pasos[] = "Tabla devoluciones creada";
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
        ON DUPLICATE KEY UPDATE email = email
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
        ON DUPLICATE KEY UPDATE email = email
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