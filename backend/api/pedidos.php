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

function dmh_try_discount_stock(PDO $conexion, array $item): void
{
    $cantidad = (int)$item["cantidad"];
    $sku = trim((string)($item["sku"] ?? ""));
    $slug = trim((string)($item["slug"] ?? ""));
    $talla = trim((string)($item["talla"] ?? "Única"));
    $color = trim((string)($item["color"] ?? ""));

    if ($cantidad <= 0 || $slug === "") {
        return;
    }

    // Si no hay variantes inventariadas para ese slug, no bloqueamos el pedido.
    $stmtHasVariants = $conexion->prepare(
        "SELECT COUNT(*)
         FROM variantes_producto vp
         JOIN productos p ON p.id = vp.producto_id
         WHERE p.slug = :slug"
    );
    $stmtHasVariants->execute(["slug" => $slug]);
    $hasVariants = (int)$stmtHasVariants->fetchColumn() > 0;
    if (!$hasVariants) {
        return;
    }

    if ($sku !== "") {
        $stmtSku = $conexion->prepare(
            "UPDATE variantes_producto vp
             JOIN productos p ON p.id = vp.producto_id
             SET vp.stock = vp.stock - :cantidad
             WHERE vp.sku = :sku
               AND vp.stock >= :cantidad"
        );
        $stmtSku->execute([
            "cantidad" => $cantidad,
            "sku" => $sku,
        ]);

        if ($stmtSku->rowCount() > 0) {
            return;
        }
    }

    $stmtVariantColor = $conexion->prepare(
        "UPDATE variantes_producto vp
         JOIN productos p ON p.id = vp.producto_id
         SET vp.stock = vp.stock - :cantidad
         WHERE p.slug = :slug
           AND vp.talla = :talla
           AND (
             (:color = '' AND (vp.color IS NULL OR vp.color = ''))
             OR vp.color = :color
           )
           AND vp.stock >= :cantidad"
    );

    $stmtVariantColor->execute([
        "cantidad" => $cantidad,
        "slug" => $slug,
        "talla" => $talla,
        "color" => $color,
    ]);

    if ($stmtVariantColor->rowCount() > 0) {
        return;
    }

    $stmtVariantSize = $conexion->prepare(
        "UPDATE variantes_producto vp
         JOIN productos p ON p.id = vp.producto_id
         SET vp.stock = vp.stock - :cantidad
         WHERE p.slug = :slug
           AND vp.talla = :talla
           AND vp.stock >= :cantidad
         ORDER BY vp.stock DESC
         LIMIT 1"
    );

    $stmtVariantSize->execute([
        "cantidad" => $cantidad,
        "slug" => $slug,
        "talla" => $talla,
    ]);

    if ($stmtVariantSize->rowCount() > 0) {
        return;
    }

    // Si se pidió una talla concreta, no debemos descontar otra talla distinta.
    // Esto garantiza que el stock que baja coincide con la talla comprada.
    $normalizedTalla = mb_strtolower(trim($talla));
    if ($normalizedTalla !== "" && $normalizedTalla !== "unica" && $normalizedTalla !== "única") {
        throw new RuntimeException("Sin stock suficiente para la talla seleccionada en " . ($item["nombre_producto"] ?? $slug));
    }

    $stmtAnyVariant = $conexion->prepare(
        "UPDATE variantes_producto vp
         JOIN productos p ON p.id = vp.producto_id
         SET vp.stock = vp.stock - :cantidad
         WHERE p.slug = :slug
           AND vp.stock >= :cantidad
         ORDER BY vp.stock DESC
         LIMIT 1"
    );

    $stmtAnyVariant->execute([
        "cantidad" => $cantidad,
        "slug" => $slug,
    ]);

    if ($stmtAnyVariant->rowCount() === 0) {
        throw new RuntimeException("Sin stock suficiente para " . ($item["nombre_producto"] ?? $slug));
    }
}

// Listar pedidos del usuario autenticado.
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
                SELECT id AS item_pedido_id, slug, nombre_producto, talla, color, sku, cantidad, precio_unitario, subtotal
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


