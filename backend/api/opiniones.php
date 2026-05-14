<?php
header("Access-Control-Allow-Origin: http://localhost:4321");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit();
}

session_start();
require_once __DIR__ . "/../conexion.php";

if (!isset($conexion) || !($conexion instanceof PDO)) {
    error_response("No se pudo inicializar la conexión a base de datos", 500);
}

$usuarioId = isset($_SESSION["usuario_id"]) ? (int)$_SESSION["usuario_id"] : null;
$metodo = $_SERVER["REQUEST_METHOD"];

function error_response($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(["ok" => false, "error" => $msg]);
    exit();
}

if ($metodo === "GET") {
    // Listar opiniones de un producto
    $producto_id = isset($_GET["producto_id"]) ? (int)$_GET["producto_id"] : 0;
    if ($producto_id <= 0) error_response("Falta producto_id", 422);
    $stmt = $conexion->prepare("SELECT o.id, o.usuario_id, u.nombre, o.puntuacion, o.comentario, o.creado_en FROM opiniones o JOIN usuarios u ON o.usuario_id = u.id WHERE o.producto_id = :pid ORDER BY o.creado_en DESC");
    $stmt->execute(["pid" => $producto_id]);
    $opiniones = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(["ok" => true, "opiniones" => $opiniones]);
    exit();
}

if ($metodo === "POST") {
    // Solo miembros pueden opinar
    if (!$usuarioId) error_response("No autenticado", 401);
    $stmtUser = $conexion->prepare("SELECT rol FROM usuarios WHERE id = :uid");
    $stmtUser->execute(["uid" => $usuarioId]);
    $rol = $stmtUser->fetchColumn();
    if ($rol !== "cliente") error_response("Solo miembros pueden opinar", 403);

    $input = json_decode(file_get_contents("php://input"), true);
    $producto_id = isset($input["producto_id"]) ? (int)$input["producto_id"] : 0;
    $puntuacion = isset($input["puntuacion"]) ? (int)$input["puntuacion"] : 0;
    $comentario = trim($input["comentario"] ?? "");
    if ($producto_id <= 0 || $puntuacion < 1 || $puntuacion > 5 || $comentario === "") error_response("Datos inválidos", 422);

    // Solo una opinión por usuario y producto
    $stmtCheck = $conexion->prepare("SELECT COUNT(*) FROM opiniones WHERE usuario_id = :uid AND producto_id = :pid");
    $stmtCheck->execute(["uid" => $usuarioId, "pid" => $producto_id]);
    if ($stmtCheck->fetchColumn() > 0) error_response("Ya has opinado sobre este producto", 409);

    $stmt = $conexion->prepare("INSERT INTO opiniones (usuario_id, producto_id, puntuacion, comentario, creado_en) VALUES (:uid, :pid, :punt, :coment, NOW())");
    $stmt->execute([
        "uid" => $usuarioId,
        "pid" => $producto_id,
        "punt" => $puntuacion,
        "coment" => $comentario
    ]);
    echo json_encode(["ok" => true]);
    exit();
}

if ($metodo === "DELETE") {
    // Solo el autor puede borrar
    if (!$usuarioId) error_response("No autenticado", 401);
    $input = json_decode(file_get_contents("php://input"), true);
    $opinion_id = isset($input["opinion_id"]) ? (int)$input["opinion_id"] : 0;
    if ($opinion_id <= 0) error_response("Falta opinion_id", 422);
    $stmt = $conexion->prepare("DELETE FROM opiniones WHERE id = :oid AND usuario_id = :uid");
    $stmt->execute(["oid" => $opinion_id, "uid" => $usuarioId]);
    echo json_encode(["ok" => true, "deleted" => $stmt->rowCount() > 0]);
    exit();
}

error_response("Método no permitido", 405);
