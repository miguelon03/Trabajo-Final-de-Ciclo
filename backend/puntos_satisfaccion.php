<?php

/**
 * puntos_satisfaccion.php
 *
 * Lógica de "puntos diferidos": los puntos de un pedido NO se otorgan al pagar,
 * sino al confirmar la satisfacción de cada producto (o automáticamente a los
 * 30 días de la entrega). Esto evita el fraude de ganar puntos, gastarlos y
 * luego devolver el pedido.
 *
 * Funciones:
 *  - dmh_confirmar_item_satisfaccion(): confirma un producto y otorga sus puntos.
 *  - dmh_sweep_confirmaciones_vencidas(): confirma automáticamente los productos
 *    entregados hace más de 30 días.
 */

/**
 * Confirma la satisfacción de un producto del pedido y otorga sus puntos.
 *
 * Reglas:
 *  - El pedido debe estar entregado.
 *  - El producto debe estar en estado de puntos 'pendiente'.
 *  - No puede tener una devolución PENDIENTE (primero hay que resolverla).
 *  - Los puntos otorgados = 1 punto por € de las unidades NO devueltas.
 *
 * Devuelve ["ok" => bool, "puntos_otorgados" => int, "item_pedido_id" => int, ...].
 * No lanza excepciones (las captura y devuelve ok=false).
 */
