<?php
header("Access-Control-Allow-Origin: http://localhost:4321");
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

    $stmtPedido = $conexion->prepare("SELECT usuario_id FROM pedidos WHERE id = :id LIMIT 1");
    $stmtPedido->execute(["id" => $pedidoId]);
    $pedido = $stmtPedido->fetch(PDO::FETCH_ASSOC);

    if (!$pedido) {
        http_response_code(404);
        echo json_encode(["ok" => false, "error" => "Pedido no encontrado"]);
        exit;
    }

    $pedidoUsuarioId = isset($pedido["usuario_id"]) ? (int)$pedido["usuario_id"] : 0;
    if ($pedidoUsuarioId > 0 && $usuarioSesion !== $pedidoUsuarioId) {
        http_response_code(403);
        echo json_encode(["ok" => false, "error" => "No autorizado para confirmar este pedido"]);
        exit;
    }

    $stmt = $conexion->prepare("UPDATE pedidos SET estado = 'pagado' WHERE id = :id AND estado = 'pendiente'");
    $stmt->execute(["id" => $pedidoId]);

    if ($saveCard && $pedidoUsuarioId > 0) {
        $pmId = (string)($paymentIntent->payment_method ?? "");
        if ($pmId !== "") {
            $paymentMethod = \Stripe\PaymentMethod::retrieve($pmId);
            if (($paymentMethod->type ?? "") === "card") {
                dmh_upsert_saved_card($conexion, $pedidoUsuarioId, $paymentMethod);
            }
        }
    }

    echo json_encode(["ok" => true, "pedido_id" => $pedidoId]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["ok" => false, "error" => "Error al confirmar pago", "detalle" => $e->getMessage()]);
}
