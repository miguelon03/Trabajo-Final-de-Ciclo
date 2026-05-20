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

function dmh_get_or_create_stripe_customer(PDO $conexion, int $usuarioId): string
{
    $stmt = $conexion->prepare(
        "SELECT stripe_customer_id
         FROM usuarios_stripe
         WHERE usuario_id = :uid
         LIMIT 1"
    );
    $stmt->execute(["uid" => $usuarioId]);
    $existing = $stmt->fetchColumn();
    if (is_string($existing) && trim($existing) !== "") {
        return $existing;
    }

    $stmtUser = $conexion->prepare(
        "SELECT nombre, email
         FROM usuarios
         WHERE id = :uid
         LIMIT 1"
    );
    $stmtUser->execute(["uid" => $usuarioId]);
    $user = $stmtUser->fetch(PDO::FETCH_ASSOC) ?: [];

    $customer = \Stripe\Customer::create([
        "name" => (string)($user["nombre"] ?? "Cliente DMH"),
        "email" => (string)($user["email"] ?? ""),
        "metadata" => ["usuario_id" => (string)$usuarioId],
    ]);

    $stmtInsert = $conexion->prepare(
        "INSERT INTO usuarios_stripe (usuario_id, stripe_customer_id)
         VALUES (:uid, :customer)
         ON DUPLICATE KEY UPDATE stripe_customer_id = VALUES(stripe_customer_id)"
    );
    $stmtInsert->execute([
        "uid" => $usuarioId,
        "customer" => (string)$customer->id,
    ]);

    return (string)$customer->id;
}

try {
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    $pedidoId = (int)($body["pedido_id"] ?? 0);
    $usuarioSesion = isset($_SESSION["usuario_id"]) ? (int)$_SESSION["usuario_id"] : null;
    $saveCard = (bool)($body["save_card"] ?? false);
    $selectedPaymentMethodId = trim((string)($body["selected_payment_method_id"] ?? ""));

    if ($pedidoId <= 0) {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "pedido_id inválido"]);
        exit;
    }

    $stmtPedido = $conexion->prepare("SELECT id, usuario_id, estado, importe_total FROM pedidos WHERE id = :id LIMIT 1");
    $stmtPedido->execute(["id" => $pedidoId]);
    $pedido = $stmtPedido->fetch(PDO::FETCH_ASSOC);

    if (!$pedido) {
        http_response_code(404);
        echo json_encode(["ok" => false, "error" => "Pedido no encontrado"]);
        exit;
    }

    $pedidoUsuarioId = $pedido["usuario_id"] !== null ? (int)$pedido["usuario_id"] : null;
    if ($pedidoUsuarioId !== null && $usuarioSesion !== $pedidoUsuarioId) {
        http_response_code(403);
        echo json_encode(["ok" => false, "error" => "No autorizado"]);
        exit;
    }

    if ($pedido["estado"] === "pagado") {
        echo json_encode(["ok" => true, "already_paid" => true, "pedido_id" => $pedidoId]);
        exit;
    }

    $importe = (int)round((float)$pedido["importe_total"] * 100);

    $intentPayload = [
        "amount" => $importe,
        "currency" => "eur",
        "metadata" => ["pedido_id" => $pedidoId],
    ];

    if ($usuarioSesion !== null) {
        $stripeCustomerId = dmh_get_or_create_stripe_customer($conexion, $usuarioSesion);
        $intentPayload["customer"] = $stripeCustomerId;

        if ($saveCard && $selectedPaymentMethodId === "") {
            $intentPayload["setup_future_usage"] = "off_session";
        }

        if ($selectedPaymentMethodId !== "") {
            $stmtSaved = $conexion->prepare(
                "SELECT id
                 FROM tarjetas_guardadas
                 WHERE usuario_id = :uid AND stripe_payment_method_id = :pm
                 LIMIT 1"
            );
            $stmtSaved->execute([
                "uid" => $usuarioSesion,
                "pm" => $selectedPaymentMethodId,
            ]);
            $saved = $stmtSaved->fetch(PDO::FETCH_ASSOC);

            if (!$saved) {
                http_response_code(403);
                echo json_encode(["ok" => false, "error" => "La tarjeta seleccionada no pertenece al usuario"]);
                exit;
            }
        }
    }

    $paymentIntent = \Stripe\PaymentIntent::create($intentPayload);

    echo json_encode([
        "ok" => true,
        "client_secret" => $paymentIntent->client_secret,
        "pedido_id" => $pedidoId,
        "importe" => $pedido["importe_total"],
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "error" => "No se pudo crear el PaymentIntent",
        "detalle" => $e->getMessage(),
    ]);
}
