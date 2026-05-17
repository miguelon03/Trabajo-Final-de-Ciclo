<?php

header("Access-Control-Allow-Origin: http://localhost:4321");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, PATCH, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit();
}

session_start();
require_once __DIR__ . "/../conexion.php";

$usuarioId = isset($_SESSION["usuario_id"]) ? (int)$_SESSION["usuario_id"] : 0;
$usuarioRol = isset($_SESSION["usuario_rol"]) ? (string)$_SESSION["usuario_rol"] : "";

function dmh_json_body(): array {
    $raw = file_get_contents("php://input");
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function dmh_is_admin(string $rol): bool {
    return mb_strtolower(trim($rol)) === "admin";
}

function dmh_return_slug(): string {
    return "DEV-" . strtoupper(bin2hex(random_bytes(4)));
}

function dmh_status_rank(string $status): int {
    $status = mb_strtolower(trim($status));
    if ($status === "pendiente") {
        return 0;
    }
    if ($status === "aceptada") {
        return 1;
    }
    return 2;
}

function dmh_try_restore_stock(PDO $conexion, array $row): void {
    $cantidad = (int)($row["cantidad_devuelta"] ?? 0);
    if ($cantidad <= 0) {
        return;
    }

    $sku = trim((string)($row["sku"] ?? ""));
    $slug = trim((string)($row["slug"] ?? ""));
    $talla = trim((string)($row["talla"] ?? "Única"));
    $color = trim((string)($row["color"] ?? ""));

    if ($sku !== "") {
        $stmtSku = $conexion->prepare(
            "UPDATE variantes_producto
             SET stock = stock + :cantidad
             WHERE sku = :sku
             LIMIT 1"
        );
        $stmtSku->execute([
            "cantidad" => $cantidad,
            "sku" => $sku,
        ]);

        if ($stmtSku->rowCount() > 0) {
            return;
        }
    }

    $stmtVariant = $conexion->prepare(
        "UPDATE variantes_producto vp
         JOIN productos p ON p.id = vp.producto_id
         SET vp.stock = vp.stock + :cantidad
         WHERE p.slug = :slug
           AND vp.talla = :talla
           AND (
             (:color = '' AND (vp.color IS NULL OR vp.color = ''))
             OR vp.color = :color
           )
         ORDER BY vp.id ASC
         LIMIT 1"
    );

    $stmtVariant->execute([
        "cantidad" => $cantidad,
        "slug" => $slug,
        "talla" => $talla,
        "color" => $color,
    ]);
}

function dmh_update_pedido_estado_si_todo_devuelto(PDO $conexion, int $pedidoId): void {
    $stmtItemCount = $conexion->prepare(
        "SELECT id, cantidad FROM items_pedido WHERE pedido_id = :pedido_id"
    );
    $stmtItemCount->execute(["pedido_id" => $pedidoId]);
    $itemsPedido = $stmtItemCount->fetchAll(PDO::FETCH_ASSOC);

    $todoDevuelto = true;

    foreach ($itemsPedido as $itemPedido) {
        $itemId = (int)$itemPedido["id"];
        $cantidadItem = (int)$itemPedido["cantidad"];

        $stmtAccepted = $conexion->prepare(
            "SELECT COALESCE(SUM(cantidad_devuelta), 0)
             FROM devoluciones
             WHERE item_pedido_id = :item_id
               AND estado = 'aceptada'"
        );
        $stmtAccepted->execute(["item_id" => $itemId]);
        $cantidadAceptada = (int)$stmtAccepted->fetchColumn();

        if ($cantidadAceptada < $cantidadItem) {
            $todoDevuelto = false;
            break;
        }
    }

    if ($todoDevuelto) {
        $stmtPedido = $conexion->prepare(
            "UPDATE pedidos
             SET estado = 'devuelto'
             WHERE id = :pedido_id"
        );
        $stmtPedido->execute(["pedido_id" => $pedidoId]);
    }
}

function dmh_update_return_status(PDO $conexion, int $devolucionId, string $estado): array {
    if ($devolucionId <= 0) {
        throw new InvalidArgumentException("ID de devolución inválido");
    }

    if (!in_array($estado, ["pendiente", "aceptada", "rechazada"], true)) {
        throw new InvalidArgumentException("Estado de devolución inválido");
    }

    $conexion->beginTransaction();

    $stmtLock = $conexion->prepare(
        "SELECT d.id, d.estado, d.pedido_id, d.item_pedido_id, d.cantidad_devuelta,
                i.slug, i.talla, i.color, i.sku
         FROM devoluciones d
         INNER JOIN items_pedido i ON i.id = d.item_pedido_id
         WHERE d.id = :id
         LIMIT 1
         FOR UPDATE"
    );
    $stmtLock->execute(["id" => $devolucionId]);
    $row = $stmtLock->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $conexion->rollBack();
        throw new RuntimeException("Devolución no encontrada");
    }

    $estadoAnterior = mb_strtolower(trim((string)($row["estado"] ?? "")));

    $stmtUpdate = $conexion->prepare(
        "UPDATE devoluciones
         SET estado = :estado
         WHERE id = :id"
    );
    $stmtUpdate->execute([
        "estado" => $estado,
        "id" => $devolucionId,
    ]);

    if ($estadoAnterior !== "aceptada" && $estado === "aceptada") {
        dmh_try_restore_stock($conexion, $row);
    }

    if ($estado === "aceptada") {
        dmh_update_pedido_estado_si_todo_devuelto($conexion, (int)$row["pedido_id"]);
    }

    $conexion->commit();

    return [
        "ok" => true,
        "mensaje" => "Estado de devolución actualizado",
        "devolucion_id" => $devolucionId,
        "estado" => $estado,
    ];
}

