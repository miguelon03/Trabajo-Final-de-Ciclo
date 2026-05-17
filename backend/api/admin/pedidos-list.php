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

try {
    $stmt = $conexion->query("
        SELECT
            p.id,
            CONCAT('PED-', LPAD(p.id, 6, '0')) AS referencia,
            COALESCE(NULLIF(u.nombre, ''), NULLIF(p.nombre_invitado, ''), 'Cliente') AS cliente_nombre,
            COALESCE(NULLIF(u.email, ''), NULLIF(p.email_invitado, ''), 'Sin email') AS cliente_email,
            p.importe_total AS total,
            p.estado,
            p.creado_en AS fecha,
            COALESCE(SUM(ip.cantidad), 0) AS articulos
        FROM pedidos p
        LEFT JOIN usuarios u ON u.id = p.usuario_id
        LEFT JOIN items_pedido ip ON ip.pedido_id = p.id
        GROUP BY
            p.id,
            u.nombre,
            p.nombre_invitado,
            u.email,
            p.email_invitado,
            p.importe_total,
            p.estado,
            p.creado_en
        ORDER BY p.creado_en DESC, p.id DESC
    ");

    $pedidos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "ok" => true,
        "pedidos" => $pedidos
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "error" => "Error al obtener pedidos",
        "detalle" => $e->getMessage()
    ]);
}