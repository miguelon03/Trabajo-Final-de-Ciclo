<?php
$dmhAllowedOrigins = ["http://localhost:4321", "https://dripmode.com"];
$dmhOrigin = $_SERVER["HTTP_ORIGIN"] ?? "";
if (in_array($dmhOrigin, $dmhAllowedOrigins, true)) {
    header("Access-Control-Allow-Origin: " . $dmhOrigin);
}
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
    $stmtProductos = $conexion->query("
        SELECT COUNT(*) AS total
        FROM productos
        WHERE estado <> 'archivado'
    ");
    $productos = (int)$stmtProductos->fetchColumn();

    $stmtPedidos = $conexion->query("
        SELECT COUNT(*) AS total
        FROM pedidos
    ");
    $pedidos = (int)$stmtPedidos->fetchColumn();

    $stmtUsuarios = $conexion->query("
        SELECT COUNT(*) AS total
        FROM usuarios
        WHERE rol = 'cliente'
    ");
    $usuarios = (int)$stmtUsuarios->fetchColumn();

    $stmtIngresos = $conexion->query("
        SELECT COALESCE(SUM(importe_total), 0) AS total
        FROM pedidos
        WHERE estado IN ('pagado', 'enviado', 'entregado')
          AND YEAR(creado_en) = YEAR(CURDATE())
          AND MONTH(creado_en) = MONTH(CURDATE())
    ");
    $ingresosMes = (float)$stmtIngresos->fetchColumn();

    echo json_encode([
        "ok" => true,
        "stats" => [
            "productos" => $productos,
            "pedidos" => $pedidos,
            "usuarios" => $usuarios,
            "ingresosMes" => $ingresosMes
        ]
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "error" => "Error al obtener dashboard",
        "detalle" => $e->getMessage()
    ]);
}