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

    $stmtPedido->execute([
        "id" => $pedidoId
    ]);

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
            i.id AS item_pedido_id,
            i.slug,
            i.nombre_producto AS nombre,
            i.talla,
            COALESCE(i.color, '') AS color,
            COALESCE(i.sku, '') AS sku,
            i.cantidad,
            i.precio_unitario AS precio,
            i.subtotal,
            COALESCE(dev.cantidad_devuelta, 0) AS cantidad_devuelta,
            COALESCE(dev.estados, '') AS devolucion_estado,
            COALESCE(dev.slugs, '') AS devolucion_slug
        FROM items_pedido i
        LEFT JOIN (
            SELECT
                item_pedido_id,
                SUM(cantidad_devuelta) AS cantidad_devuelta,
                GROUP_CONCAT(DISTINCT estado ORDER BY estado SEPARATOR ', ') AS estados,
                GROUP_CONCAT(slug ORDER BY id ASC SEPARATOR ', ') AS slugs
            FROM devoluciones
            WHERE pedido_id = :pedido_id_devoluciones
              AND estado IN ('pendiente', 'aceptada')
            GROUP BY item_pedido_id
        ) dev ON dev.item_pedido_id = i.id
        WHERE i.pedido_id = :pedido_id
        ORDER BY i.id ASC
    ");

    $stmtItems->execute([
        "pedido_id" => $pedidoId,
        "pedido_id_devoluciones" => $pedidoId
    ]);

    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    $items = array_map(function (array $item): array {
        $cantidadDevuelta = (int)($item["cantidad_devuelta"] ?? 0);

        $item["item_pedido_id"] = (int)($item["item_pedido_id"] ?? 0);
        $item["cantidad"] = (int)($item["cantidad"] ?? 0);
        $item["cantidad_devuelta"] = $cantidadDevuelta;
        $item["precio"] = (float)($item["precio"] ?? 0);
        $item["subtotal"] = (float)($item["subtotal"] ?? 0);
        $item["es_devuelto"] = $cantidadDevuelta > 0;

        return $item;
    }, $items);

    $itemsDevueltos = array_values(array_filter($items, function (array $item): bool {
        return (int)($item["cantidad_devuelta"] ?? 0) > 0;
    }));

    $pedido["items"] = count($itemsDevueltos) > 0 ? $itemsDevueltos : $items;
    $pedido["mostrando_devoluciones"] = count($itemsDevueltos) > 0;

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