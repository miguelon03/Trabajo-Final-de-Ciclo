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

$usuarioId = isset($_GET["id"]) ? (int)$_GET["id"] : 0;

if ($usuarioId <= 0) {
    http_response_code(400);
    echo json_encode([
        "ok" => false,
        "error" => "El id del usuario es obligatorio"
    ]);
    exit;
}

try {
    $stmtUsuario = $conexion->prepare("
        SELECT
            u.id,
            u.nombre,
            u.email,
            u.rol,
            u.estado,
            COALESCE(NULLIF(u.telefono, ''), 'Sin teléfono') AS telefono,
            COALESCE(NULLIF(u.direccion, ''), 'Sin dirección') AS direccion,
            COALESCE(NULLIF(u.ciudad, ''), 'Sin ciudad') AS ciudad,
            COALESCE(NULLIF(u.codigo_postal, ''), 'Sin código postal') AS codigo_postal,
            u.creado_en,
            u.actualizado_en,
            COALESCE(pu.puntos, 0) AS puntos
        FROM usuarios u
        LEFT JOIN puntos_usuarios pu ON pu.usuario_id = u.id
        WHERE u.id = :id
        LIMIT 1
    ");
    $stmtUsuario->execute(["id" => $usuarioId]);
    $usuario = $stmtUsuario->fetch(PDO::FETCH_ASSOC);

    if (!$usuario) {
        http_response_code(404);
        echo json_encode([
            "ok" => false,
            "error" => "Usuario no encontrado"
        ]);
        exit;
    }

    $stmtResumen = $conexion->prepare("
        SELECT
            COUNT(DISTINCT p.id) AS pedidos,
            COUNT(DISTINCT d.id) AS devoluciones,
            COUNT(DISTINCT o.id) AS opiniones,
            COUNT(DISTINCT f.id) AS favoritos,
            COALESCE(SUM(CASE WHEN p.estado IN ('pagado', 'enviado', 'entregado', 'devuelto') THEN p.importe_total ELSE 0 END), 0) AS gasto_total
        FROM usuarios u
        LEFT JOIN pedidos p ON p.usuario_id = u.id
        LEFT JOIN devoluciones d ON d.usuario_id = u.id
        LEFT JOIN opiniones o ON o.usuario_id = u.id
        LEFT JOIN usuarios_favoritos f ON f.usuario_id = u.id
        WHERE u.id = :id
        GROUP BY u.id
        LIMIT 1
    ");
    $stmtResumen->execute(["id" => $usuarioId]);
    $resumen = $stmtResumen->fetch(PDO::FETCH_ASSOC) ?: [
        "pedidos" => 0,
        "devoluciones" => 0,
        "opiniones" => 0,
        "favoritos" => 0,
        "gasto_total" => 0,
    ];

    $stmtPedidos = $conexion->prepare("
        SELECT
            CONCAT('PED-', LPAD(id, 6, '0')) AS referencia,
            estado,
            importe_total AS total,
            creado_en AS fecha
        FROM pedidos
        WHERE usuario_id = :id
        ORDER BY creado_en DESC, id DESC
        LIMIT 5
    ");
    $stmtPedidos->execute(["id" => $usuarioId]);
    $pedidos = $stmtPedidos->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "ok" => true,
        "detalle" => [
            "usuario" => $usuario,
            "resumen" => $resumen,
            "pedidos_recientes" => $pedidos,
        ]
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "error" => "Error al obtener el detalle del usuario",
        "detalle" => $e->getMessage()
    ]);
}