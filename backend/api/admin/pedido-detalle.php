<?php
header("Access-Control-Allow-Origin: http://localhost:4321");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, OPTIONS");
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

$pedidoId = isset($_GET["id"]) ? (int)$_GET["id"] : 0;

if ($pedidoId <= 0) {
    http_response_code(400);
    echo json_encode([
        "ok" => false,
        "error" => "El id del pedido es obligatorio"
    ]);
    exit;
}

try {
    $stmtPedido = $conexion->prepare("
        SELECT
            p.id,
            CONCAT('PED-', LPAD(p.id, 6, '0')) AS referencia,
            COALESCE(NULLIF(u.nombre, ''), NULLIF(p.nombre_invitado, ''), 'Cliente') AS cliente_nombre,
            COALESCE(NULLIF(u.email, ''), NULLIF(p.email_invitado, ''), 'Sin email') AS cliente_email,
            p.estado,
            p.importe_total AS total,
            p.direccion_envio,
            p.creado_en AS fecha
        FROM pedidos p
        LEFT JOIN usuarios u ON u.id = p.usuario_id
        WHERE p.id = :id
        LIMIT 1
    ");
    $stmtPedido->execute(["id" => $pedidoId]);
    $pedido = $stmtPedido->fetch(PDO::FETCH_ASSOC);

    if (!$pedido) {
        http_response_code(404);
        echo json_encode([
            "ok" => false,
            "error" => "Pedido no encontrado"
        ]);
        exit;
    }

    $stmtItems = $conexion->prepare("
        SELECT
            slug,
            nombre_producto AS nombre,
            talla,
            COALESCE(color, '') AS color,
            COALESCE(sku, '') AS sku,
            cantidad,
            precio_unitario AS precio,
            subtotal
        FROM items_pedido
        WHERE pedido_id = :pedido_id
        ORDER BY id ASC
    ");
    $stmtItems->execute(["pedido_id" => $pedidoId]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    $pedido["items"] = $items;

    echo json_encode([
        "ok" => true,
        "detalle" => $pedido
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "error" => "Error al obtener el detalle del pedido",
        "detalle" => $e->getMessage()
    ]);
}