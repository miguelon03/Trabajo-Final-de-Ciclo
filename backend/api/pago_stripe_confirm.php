<?php
header("Access-Control-Allow-Origin: http://localhost:4321");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit(); }
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["ok" => false, "error" => "Método no permitido"]);
    exit;
}

require_once __DIR__ . "/../vendor/autoload.php";
require_once __DIR__ . "/../conexion.php";
session_start();

\Stripe\Stripe::setApiKey("sk_test_51TY3cxJdVatqe0Q4zDHlRcAMny13IwrAIM9gnluOiH7TaBtSah2bKYQmBQbSFodWiiNnGRcMmZ43Em4nSHz3dNNa00yrhA1tBP");

try {
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    $pedidoId = (int)($body["pedido_id"] ?? 0);
    $paymentIntentId = trim((string)($body["payment_intent_id"] ?? ""));

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

    $stmt = $conexion->prepare("UPDATE pedidos SET estado = 'pagado' WHERE id = :id AND estado = 'pendiente'");
    $stmt->execute(["id" => $pedidoId]);

    echo json_encode(["ok" => true, "pedido_id" => $pedidoId]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["ok" => false, "error" => "Error al confirmar pago", "detalle" => $e->getMessage()]);
}