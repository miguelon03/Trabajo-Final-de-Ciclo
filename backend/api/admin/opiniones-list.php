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
    $estado = trim((string)($_GET["estado"] ?? ""));
    $buscar = trim((string)($_GET["buscar"] ?? ""));

    $sql = "
        SELECT
            o.id,
            o.usuario_id,
            o.producto_id,
            o.puntuacion,
            o.comentario,
            o.estado,
            o.creado_en,
            u.nombre AS usuario_nombre,
            p.nombre AS producto_nombre,
            p.slug AS producto_slug,
            EXISTS (
                SELECT 1
                FROM pedidos ped
                INNER JOIN items_pedido ip ON ip.pedido_id = ped.id
                WHERE ped.usuario_id = o.usuario_id
                  AND ip.slug = p.slug
                  AND ped.estado IN ('pagado', 'enviado', 'entregado', 'devuelto')
            ) AS compra_verificada
        FROM opiniones o
        INNER JOIN usuarios u ON u.id = o.usuario_id
        INNER JOIN productos p ON p.id = o.producto_id
        WHERE 1=1
    ";

    $params = [];

    if ($estado !== "" && in_array($estado, ["pendiente", "aprobada", "rechazada"], true)) {
        $sql .= " AND o.estado = :estado ";
        $params["estado"] = $estado;
    }

    if ($buscar !== "") {
        $sql .= " AND (
            u.nombre LIKE :buscar
            OR p.nombre LIKE :buscar
            OR p.slug LIKE :buscar
            OR o.comentario LIKE :buscar
        ) ";
        $params["buscar"] = "%" . $buscar . "%";
    }

    $sql .= "
        ORDER BY
            CASE
                WHEN o.estado = 'pendiente' THEN 0
                WHEN o.estado = 'aprobada' THEN 1
                ELSE 2
            END,
            o.creado_en DESC,
            o.id DESC
    ";

    $stmt = $conexion->prepare($sql);
    $stmt->execute($params);
    $opiniones = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "ok" => true,
        "opiniones" => $opiniones
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "error" => "Error al obtener opiniones",
        "detalle" => $e->getMessage()
    ]);
}