<?php
$dmhAllowedOrigins = ["http://localhost:4321", "https://dripmode.com"];
$dmhOrigin = $_SERVER["HTTP_ORIGIN"] ?? "";
if (in_array($dmhOrigin, $dmhAllowedOrigins, true)) {
    header("Access-Control-Allow-Origin: " . $dmhOrigin);
}
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit();
}
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["ok" => false, "error" => "Método no permitido"]);
    exit;
}

require_once __DIR__ . "/../vendor/autoload.php";
require_once __DIR__ . "/../conexion.php";
require_once __DIR__ . "/../email_pedidos.php";
session_start();

$envFile = __DIR__ . '/../.env';
$stripeKey = '';
if (file_exists($envFile)) {
    foreach (file($envFile) as $line) {
        if (str_starts_with(trim($line), 'STRIPE_SECRET_KEY=')) {
            $stripeKey = trim(explode('=', $line, 2)[1]);
            break;
        }
    }
}
\Stripe\Stripe::setApiKey($stripeKey);

function dmh_try_discount_stock(PDO $conexion, array $item): void
{
    $cantidad = (int)($item["cantidad"] ?? 0);
    $sku = trim((string)($item["sku"] ?? ""));
    $slug = trim((string)($item["slug"] ?? ""));
    $talla = trim((string)($item["talla"] ?? "Única"));
    $color = trim((string)($item["color"] ?? ""));

    if ($cantidad <= 0 || $slug === "") {
        return;
    }

    $stmtHasVariants = $conexion->prepare(
        "SELECT COUNT(*)
         FROM variantes_producto vp
         JOIN productos p ON p.id = vp.producto_id
         WHERE p.slug = :slug"
    );
    $stmtHasVariants->execute(["slug" => $slug]);
    if ((int)$stmtHasVariants->fetchColumn() <= 0) {
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

function dmh_apply_order_side_effects(PDO $conexion, array $pedido, array $items): void
{
    foreach ($items as $item) {
        dmh_try_discount_stock($conexion, $item);
    }

    $pedidoUsuarioId = isset($pedido["usuario_id"]) ? (int)$pedido["usuario_id"] : 0;
    if ($pedidoUsuarioId <= 0) {
        return;
    }

    $puntosUsados = isset($pedido["puntos_usados"]) ? (int)$pedido["puntos_usados"] : 0;
    $puntosGanados = isset($pedido["puntos_ganados"]) ? (int)$pedido["puntos_ganados"] : 0;

    $stmtPuntos = $conexion->prepare("SELECT COALESCE(SUM(puntos), 0) FROM puntos_usuarios WHERE usuario_id = :uid");
    $stmtPuntos->execute(["uid" => $pedidoUsuarioId]);
    $puntosDisponibles = (int)($stmtPuntos->fetchColumn() ?: 0);
    $saldoPuntosFinal = max(0, $puntosDisponibles - $puntosUsados + $puntosGanados);

    $conexion->prepare("DELETE FROM puntos_usuarios WHERE usuario_id = :uid")
        ->execute(["uid" => $pedidoUsuarioId]);

    $conexion->prepare("INSERT INTO puntos_usuarios (usuario_id, puntos) VALUES (:uid, :puntos)")
        ->execute([
            "uid" => $pedidoUsuarioId,
            "puntos" => $saldoPuntosFinal,
        ]);

    if ($puntosUsados > 0) {
        $conexion->prepare("INSERT INTO historial_puntos (usuario_id, pedido_id, cambio, motivo) VALUES (:uid, :pid, :cambio, :motivo)")
            ->execute([
                "uid" => $pedidoUsuarioId,
                "pid" => (int)$pedido["id"],
                "cambio" => -$puntosUsados,
                "motivo" => "Canje de puntos en pedido #" . (int)$pedido["id"],
            ]);
    }

    if ($puntosGanados > 0) {
        $conexion->prepare("INSERT INTO historial_puntos (usuario_id, pedido_id, cambio, motivo) VALUES (:uid, :pid, :cambio, :motivo)")
            ->execute([
                "uid" => $pedidoUsuarioId,
                "pid" => (int)$pedido["id"],
                "cambio" => $puntosGanados,
                "motivo" => "Puntos ganados por pedido #" . (int)$pedido["id"],
            ]);
    }

    $conexion->prepare("DELETE FROM carrito_items WHERE usuario_id = :uid")
        ->execute(["uid" => $pedidoUsuarioId]);
}

function dmh_upsert_saved_card(PDO $conexion, int $usuarioId, \Stripe\PaymentMethod $paymentMethod): void
{
    $card = $paymentMethod->card ?? null;
    $brand = $card->brand ?? null;
    $last4 = $card->last4 ?? null;
    $expMonth = isset($card->exp_month) ? (int)$card->exp_month : null;
    $expYear = isset($card->exp_year) ? (int)$card->exp_year : null;

    $stmt = $conexion->prepare(
        "INSERT INTO tarjetas_guardadas (
            usuario_id,
            stripe_payment_method_id,
            marca,
            ultimos4,
            exp_mes,
            exp_ano
         ) VALUES (
            :uid,
            :pm,
            :brand,
            :last4,
            :exp_month,
            :exp_year
         )
         ON DUPLICATE KEY UPDATE
            marca = VALUES(marca),
            ultimos4 = VALUES(ultimos4),
            exp_mes = VALUES(exp_mes),
            exp_ano = VALUES(exp_ano),
            actualizado_en = CURRENT_TIMESTAMP"
    );

    $stmt->execute([
        "uid" => $usuarioId,
        "pm" => (string)$paymentMethod->id,
        "brand" => $brand,
        "last4" => $last4,
        "exp_month" => $expMonth,
        "exp_year" => $expYear,
    ]);
}

try {
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    $pedidoId = (int)($body["pedido_id"] ?? 0);
    $paymentIntentId = trim((string)($body["payment_intent_id"] ?? ""));
    $saveCard = (bool)($body["save_card"] ?? false);
    $usuarioSesion = isset($_SESSION["usuario_id"]) ? (int)$_SESSION["usuario_id"] : 0;

    if ($pedidoId <= 0 || $paymentIntentId === "") {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "Datos inválidos"]);
        exit;
    }

    // Verificar con Stripe que el pago está realmente completado
    $paymentIntent = \Stripe\PaymentIntent::retrieve($paymentIntentId);

    if ($paymentIntent->status !== "succeeded") {
        http_response_code(402);
        echo json_encode(["ok" => false, "error" => "El pago no se completó"]);
        exit;
    }

    // Verificar que el pedido_id coincide con el metadata del PaymentIntent
    if ((int)($paymentIntent->metadata["pedido_id"] ?? 0) !== $pedidoId) {
        http_response_code(403);
        echo json_encode(["ok" => false, "error" => "El pago no corresponde a este pedido"]);
        exit;
    }

    $conexion->beginTransaction();

    $stmtPedido = $conexion->prepare("SELECT id, usuario_id, estado, puntos_usados, puntos_ganados FROM pedidos WHERE id = :id LIMIT 1 FOR UPDATE");
    $stmtPedido->execute(["id" => $pedidoId]);
    $pedido = $stmtPedido->fetch(PDO::FETCH_ASSOC);

    if (!$pedido) {
        if ($conexion->inTransaction()) {
            $conexion->rollBack();
        }
        http_response_code(404);
        echo json_encode(["ok" => false, "error" => "Pedido no encontrado"]);
        exit;
    }

    $pedidoUsuarioId = isset($pedido["usuario_id"]) ? (int)$pedido["usuario_id"] : 0;
    if ($pedidoUsuarioId > 0 && $usuarioSesion !== $pedidoUsuarioId) {
        if ($conexion->inTransaction()) {
            $conexion->rollBack();
        }
        http_response_code(403);
        echo json_encode(["ok" => false, "error" => "No autorizado para confirmar este pedido"]);
        exit;
    }

    if (($pedido["estado"] ?? "") !== "pendiente") {
        if ($conexion->inTransaction()) {
            $conexion->commit();
        }
        echo json_encode(["ok" => true, "pedido_id" => $pedidoId]);
        exit;
    }

    $stmtItems = $conexion->prepare("SELECT slug, nombre_producto, talla, color, sku, cantidad FROM items_pedido WHERE pedido_id = :pid ORDER BY id ASC");
    $stmtItems->execute(["pid" => $pedidoId]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC) ?: [];

    dmh_apply_order_side_effects($conexion, $pedido, $items);

    $stmt = $conexion->prepare("UPDATE pedidos SET estado = 'pagado' WHERE id = :id AND estado = 'pendiente'");
    $stmt->execute(["id" => $pedidoId]);

    $conexion->commit();

    if ($saveCard && $pedidoUsuarioId > 0) {
        $pmId = (string)($paymentIntent->payment_method ?? "");
        if ($pmId !== "") {
            $paymentMethod = \Stripe\PaymentMethod::retrieve($pmId);
            if (($paymentMethod->type ?? "") === "card") {
                dmh_upsert_saved_card($conexion, $pedidoUsuarioId, $paymentMethod);
            }
        }
    }

    // Si la compra es de un invitado (sin cuenta), le enviamos por correo la
    // confirmación con el nº de pedido, los artículos y las instrucciones de
    // devolución (30 días + registrarse con el mismo email). Un fallo de correo
    // no debe romper la confirmación del pago.
    $emailEnviado = false;
    if ($pedidoUsuarioId <= 0) {
        $resultadoEmail = dmh_enviar_email_pedido_invitado($conexion, $pedidoId);
        $emailEnviado = (bool)($resultadoEmail["ok"] ?? false);
        if (!$emailEnviado && empty($resultadoEmail["skipped"])) {
            error_log("[DMH] No se pudo enviar el email del pedido {$pedidoId}: " . (string)($resultadoEmail["error"] ?? ""));
        }
    }

    echo json_encode(["ok" => true, "pedido_id" => $pedidoId, "email_enviado" => $emailEnviado]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["ok" => false, "error" => "Error al confirmar pago", "detalle" => $e->getMessage()]);
}