// Crear pedido (usuario autenticado o invitado). El cobro se simula aparte.

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        $raw  = file_get_contents("php://input");
        $body = json_decode($raw, true) ?? [];

        // Validar items 
        $items = $body["items"] ?? [];
        if (!is_array($items) || count($items) === 0) {
            http_response_code(400);
            echo json_encode(["ok" => false, "error" => "El carrito está vacío"]);
            exit;
        }

        // Dirección de envío 
        $direccionEnvio = trim((string)($body["direccion_envio"] ?? ""));
        if ($direccionEnvio === "") {
            http_response_code(400);
            echo json_encode(["ok" => false, "error" => "La dirección de envío es obligatoria"]);
            exit;
        }

        // Datos de invitado (obligatorios si no hay sesión)
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

            // Solo se permite compra como invitado con emails no registrados.
            $stmtEmailRegistrado = $conexion->prepare(
                "SELECT id
                 FROM usuarios
                 WHERE email = :email
                 LIMIT 1"
            );
            $stmtEmailRegistrado->execute(["email" => $emailInvitado]);
            $emailYaRegistrado = $stmtEmailRegistrado->fetchColumn();

            if ($emailYaRegistrado) {
                http_response_code(409);
                echo json_encode([
                    "ok" => false,
                    "error" => "Este email ya está registrado. Inicia sesión para continuar la compra",
                    "code" => "EMAIL_ALREADY_REGISTERED",
                ]);
                exit;
            }
        }

        // Calcular total y validar items 
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

        // Insertar pedido 
        $conexion->beginTransaction();

        // Restamos stock en el momento de crear el pedido para evitar sobreventa.
        foreach ($itemsLimpios as $item) {
            dmh_try_discount_stock($conexion, $item);
        }

        // Gestion de puntos para clientes
        $puntosUsados = 0;
        $descuentoPuntos = 0.0;

        if ($usuarioId) {
            // Canjear puntos si el usuario lo solicita
            $canjearPuntos = (bool)($body["canjear_puntos"] ?? false);

            if ($canjearPuntos) {
                $stmtPuntos = $conexion->prepare("SELECT puntos FROM puntos_usuarios WHERE usuario_id = :uid LIMIT 1");
                $stmtPuntos->execute(["uid" => $usuarioId]);
                $puntosDisponibles = (int)($stmtPuntos->fetchColumn() ?: 0);

                if ($puntosDisponibles >= 500) {
                    // Máximo 20% del total
                    $maxDescuento = round($importeTotal * 0.20, 2);
                    // 100 puntos = 1€
                    $descuentoSolicitado = round($puntosDisponibles / 100, 2);
                    $descuentoPuntos = min($descuentoSolicitado, $maxDescuento);
                    $puntosUsados = (int)($descuentoPuntos * 100);
                    $importeTotal = round($importeTotal - $descuentoPuntos, 2);
                }
            }
        }

        // Puntos ganados: 1€ = 10 puntos (solo usuarios registrados)
        $puntosGanados = $usuarioId ? (int)floor($importeTotal * 10) : 0;

        // Insertar pedido
        $stmtPedido = $conexion->prepare("
    INSERT INTO pedidos (usuario_id, nombre_invitado, email_invitado, importe_total, puntos_usados, puntos_ganados, direccion_envio)
    VALUES (:uid, :nombre_invitado, :email_invitado, :total, :puntos_usados, :puntos_ganados, :direccion)
");
        $stmtPedido->execute([
            "uid"             => $usuarioId,
            "nombre_invitado" => $nombreInvitado,
            "email_invitado"  => $emailInvitado,
            "total"           => $importeTotal,
            "puntos_usados"   => $puntosUsados,
            "puntos_ganados"  => $puntosGanados,
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

        // Actualizar puntos del usuario
        if ($usuarioId) {
            // Descontar puntos usados
            if ($puntosUsados > 0) {
                $conexion->prepare("UPDATE puntos_usuarios SET puntos = puntos - :usados WHERE usuario_id = :uid")
                    ->execute(["usados" => $puntosUsados, "uid" => $usuarioId]);

                $conexion->prepare("INSERT INTO historial_puntos (usuario_id, pedido_id, cambio, motivo) VALUES (:uid, :pid, :cambio, :motivo)")
                    ->execute([
                        "uid"    => $usuarioId,
                        "pid"    => $pedidoId,
                        "cambio" => -$puntosUsados,
                        "motivo" => "Canje de puntos en pedido #$pedidoId",
                    ]);
            }

            // Acumular puntos ganados
            if ($puntosGanados > 0) {
                $stmtUpsert = $conexion->prepare("
            INSERT INTO puntos_usuarios (usuario_id, puntos)
            VALUES (:uid, :puntos)
            ON DUPLICATE KEY UPDATE puntos = puntos + :puntos
        ");
                $stmtUpsert->execute(["uid" => $usuarioId, "puntos" => $puntosGanados]);

                $conexion->prepare("INSERT INTO historial_puntos (usuario_id, pedido_id, cambio, motivo) VALUES (:uid, :pid, :cambio, :motivo)")
                    ->execute([
                        "uid"    => $usuarioId,
                        "pid"    => $pedidoId,
                        "cambio" => $puntosGanados,
                        "motivo" => "Puntos ganados por pedido #$pedidoId",
                    ]);
            }
        }

        $conexion->commit();

        echo json_encode([
            "ok"               => true,
            "mensaje"          => "Pedido realizado correctamente",
            "pedido_id"        => $pedidoId,
            "total"            => $importeTotal,
            "envio"            => $envio,
            "puntos_usados"    => $puntosUsados,
            "puntos_ganados"   => $puntosGanados,
            "descuento_puntos" => $descuentoPuntos,
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