function dmh_confirmar_item_satisfaccion(PDO $conexion, int $itemPedidoId, int $usuarioId, bool $automatico = false): array
{
    try {
        $conexion->beginTransaction();

        $stmt = $conexion->prepare(
            "SELECT i.id, i.pedido_id, i.cantidad, i.precio_unitario, i.puntos_estado, i.puntos_potenciales,
                    p.usuario_id, p.estado AS pedido_estado
             FROM items_pedido i
             INNER JOIN pedidos p ON p.id = i.pedido_id
             WHERE i.id = :id
             LIMIT 1
             FOR UPDATE"
        );
        $stmt->execute(["id" => $itemPedidoId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            $conexion->rollBack();
            return ["ok" => false, "error" => "Artículo no encontrado"];
        }

        if ((int)$item["usuario_id"] !== $usuarioId) {
            $conexion->rollBack();
            return ["ok" => false, "error" => "No autorizado para confirmar este artículo"];
        }

        $estadoPedido = mb_strtolower(trim((string)($item["pedido_estado"] ?? "")));
        if ($estadoPedido !== "entregado" && $estadoPedido !== "devuelto") {
            $conexion->rollBack();
            return ["ok" => false, "error" => "Solo puedes confirmar productos de pedidos entregados"];
        }

        if (mb_strtolower(trim((string)($item["puntos_estado"] ?? ""))) !== "pendiente") {
            $conexion->rollBack();
            return ["ok" => false, "error" => "Este producto ya estaba confirmado", "ya_confirmado" => true];
        }

        // No se puede confirmar si hay una devolución PENDIENTE de este producto.
        $stmtPend = $conexion->prepare(
            "SELECT COALESCE(SUM(cantidad_devuelta), 0)
             FROM devoluciones
             WHERE item_pedido_id = :item_id
               AND estado = 'pendiente'"
        );
        $stmtPend->execute(["item_id" => $itemPedidoId]);
        $devolucionesPendientes = (int)$stmtPend->fetchColumn();

        if ($devolucionesPendientes > 0) {
            $conexion->rollBack();
            return [
                "ok" => false,
                "error" => "Tienes una devolución pendiente de este producto; espera a que se resuelva.",
                "devolucion_pendiente" => true,
            ];
        }

        // Unidades ya devueltas (aceptadas) -> no generan puntos.
        $stmtAcc = $conexion->prepare(
            "SELECT COALESCE(SUM(cantidad_devuelta), 0)
             FROM devoluciones
             WHERE item_pedido_id = :item_id
               AND estado = 'aceptada'"
        );
        $stmtAcc->execute(["item_id" => $itemPedidoId]);
        $cantidadDevuelta = (int)$stmtAcc->fetchColumn();

        $cantidad = (int)$item["cantidad"];
        $cantidadDisponible = max(0, $cantidad - $cantidadDevuelta);
        $puntosPotenciales = (int)($item["puntos_potenciales"] ?? 0);

        // Puntos a otorgar = parte proporcional de los puntos potenciales del producto
        // (que ya descuentan los puntos canjeados), según las unidades NO devueltas.
        // Las compras de invitado tienen puntos_potenciales = 0 -> otorgan 0.
        $puntos = ($cantidad > 0)
            ? (int)floor($puntosPotenciales * $cantidadDisponible / $cantidad)
            : 0;

        // Marcar el producto como confirmado (ya no se podrá devolver).
        $conexion->prepare(
            "UPDATE items_pedido
             SET puntos_estado = 'confirmado',
                 confirmado_en = NOW(),
                 puntos_potenciales = :pts
             WHERE id = :id"
        )->execute(["pts" => $puntos, "id" => $itemPedidoId]);

        if ($puntos > 0) {
            // Sumar puntos al saldo del usuario (la tabla tiene índice único por usuario).
            $conexion->prepare(
                "INSERT INTO puntos_usuarios (usuario_id, puntos)
                 VALUES (:uid, :pts)
                 ON DUPLICATE KEY UPDATE puntos = puntos + VALUES(puntos)"
            )->execute(["uid" => $usuarioId, "pts" => $puntos]);

            $motivo = $automatico
                ? ("Puntos por confirmación automática (30 días) del pedido #" . (int)$item["pedido_id"])
                : ("Puntos por recepción confirmada del pedido #" . (int)$item["pedido_id"]);

            $conexion->prepare(
                "INSERT INTO historial_puntos (usuario_id, pedido_id, cambio, motivo)
                 VALUES (:uid, :pid, :cambio, :motivo)"
            )->execute([
                "uid" => $usuarioId,
                "pid" => (int)$item["pedido_id"],
                "cambio" => $puntos,
                "motivo" => $motivo,
            ]);
        }

        $conexion->commit();

        return [
            "ok" => true,
            "puntos_otorgados" => $puntos,
            "item_pedido_id" => $itemPedidoId,
            "pedido_id" => (int)$item["pedido_id"],
            "automatico" => $automatico,
        ];
    } catch (Throwable $e) {
        if ($conexion->inTransaction()) {
            $conexion->rollBack();
        }
        return ["ok" => false, "error" => $e->getMessage()];
    }
}

/**
 * Confirma automáticamente los productos entregados hace más de 30 días que el
 * usuario no haya confirmado ni devuelto. Se llama al cargar los pedidos del
 * usuario. Nunca lanza excepción.
 *
 * Devuelve ["items" => int, "puntos" => int].
 */
function dmh_sweep_confirmaciones_vencidas(PDO $conexion, int $usuarioId): array
{
    $totalItems = 0;
    $totalPuntos = 0;

    try {
        $stmt = $conexion->prepare(
            "SELECT i.id
             FROM items_pedido i
             INNER JOIN pedidos p ON p.id = i.pedido_id
             WHERE p.usuario_id = :uid
               AND p.estado IN ('entregado', 'devuelto')
               AND p.entregado_en IS NOT NULL
               AND p.entregado_en <= (NOW() - INTERVAL 30 DAY)
               AND i.puntos_estado = 'pendiente'
               AND NOT EXISTS (
                   SELECT 1 FROM devoluciones d
                   WHERE d.item_pedido_id = i.id AND d.estado = 'pendiente'
               )"
        );
        $stmt->execute(["uid" => $usuarioId]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

        foreach ($ids as $id) {
            $resultado = dmh_confirmar_item_satisfaccion($conexion, (int)$id, $usuarioId, true);
            if (!empty($resultado["ok"])) {
                $totalItems++;
                $totalPuntos += (int)($resultado["puntos_otorgados"] ?? 0);
            }
        }
    } catch (Throwable $e) {
        // Silencioso: un fallo aquí no debe romper la carga de pedidos.
    }

    return ["items" => $totalItems, "puntos" => $totalPuntos];
}
