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
    $producto_id = isset($_GET["producto_id"]) ? (int)$_GET["producto_id"] : 0;
    if ($producto_id <= 0) error_response("Falta producto_id", 422);

    $stmt = $conexion->prepare("
        SELECT o.id, o.usuario_id, u.nombre, o.puntuacion, o.comentario, o.estado, o.creado_en
        FROM opiniones o
        JOIN usuarios u ON o.usuario_id = u.id
        WHERE o.producto_id = :pid
          AND (
            o.estado = 'aprobada'
            OR (:uid > 0 AND o.usuario_id = :uid)
          )
        ORDER BY
          CASE
            WHEN o.estado = 'aprobada' THEN 0
            WHEN o.estado = 'pendiente' THEN 1
            ELSE 2
          END,
          o.creado_en DESC
    ");
    $stmt->execute([
        "pid" => $producto_id,
        "uid" => (int)($usuarioId ?? 0),
    ]);

    $opiniones = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(["ok" => true, "opiniones" => $opiniones]);
    exit();
}

if ($metodo === "POST") {
    if (!$usuarioId) error_response("No autenticado", 401);

    $stmtUser = $conexion->prepare("SELECT rol FROM usuarios WHERE id = :uid");
    $stmtUser->execute(["uid" => $usuarioId]);
    $rol = $stmtUser->fetchColumn();
    if ($rol !== "cliente") error_response("Solo miembros pueden opinar", 403);

    $input = json_decode(file_get_contents("php://input"), true);
    $producto_id = isset($input["producto_id"]) ? (int)$input["producto_id"] : 0;
    $puntuacion = isset($input["puntuacion"]) ? (int)$input["puntuacion"] : 0;
    $comentario = trim($input["comentario"] ?? "");

    if ($producto_id <= 0 || $puntuacion < 1 || $puntuacion > 5 || $comentario === "") {
        error_response("Datos inválidos", 422);
    }

    $stmtCheck = $conexion->prepare("SELECT estado FROM opiniones WHERE usuario_id = :uid AND producto_id = :pid LIMIT 1");
    $stmtCheck->execute(["uid" => $usuarioId, "pid" => $producto_id]);
    $estadoExistente = $stmtCheck->fetchColumn();

    if ($estadoExistente !== false) {
        if ($estadoExistente === "pendiente") {
            error_response("Tu opinión sigue pendiente de revisión", 409);
        }
        if ($estadoExistente === "rechazada") {
            error_response("Tu opinión anterior fue rechazada. Elimina la anterior antes de enviar otra", 409);
        }
        error_response("Ya has opinado sobre este producto", 409);
    }

    $stmt = $conexion->prepare("
        INSERT INTO opiniones (usuario_id, producto_id, puntuacion, comentario, estado, creado_en)
        VALUES (:uid, :pid, :punt, :coment, 'pendiente', NOW())
    ");
    $stmt->execute([
        "uid" => $usuarioId,
        "pid" => $producto_id,
        "punt" => $puntuacion,
        "coment" => $comentario
    ]);

    echo json_encode([
        "ok" => true,
        "mensaje" => "Opinión enviada y pendiente de revisión"
    ]);
    exit();
}

if ($metodo === "DELETE") {
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