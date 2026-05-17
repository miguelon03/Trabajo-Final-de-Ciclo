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
            u.id,
            u.nombre,
            u.email,
            u.rol,
            u.estado,
            COALESCE(NULLIF(u.telefono, ''), 'Sin teléfono') AS telefono,
            COALESCE(NULLIF(u.ciudad, ''), 'Sin ciudad') AS ciudad,
            u.creado_en,
            u.actualizado_en,
            COALESCE(pu.puntos, 0) AS puntos,
            COUNT(DISTINCT p.id) AS pedidos,
            COUNT(DISTINCT d.id) AS devoluciones,
            COUNT(DISTINCT o.id) AS opiniones
        FROM usuarios u
        LEFT JOIN puntos_usuarios pu ON pu.usuario_id = u.id
        LEFT JOIN pedidos p ON p.usuario_id = u.id
        LEFT JOIN devoluciones d ON d.usuario_id = u.id
        LEFT JOIN opiniones o ON o.usuario_id = u.id
        GROUP BY
            u.id,
            u.nombre,
            u.email,
            u.rol,
            u.estado,
            u.telefono,
            u.ciudad,
            u.creado_en,
            u.actualizado_en,
            pu.puntos
        ORDER BY u.creado_en DESC, u.id DESC
    ");

    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "ok" => true,
        "usuarios" => $usuarios
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "error" => "Error al obtener usuarios",
        "detalle" => $e->getMessage()
    ]);
}