<?php

// Permitimos peticiones desde el frontend Astro y dripmode.com
$dmhAllowedOrigins = ["http://localhost:4321", "https://dripmode.com"];
$dmhOrigin = $_SERVER["HTTP_ORIGIN"] ?? "";
if (in_array($dmhOrigin, $dmhAllowedOrigins, true)) {
    header("Access-Control-Allow-Origin: " . $dmhOrigin);
}

// Permitimos uso de cookies/sesión
header("Access-Control-Allow-Credentials: true");

// Permitimos peticiones GET y OPTIONS
header("Access-Control-Allow-Methods: GET, OPTIONS");

// Permitimos la cabecera Content-Type
header("Access-Control-Allow-Headers: Content-Type");

// Respondemos a la preflight request OPTIONS
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit();
}

// Iniciamos la sesión para poder leer los datos guardados del usuario
session_start();

require_once __DIR__ . "/conexion.php";

// Indicamos que la respuesta será JSON
header("Content-Type: application/json; charset=utf-8");

if (isset($_SESSION["usuario_id"])) {
    try {
        // Releemos el usuario desde la base de datos para no depender de un rol
        // viejo guardado en la sesión.
        $stmt = $conexion->prepare("
            SELECT id, nombre, email, rol, estado
            FROM usuarios
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute([
            "id" => (int)$_SESSION["usuario_id"],
        ]);

        $usuarioActual = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$usuarioActual || ($usuarioActual["estado"] ?? "") !== "activo") {
            $_SESSION = [];
            session_destroy();
        } else {
            $_SESSION["usuario_id"] = $usuarioActual["id"];
            $_SESSION["usuario_nombre"] = $usuarioActual["nombre"];
            $_SESSION["usuario_email"] = $usuarioActual["email"];
            $_SESSION["usuario_rol"] = $usuarioActual["rol"];
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            "ok" => false,
            "error" => "No se pudo validar la sesión actual",
            "detalle" => $e->getMessage()
        ]);
        exit;
    }
}

// Devolvemos:
// - si hay sesión iniciada
// - y, si la hay, los datos básicos del usuario, incluyendo puntos
$usuario = null;
if (isset($_SESSION["usuario_id"])) {
    // Consultar puntos del usuario
    $puntos = 0;
    try {
        $stmtPuntos = $conexion->prepare("SELECT COALESCE(SUM(puntos), 0) AS puntos FROM puntos_usuarios WHERE usuario_id = :id");
        $stmtPuntos->execute(["id" => (int)$_SESSION["usuario_id"]]);
        $puntos = (int)($stmtPuntos->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        $puntos = 0;
    }
    $usuario = [
        "id" => $_SESSION["usuario_id"],
        "nombre" => $_SESSION["usuario_nombre"] ?? "",
        "email" => $_SESSION["usuario_email"] ?? "",
        "rol" => $_SESSION["usuario_rol"] ?? "cliente",
        "puntos" => $puntos
    ];
}

echo json_encode([
    "ok" => true,
    "logueado" => isset($_SESSION["usuario_id"]),
    "autenticado" => isset($_SESSION["usuario_id"]),
    "usuario" => $usuario
]);