<?php

//cabeceras CORS para permitir peticiones desde el frontend de Astro en localhost
header("Access-Control-Allow-Origin: http://localhost:4321");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Si el navegador hace una petición previa OPTIONS, respondemos OK y terminamos
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit();
}

// Iniciamos la sesión para poder guardar los datos del usuario una vez haga login
session_start();

// Indicamos que la respuesta será JSON
header("Content-Type: application/json; charset=utf-8");

// necesitamos una vez el archivo conexion.php
require_once __DIR__ . "/conexion.php";

try {
    // Recogemos y limpiamos el email enviado desde el formulario
    $email = trim($_POST["email"] ?? "");

    // Recogemos y limpiamos la contraseña enviada desde el formulario
    $contrasena = trim($_POST["contrasena"] ?? "");

    // Comprobamos que ambos campos tengan valor si no devolvemos un json con false y el error
    if ($email === "" || $contrasena === "") {
        echo json_encode([
            "ok" => false,
            "error" => "Email y contraseña son obligatorios"
        ]);
        exit;
    }

    // Preparamos la consulta para buscar al usuario por email
    // Seleccionamos también el hash de la contraseña, el rol y el estado
    $sql = "SELECT id, nombre, email, contrasena_hash, rol, estado
            FROM usuarios
            WHERE email = :email
            LIMIT 1";
    $stmt = $conexion->prepare($sql);

    // Ejecutamos la consulta pasando el email como parámetro
    $stmt->execute(["email" => $email]);

    // Obtenemos el usuario encontrado como array asociativo
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    // Si no existe ningún usuario con ese email, devolvemos error
    if (!$usuario) {
        echo json_encode([
            "ok" => false,
            "error" => "Usuario o contraseña incorrectos"
        ]);
        exit;
    }

    // Si el usuario existe pero su cuenta no está activa, no permitimos el login
    if ($usuario["estado"] !== "activo") {
        echo json_encode([
            "ok" => false,
            "error" => "Tu cuenta está desactivada"
        ]);
        exit;
    }

    // Comparamos la contraseña escrita por el usuario con el hash guardado en la base de datos
    // password_verify devuelve true si coinciden
    if (!password_verify($contrasena, $usuario["contrasena_hash"])) {
        echo json_encode([
            "ok" => false,
            "error" => "Usuario o contraseña incorrectos"
        ]);
        exit;
    }

    // Si todo es correcto, guardamos los datos del usuario en la sesión
    $_SESSION["usuario_id"] = $usuario["id"];
    $_SESSION["usuario_nombre"] = $usuario["nombre"];
    $_SESSION["usuario_email"] = $usuario["email"];
    $_SESSION["usuario_rol"] = $usuario["rol"];

    // Definimos a qué página se le enviará según su rol
    $redirect = $usuario["rol"] === "admin" ? "/admin" : "/usuario";

    // Devolvemos login correcto
    echo json_encode([
        "ok" => true,
        "mensaje" => "Login correcto",
        "redirect" => $redirect,
        "rol" => $usuario["rol"]
    ]);

} catch (Throwable $e) {
    // Si ocurre cualquier error inesperado, devolvemos error 500 en JSON
    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "error" => "Error interno del servidor",
        "detalle" => $e->getMessage()
    ]);
}