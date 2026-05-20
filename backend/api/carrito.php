<?php

header("Access-Control-Allow-Origin: http://localhost:4321");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit();
}

session_start();
require_once __DIR__ . "/../conexion.php";

$usuarioId = isset($_SESSION["usuario_id"]) ? (int)$_SESSION["usuario_id"] : 0;
if ($usuarioId <= 0) {
    http_response_code(401);
    echo json_encode(["ok" => false, "error" => "Debes iniciar sesión"]);
    exit;
}

function dmh_map_cart_row(array $row): array
{
    return [
        "id" => isset($row["producto_id"]) ? (int)$row["producto_id"] : 0,
        "slug" => (string)($row["slug"] ?? ""),
        "title" => (string)($row["nombre_producto"] ?? ""),
        "price" => isset($row["precio_unitario"]) ? (float)$row["precio_unitario"] : 0.0,
        "image" => (string)($row["imagen"] ?? ""),
        "color" => (string)($row["color"] ?? ""),
        "size" => (string)($row["talla"] ?? "Única"),
        "sku" => (string)($row["sku"] ?? ""),
        "quantity" => isset($row["cantidad"]) ? (int)$row["cantidad"] : 1,
    ];
}

try {
    if ($_SERVER["REQUEST_METHOD"] === "GET") {
        $stmt = $conexion->prepare(
            "SELECT producto_id, slug, nombre_producto, precio_unitario, imagen, color, talla, sku, cantidad
             FROM carrito_items
             WHERE usuario_id = :uid
             ORDER BY id ASC"
        );
        $stmt->execute(["uid" => $usuarioId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $items = array_map("dmh_map_cart_row", $rows);

        echo json_encode(["ok" => true, "items" => $items]);
        exit;
    }

    if ($_SERVER["REQUEST_METHOD"] === "DELETE") {
        $stmtDelete = $conexion->prepare("DELETE FROM carrito_items WHERE usuario_id = :uid");
        $stmtDelete->execute(["uid" => $usuarioId]);
        echo json_encode(["ok" => true]);
        exit;
    }

    if ($_SERVER["REQUEST_METHOD"] === "PUT") {
        $body = json_decode(file_get_contents("php://input"), true) ?? [];
        $items = $body["items"] ?? [];

        if (!is_array($items)) {
            http_response_code(400);
            echo json_encode(["ok" => false, "error" => "Formato de carrito inválido"]);
            exit;
        }

        $conexion->beginTransaction();

        $stmtDelete = $conexion->prepare("DELETE FROM carrito_items WHERE usuario_id = :uid");
        $stmtDelete->execute(["uid" => $usuarioId]);

        if (count($items) > 0) {
            $stmtInsert = $conexion->prepare(
                "INSERT INTO carrito_items
                (usuario_id, producto_id, slug, nombre_producto, precio_unitario, imagen, color, talla, sku, cantidad)
                 VALUES
                (:uid, :producto_id, :slug, :nombre, :precio, :imagen, :color, :talla, :sku, :cantidad)"
            );

            foreach ($items as $item) {
                $slug = trim((string)($item["slug"] ?? ""));
                $title = trim((string)($item["title"] ?? ""));
                $size = trim((string)($item["size"] ?? "Única"));
                $qty = (int)($item["quantity"] ?? 0);
                $price = (float)($item["price"] ?? 0);

                if ($slug === "" || $title === "" || $size === "" || $qty <= 0 || $price <= 0) {
                    continue;
                }

                $stmtInsert->execute([
                    "uid" => $usuarioId,
                    "producto_id" => isset($item["id"]) ? (int)$item["id"] : null,
                    "slug" => $slug,
                    "nombre" => $title,
                    "precio" => round($price, 2),
                    "imagen" => (string)($item["image"] ?? ""),
                    "color" => (string)($item["color"] ?? ""),
                    "talla" => $size,
                    "sku" => (string)($item["sku"] ?? ""),
                    "cantidad" => $qty,
                ]);
            }
        }

        $conexion->commit();

        echo json_encode(["ok" => true]);
        exit;
    }

    http_response_code(405);
    echo json_encode(["ok" => false, "error" => "Método no permitido"]);
} catch (Throwable $e) {
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }

    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "error" => "No se pudo gestionar el carrito",
        "detalle" => $e->getMessage(),
    ]);
}
