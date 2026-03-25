<?php
/*
 * INSTALADOR DE LA BASE DE DATOS DRIPMODE
 * ---------------------------------------
 * Este script:
 *  1. Conecta a MySQL usando PDO
 *  2. Crea la base de datos si no existe
 *  3. Crea todas las tablas necesarias
 *  4. Inserta un usuario administrador inicial
 *
 * Requisitos:
 *  - Apache y MySQL activos en XAMPP
 *  - conexion.php en la misma carpeta
 */


// Falta por añadir la tabla "carrito" para guardar productos antes de finalizar el pedido, pero se puede añadir después sin problemas.

require_once "conexion.php"; // Importamos la conexión PDO

try {
    echo "<h2>Instalando base de datos...</h2>";

    // Crear base de datos si no existe
    $conexion->exec("CREATE DATABASE IF NOT EXISTS dripmode CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "✔ Base de datos creada o ya existente<br>";

    // Seleccionar la base de datos
    $conexion->exec("USE dripmode");

    /*
     * TABLA: usuarios
     */
    $conexion->exec("
        CREATE TABLE IF NOT EXISTS usuarios (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nombre VARCHAR(100) NOT NULL,
            email VARCHAR(150) NOT NULL UNIQUE,
            contraseña_hash VARCHAR(255) NOT NULL,
            rol ENUM('cliente','admin') NOT NULL DEFAULT 'cliente',
            estado ENUM('activo','desactivado') NOT NULL DEFAULT 'activo',
            creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");
    echo "✔ Tabla usuarios creada<br>";

    /*
     * TABLA: puntos_usuarios
     */
    $conexion->exec("
        CREATE TABLE IF NOT EXISTS puntos_usuarios (
            id INT AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT NOT NULL,
            puntos INT NOT NULL DEFAULT 0,
            actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        )
    ");
    echo "✔ Tabla puntos_usuarios creada<br>";

    /*
     * TABLA: historial_puntos
     */
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
    echo "✔ Tabla historial_puntos creada<br>";

    /*
     * TABLA: categorias
     */
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
    echo "✔ Tabla categorias creada<br>";

    /*
     * TABLA: productos
     */
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
    echo "✔ Tabla productos creada<br>";

    /*
    * TABLA: opiniones
    */
    $conexion->exec("
    CREATE TABLE IF NOT EXISTS opiniones (
        id INT AUTO_INCREMENT PRIMARY KEY,
        usuario_id INT NOT NULL,
        producto_id INT NOT NULL,
        puntuacion TINYINT NOT NULL CHECK (puntuacion BETWEEN 1 AND 5),
        comentario TEXT NULL,
        creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (usuario_id, producto_id), -- 1 opinión por usuario por producto
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
        FOREIGN KEY (producto_id) REFERENCES productos(id)
    )
");
    echo "✔ Tabla opiniones creada<br>";

    /*
     * TABLA: productos_categorias
     */
    $conexion->exec("
        CREATE TABLE IF NOT EXISTS productos_categorias (
            producto_id INT NOT NULL,
            categoria_id INT NOT NULL,
            PRIMARY KEY (producto_id, categoria_id),
            FOREIGN KEY (producto_id) REFERENCES productos(id),
            FOREIGN KEY (categoria_id) REFERENCES categorias(id)
        )
    ");
    echo "✔ Tabla productos_categorias creada<br>";

    /*
     * TABLA: imagenes_productos
     */
    $conexion->exec("
        CREATE TABLE IF NOT EXISTS imagenes_productos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            producto_id INT NOT NULL,
            url_imagen VARCHAR(255) NOT NULL,
            posicion INT NOT NULL DEFAULT 0,
            FOREIGN KEY (producto_id) REFERENCES productos(id)
        )
    ");
    echo "✔ Tabla imagenes_productos creada<br>";

    /*
     * TABLA: variantes_producto
     */
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
    echo "✔ Tabla variantes_producto creada<br>";

    /*
     * TABLA: pedidos
     */
    $conexion->exec("
        CREATE TABLE IF NOT EXISTS pedidos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT NOT NULL,
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
    echo "✔ Tabla pedidos creada<br>";

    /*
     * TABLA: items_pedido
     */
    $conexion->exec("
        CREATE TABLE IF NOT EXISTS items_pedido (
            id INT AUTO_INCREMENT PRIMARY KEY,
            pedido_id INT NOT NULL,
            producto_id INT NOT NULL,
            variante_id INT NULL,
            cantidad INT NOT NULL,
            precio_unitario DECIMAL(10,2) NOT NULL,
            subtotal DECIMAL(10,2) NOT NULL,
            FOREIGN KEY (pedido_id) REFERENCES pedidos(id),
            FOREIGN KEY (producto_id) REFERENCES productos(id),
            FOREIGN KEY (variante_id) REFERENCES variantes_producto(id)
        )
    ");
    echo "✔ Tabla items_pedido creada<br>";

    /*
     * TABLA: devoluciones
     */
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
    echo "✔ Tabla devoluciones creada<br>";

    /*
     * TABLA: contenido
     */
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
    echo "✔ Tabla contenido creada<br>";

    /*
     * TABLA: carrusel
     */
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
    echo "✔ Tabla carrusel creada<br>";

    /*
     * TABLA: logs_admin
     */
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
    echo "✔ Tabla logs_admin creada<br>";

    /*
     * TABLA: vistas_producto
     */
    $conexion->exec("
        CREATE TABLE IF NOT EXISTS vistas_producto (
            id INT AUTO_INCREMENT PRIMARY KEY,
            producto_id INT NOT NULL,
            visto_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (producto_id) REFERENCES productos(id)
        )
    ");
    echo "✔ Tabla vistas_producto creada<br>";

    /*
     * Crear usuario administrador inicial
     */
    $password = password_hash("admin123", PASSWORD_DEFAULT);

    $conexion->exec("
        INSERT INTO usuarios (nombre, email, contraseña_hash, rol)
        VALUES ('Administrador', 'admin@dripmode.com', '$password', 'admin')
        ON DUPLICATE KEY UPDATE email=email
    ");

    echo "<br><strong>✔ Instalación completada. Usuario admin creado.</strong>";
} catch (PDOException $e) {
    die("<br><br><strong>Error durante la instalación:</strong> " . $e->getMessage());
}
