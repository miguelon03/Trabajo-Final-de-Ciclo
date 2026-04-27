<?php

header("Access-Control-Allow-Origin: http://localhost:4321");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit();
}

session_start();
require_once __DIR__ . "/../conexion.php";

$usuarioId = isset($_SESSION["usuario_id"]) ? (int)$_SESSION["usuario_id"] : null;

// ─────────────────────────────────────────────────────────────────────────────
// GET: listar pedidos del usuario autenticado.
// ─────────────────────────────────────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "GET") {
    if (!$usuarioId) {
        http_response_code(401);
        echo json_encode(["ok" => false, "error" => "Debes iniciar sesión"]);
        exit;
    }

    try {
        $stmtPedidos = $conexion->prepare("
            SELECT id, estado, importe_total, direccion_envio, creado_en
            FROM pedidos
            WHERE usuario_id = :uid
            ORDER BY creado_en DESC
        ");
        $stmtPedidos->execute(["uid" => $usuarioId]);
        $pedidos = $stmtPedidos->fetchAll();

        foreach ($pedidos as &$pedido) {
            $stmtItems = $conexion->prepare("
                SELECT slug, nombre_producto, talla, color, sku, cantidad, precio_unitario, subtotal
                FROM items_pedido
                WHERE pedido_id = :pid
            ");
            $stmtItems->execute(["pid" => $pedido["id"]]);
            $pedido["items"] = $stmtItems->fetchAll();
        }
        unset($pedido);

        echo json_encode(["ok" => true, "pedidos" => $pedidos]);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(["ok" => false, "error" => "Error al obtener pedidos", "detalle" => $e->getMessage()]);
        exit;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// POST: crear pedido (usuario autenticado o invitado).
// ─────────────────────────────────────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        $raw  = file_get_contents("php://input");
        $body = json_decode($raw, true) ?? [];

        // ── Validar items ──────────────────────────────────────────────────
        $items = $body["items"] ?? [];
        if (!is_array($items) || count($items) === 0) {
            http_response_code(400);
            echo json_encode(["ok" => false, "error" => "El carrito está vacío"]);
            exit;
        }

        // ── Dirección de envío ─────────────────────────────────────────────
        $direccionEnvio = trim((string)($body["direccion_envio"] ?? ""));
        if ($direccionEnvio === "") {
            http_response_code(400);
            echo json_encode(["ok" => false, "error" => "La dirección de envío es obligatoria"]);
            exit;
        }

        // ── Datos de invitado (obligatorios si no hay sesión) ──────────────
        $nombreInvitado = null;
        $emailInvitado  = null;

        if (!$usuarioId) {
            $nombreInvitado = trim((string)($body["nombre"] ?? ""));
            $emailInvitado  = trim((string)($body["email"] ?? ""));

            if ($nombreInvitado === "" || $emailInvitado === "") {
                http_response_code(400);
                echo json_encode(["ok" => false, "error" => "Nombre y email son obligatorios para comprar sin cuenta"]);
                exit;
            }

            if (!filter_var($emailInvitado, FILTER_VALIDATE_EMAIL)) {
                http_response_code(400);
                echo json_encode(["ok" => false, "error" => "El email no es válido"]);
                exit;
            }
        }

        // ── Calcular total y validar items ─────────────────────────────────
        $totalCalculado = 0.0;
        $itemsLimpios   = [];

        foreach ($items as $item) {
            $slug     = trim((string)($item["slug"] ?? ""));
            $nombre   = trim((string)($item["title"] ?? ""));
            $talla    = trim((string)($item["size"] ?? "Única"));
            $color    = trim((string)($item["color"] ?? ""));
            $sku      = trim((string)($item["sku"] ?? ""));
            $cantidad = (int)($item["quantity"] ?? 0);
            $precio   = round((float)($item["price"] ?? 0), 2);

            if ($slug === "" || $nombre === "" || $cantidad <= 0 || $precio <= 0) {
                http_response_code(400);
                echo json_encode(["ok" => false, "error" => "Datos de producto inválidos en el carrito"]);
                exit;
            }

            // Validar slug para evitar datos sucios.
            if (!preg_match('/^[a-z0-9\-]+$/', $slug)) {
                http_response_code(400);
                echo json_encode(["ok" => false, "error" => "Slug de producto inválido: $slug"]);
                exit;
            }

            $subtotal = round($precio * $cantidad, 2);
            $totalCalculado += $subtotal;

            $itemsLimpios[] = [
                "slug"            => $slug,
                "nombre_producto" => $nombre,
                "talla"           => $talla,
                "color"           => $color ?: null,
                "sku"             => $sku ?: null,
                "cantidad"        => $cantidad,
                "precio_unitario" => $precio,
                "subtotal"        => $subtotal,
            ];
        }

        $totalCalculado = round($totalCalculado, 2);

        // Envío gratis por encima de 80 €, si no 4,95 €.
        $envio = $totalCalculado >= 80 ? 0.0 : 4.95;
        $importeTotal = round($totalCalculado + $envio, 2);

        // ── Insertar pedido ────────────────────────────────────────────────
        $conexion->beginTransaction();

        $stmtPedido = $conexion->prepare("
            INSERT INTO pedidos (usuario_id, nombre_invitado, email_invitado, importe_total, direccion_envio)
            VALUES (:uid, :nombre_invitado, :email_invitado, :total, :direccion)
        ");
        $stmtPedido->execute([
            "uid"             => $usuarioId,
            "nombre_invitado" => $nombreInvitado,
            "email_invitado"  => $emailInvitado,
            "total"           => $importeTotal,
            "direccion"       => $direccionEnvio,
        ]);

        $pedidoId = (int)$conexion->lastInsertId();

        $stmtItem = $conexion->prepare("
            INSERT INTO items_pedido
                (pedido_id, slug, nombre_producto, talla, color, sku, cantidad, precio_unitario, subtotal)
            VALUES
                (:pedido_id, :slug, :nombre, :talla, :color, :sku, :cantidad, :precio, :subtotal)
        ");

        foreach ($itemsLimpios as $item) {
            $stmtItem->execute([
                "pedido_id" => $pedidoId,
                "slug"      => $item["slug"],
                "nombre"    => $item["nombre_producto"],
                "talla"     => $item["talla"],
                "color"     => $item["color"],
                "sku"       => $item["sku"],
                "cantidad"  => $item["cantidad"],
                "precio"    => $item["precio_unitario"],
                "subtotal"  => $item["subtotal"],
            ]);
        }

        $conexion->commit();

        echo json_encode([
            "ok"          => true,
            "mensaje"     => "Pedido realizado correctamente",
            "pedido_id"   => $pedidoId,
            "total"       => $importeTotal,
            "envio"       => $envio,
        ]);
        exit;
    } catch (Throwable $e) {
        if ($conexion->inTransaction()) {
            $conexion->rollBack();
        }
        http_response_code(500);
        echo json_encode(["ok" => false, "error" => "Error al crear el pedido", "detalle" => $e->getMessage()]);
        exit;
    }
}

http_response_code(405);
echo json_encode(["ok" => false, "error" => "Método no permitido"]);