if ($usuarioId <= 0) {
    http_response_code(401);
    echo json_encode(["ok" => false, "error" => "Debes iniciar sesión"]);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "GET") {
    try {
        $isAdmin = dmh_is_admin($usuarioRol);

        $baseSql = "
            SELECT
                d.id,
                d.slug,
                d.pedido_id,
                d.item_pedido_id,
                d.usuario_id,
                d.cantidad_devuelta,
                d.estado,
                d.motivo,
                d.creado_en,
                d.actualizado_en,
                u.nombre AS usuario_nombre,
                u.email AS usuario_email,
                p.estado AS pedido_estado,
                i.slug AS producto_slug,
                i.nombre_producto,
                i.talla,
                i.color,
                i.sku,
                i.cantidad AS cantidad_comprada,
                i.precio_unitario,
                i.subtotal
            FROM devoluciones d
            INNER JOIN usuarios u ON u.id = d.usuario_id
            INNER JOIN pedidos p ON p.id = d.pedido_id
            INNER JOIN items_pedido i ON i.id = d.item_pedido_id
        ";

        if ($isAdmin) {
            $stmt = $conexion->query($baseSql . " ORDER BY d.creado_en DESC");
        } else {
            $stmt = $conexion->prepare($baseSql . " WHERE d.usuario_id = :uid ORDER BY d.creado_en DESC");
            $stmt->execute(["uid" => $usuarioId]);
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        usort($rows, function (array $a, array $b): int {
            $rankCmp = dmh_status_rank((string)$a["estado"]) <=> dmh_status_rank((string)$b["estado"]);
            if ($rankCmp !== 0) {
                return $rankCmp;
            }
            return strcmp((string)$b["creado_en"], (string)$a["creado_en"]);
        });

        echo json_encode(["ok" => true, "devoluciones" => $rows]);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            "ok" => false,
            "error" => "No se pudieron cargar las devoluciones",
            "detalle" => $e->getMessage()
        ]);
        exit;
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        $body = dmh_json_body();

        if (dmh_is_admin($usuarioRol) && isset($body["devolucion_id"], $body["estado"])) {
            $devolucionId = (int)($body["devolucion_id"] ?? 0);
            $estado = mb_strtolower(trim((string)($body["estado"] ?? "")));

            $resultado = dmh_update_return_status($conexion, $devolucionId, $estado);
            echo json_encode($resultado);
            exit;
        }

        $pedidoId = (int)($body["pedido_id"] ?? 0);
        $itemPedidoId = (int)($body["item_pedido_id"] ?? 0);
        $cantidad = (int)($body["cantidad"] ?? 0);
        $motivo = trim((string)($body["motivo"] ?? ""));

        if ($pedidoId <= 0 || $itemPedidoId <= 0) {
            http_response_code(400);
            echo json_encode(["ok" => false, "error" => "Pedido o artículo inválido"]);
            exit;
        }

        if ($cantidad <= 0) {
            http_response_code(400);
            echo json_encode(["ok" => false, "error" => "Indica una cantidad válida"]);
            exit;
        }

        if (mb_strlen($motivo) > 500) {
            http_response_code(400);
            echo json_encode(["ok" => false, "error" => "El motivo es demasiado largo"]);
            exit;
        }

        $stmtItem = $conexion->prepare(
            "SELECT
                i.id,
                i.pedido_id,
                i.slug,
                i.nombre_producto,
                i.talla,
                i.color,
                i.sku,
                i.cantidad,
                p.usuario_id,
                p.estado AS pedido_estado
             FROM items_pedido i
             INNER JOIN pedidos p ON p.id = i.pedido_id
             WHERE i.id = :item_id
               AND i.pedido_id = :pedido_id
             LIMIT 1"
        );
        $stmtItem->execute([
            "item_id" => $itemPedidoId,
            "pedido_id" => $pedidoId,
        ]);
        $item = $stmtItem->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            http_response_code(404);
            echo json_encode(["ok" => false, "error" => "No se encontró el artículo del pedido"]);
            exit;
        }

        if ((int)($item["usuario_id"] ?? 0) !== $usuarioId) {
            http_response_code(403);
            echo json_encode(["ok" => false, "error" => "No autorizado para devolver este artículo"]);
            exit;
        }

        $pedidoEstado = mb_strtolower(trim((string)($item["pedido_estado"] ?? "")));
        if ($pedidoEstado !== "entregado" && $pedidoEstado !== "devuelto") {
            http_response_code(400);
            echo json_encode(["ok" => false, "error" => "Solo puedes devolver artículos de pedidos entregados"]);
            exit;
        }

        $cantidadComprada = (int)($item["cantidad"] ?? 0);

        $stmtReturned = $conexion->prepare(
            "SELECT COALESCE(SUM(cantidad_devuelta), 0)
             FROM devoluciones
             WHERE item_pedido_id = :item_id
               AND usuario_id = :uid
               AND estado IN ('pendiente', 'aceptada')"
        );
        $stmtReturned->execute([
            "item_id" => $itemPedidoId,
            "uid" => $usuarioId,
        ]);
        $cantidadReservada = (int)$stmtReturned->fetchColumn();

        $cantidadDisponible = max(0, $cantidadComprada - $cantidadReservada);

        if ($cantidad > $cantidadDisponible) {
            http_response_code(400);
            echo json_encode([
                "ok" => false,
                "error" => "No puedes devolver más unidades de las disponibles",
                "disponible" => $cantidadDisponible,
            ]);
            exit;
        }

        $slug = dmh_return_slug();

        $stmtInsert = $conexion->prepare(
            "INSERT INTO devoluciones
                (slug, pedido_id, item_pedido_id, usuario_id, cantidad_devuelta, estado, motivo)
             VALUES
                (:slug, :pedido_id, :item_pedido_id, :usuario_id, :cantidad_devuelta, 'pendiente', :motivo)"
        );

        $stmtInsert->execute([
            "slug" => $slug,
            "pedido_id" => $pedidoId,
            "item_pedido_id" => $itemPedidoId,
            "usuario_id" => $usuarioId,
            "cantidad_devuelta" => $cantidad,
            "motivo" => $motivo,
        ]);

        echo json_encode([
            "ok" => true,
            "mensaje" => "Solicitud de devolución enviada",
            "devolucion" => [
                "id" => (int)$conexion->lastInsertId(),
                "slug" => $slug,
                "estado" => "pendiente",
                "pedido_id" => $pedidoId,
                "item_pedido_id" => $itemPedidoId,
                "cantidad_devuelta" => $cantidad,
                "motivo" => $motivo,
            ],
        ]);
        exit;
    } catch (InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => $e->getMessage()]);
        exit;
    } catch (RuntimeException $e) {
        if ($conexion->inTransaction()) {
            $conexion->rollBack();
        }

        $status = $e->getMessage() === "Devolución no encontrada" ? 404 : 500;
        http_response_code($status);
        echo json_encode(["ok" => false, "error" => $e->getMessage()]);
        exit;
    } catch (Throwable $e) {
        if ($conexion->inTransaction()) {
            $conexion->rollBack();
        }

        if ((int)$e->getCode() === 23000) {
            http_response_code(409);
            echo json_encode(["ok" => false, "error" => "No se pudo crear la devolución por conflicto de datos"]);
            exit;
        }

        http_response_code(500);
        echo json_encode([
            "ok" => false,
            "error" => "No se pudo registrar la devolución",
            "detalle" => $e->getMessage()
        ]);
        exit;
    }
}

