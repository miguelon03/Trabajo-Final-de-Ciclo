<?php
header("Access-Control-Allow-Origin: http://localhost:4321");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit();
}

session_start();

if (!isset($_SESSION["usuario_id"]) || ($_SESSION["usuario_rol"] ?? "") !== "admin") {
    http_response_code(403);
    echo json_encode([
        "ok" => false,
        "error" => "Acceso no autorizado"
    ]);
    exit;
}

require_once __DIR__ . "/../../conexion.php";

$body = json_decode(file_get_contents("php://input"), true) ?? [];
$pedidoId = (int)($body["id"] ?? 0);
$estado = trim((string)($body["estado"] ?? ""));
$estadosPermitidos = ["pendiente", "pagado", "enviado", "entregado", "devuelto"];

if ($pedidoId <= 0) {
    http_response_code(400);
    echo json_encode([
        "ok" => false,
        "error" => "El id del pedido es obligatorio"
    ]);
    exit;
}

if (!in_array($estado, $estadosPermitidos, true)) {
    http_response_code(400);
    echo json_encode([
        "ok" => false,
        "error" => "Estado de pedido no válido"
    ]);
    exit;
}

try {
    $stmtExiste = $conexion->prepare("
        SELECT id
        FROM pedidos
        WHERE id = :id
        LIMIT 1
    ");
    $stmtExiste->execute(["id" => $pedidoId]);

    if (!$stmtExiste->fetchColumn()) {
        http_response_code(404);
        echo json_encode([
            "ok" => false,
            "error" => "Pedido no encontrado"
        ]);
        exit;
    }

    $stmt = $conexion->prepare("
        UPDATE pedidos
        SET estado = :estado
        WHERE id = :id
    ");
    $stmt->execute([
        "estado" => $estado,
        "id" => $pedidoId,
    ]);

    echo json_encode([
        "ok" => true,
        "mensaje" => "Estado actualizado correctamente"
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "error" => "Error al actualizar el estado del pedido",
        "detalle" => $e->getMessage()
    ]);
}