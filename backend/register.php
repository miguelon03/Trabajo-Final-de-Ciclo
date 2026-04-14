<?php

// Permitimos peticiones desde el frontend Astro que corre en localhost:4321
header("Access-Control-Allow-Origin: http://localhost:4321");

// Permitimos enviar cookies y usar la sesión en peticiones cross-origin
header("Access-Control-Allow-Credentials: true");

// Indicamos los métodos HTTP permitidos para este archivo
header("Access-Control-Allow-Methods: POST, OPTIONS");

// Permitimos la cabecera Content-Type en la petición
header("Access-Control-Allow-Headers: Content-Type");

// Si el navegador hace una petición previa OPTIONS, respondemos OK y terminamos
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit();
}

// Iniciamos la sesión para poder guardar el usuario automáticamente tras registrarse
session_start();

// Indicamos que la respuesta será en formato JSON
header("Content-Type: application/json; charset=utf-8");

// Importamos la conexión a la base de datos
require_once __DIR__ . "/conexion.php";

try {
    // Recogemos y limpiamos los datos enviados desde el formulario
    $nombre = trim($_POST["nombre"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $contrasena = trim($_POST["contrasena"] ?? "");
    $acepta = $_POST["acepta"] ?? "";

    // Validamos que los campos obligatorios no estén vacíos
    if ($nombre === "" || $email === "" || $contrasena === "") {
        echo json_encode([
            "ok" => false,
            "error" => "Todos los campos son obligatorios"
        ]);
        exit;
    }

    // Validamos que el email tenga un formato correcto
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode([
            "ok" => false,
            "error" => "El email no es válido"
        ]);
        exit;
    }

    // Validamos que la contraseña tenga al menos 6 caracteres
    if (mb_strlen($contrasena) < 6) {
        echo json_encode([
            "ok" => false,
            "error" => "La contraseña debe tener al menos 6 caracteres"
        ]);
        exit;
    }

    // Comprobamos que el usuario haya aceptado los términos y condiciones
    if (!$acepta) {
        echo json_encode([
            "ok" => false,
            "error" => "Debes aceptar los términos y condiciones"
        ]);
        exit;
    }

    // Buscamos si ya existe un usuario con el mismo email
    $sql = "SELECT id FROM usuarios WHERE email = :email LIMIT 1";
    $stmt = $conexion->prepare($sql);
    $stmt->execute(["email" => $email]);
    $usuarioExistente = $stmt->fetch();

    // Si ya existe, devolvemos error
    if ($usuarioExistente) {
        echo json_encode([
            "ok" => false,
            "error" => "Ya existe una cuenta con ese email"
        ]);
        exit;
    }

    // Ciframos la contraseña con password_hash antes de guardarla
    $hash = password_hash($contrasena, PASSWORD_DEFAULT);

    // Insertamos el nuevo usuario con rol cliente y estado activo
    $sql = "INSERT INTO usuarios (nombre, email, contrasena_hash, rol, estado)
            VALUES (:nombre, :email, :hash, 'cliente', 'activo')";
    $stmt = $conexion->prepare($sql);
    $stmt->execute([
        "nombre" => $nombre,
        "email" => $email,
        "hash" => $hash
    ]);

    // Obtenemos el ID del usuario recién creado
    $idUsuario = $conexion->lastInsertId();

    // Guardamos sus datos en sesión para dejarlo logueado automáticamente
    $_SESSION["usuario_id"] = $idUsuario;
    $_SESSION["usuario_nombre"] = $nombre;
    $_SESSION["usuario_email"] = $email;
    $_SESSION["usuario_rol"] = "cliente";

    // Devolvemos respuesta de éxito
    echo json_encode([
        "ok" => true,
        "mensaje" => "Cuenta creada correctamente",
        "redirect" => "/"
    ]);

} catch (Throwable $e) {
    // Si ocurre cualquier error inesperado, devolvemos error 500
    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "error" => "Error interno del servidor",
        "detalle" => $e->getMessage()
    ]);
}