if ($_SERVER["REQUEST_METHOD"] === "PATCH") {
    if (!dmh_is_admin($usuarioRol)) {
        http_response_code(403);
        echo json_encode(["ok" => false, "error" => "Solo admin puede gestionar devoluciones"]);
        exit;
    }

    try {
        $body = dmh_json_body();
        $devolucionId = (int)($body["devolucion_id"] ?? 0);
        $estado = mb_strtolower(trim((string)($body["estado"] ?? "")));

        $resultado = dmh_update_return_status($conexion, $devolucionId, $estado);
        echo json_encode($resultado);
        exit;
    } catch (InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => $e->getMessage()]);
        exit;
    } catch (RuntimeException $e) {
        if ($conexion->inTransaction()) {
            $conexion->rollBack();
        }

        $status = $e->getMessage() === "Devolución no encontrada" ? 404 : 500;
        http_response_code($status);
        echo json_encode(["ok" => false, "error" => $e->getMessage()]);
        exit;
    } catch (Throwable $e) {
        if ($conexion->inTransaction()) {
            $conexion->rollBack();
        }

        http_response_code(500);
        echo json_encode([
            "ok" => false,
            "error" => "No se pudo actualizar la devolución",
            "detalle" => $e->getMessage()
        ]);
        exit;
    }
}

http_response_code(405);
echo json_encode(["ok" => false, "error" => "Método no permitido"]);