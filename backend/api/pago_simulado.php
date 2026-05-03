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

require_once __DIR__ . "/../conexion.php";
session_start();

function dmh_json_body(): array {
    $raw = file_get_contents("php://input");
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

try {
    $body = dmh_json_body();
    $pedidoId = (int)($body["pedido_id"] ?? 0);
    $titular = trim((string)($body["titular"] ?? ""));
    $tarjetaUltimos4 = preg_replace('/\D+/', '', (string)($body["tarjeta_ultimos4"] ?? "")) ?? "";
    $tarjetaMascara = trim((string)($body["tarjeta_mascara"] ?? ""));
    $caducidad = trim((string)($body["caducidad"] ?? ""));
    $usuarioSesion = isset($_SESSION["usuario_id"]) ? (int)$_SESSION["usuario_id"] : null;

    if ($pedidoId <= 0) {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "pedido_id inválido"]);
        exit;
    }

    if ($titular === "" || mb_strlen($titular) < 5) {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "Titular de tarjeta inválido"]);
        exit;
    }

    if (!preg_match('/^\d{4}$/', $tarjetaUltimos4)) {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "Últimos 4 dígitos de tarjeta inválidos"]);
        exit;
    }

    if (!preg_match('/^(0[1-9]|1[0-2])\/\d{2}$/', $caducidad)) {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "Caducidad inválida"]);
        exit;
    }

    $stmtPedido = $conexion->prepare(
        "SELECT id, usuario_id, estado
         FROM pedidos
         WHERE id = :id
         LIMIT 1"
    );
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
        echo json_encode(["ok" => false, "error" => "No autorizado para pagar este pedido"]);
        exit;
    }

    if (($pedido["estado"] ?? "") === "pagado") {
        echo json_encode([
            "ok" => true,
            "already_paid" => true,
            "pedido_id" => $pedidoId,
            "tarjeta_mascara" => $tarjetaMascara !== "" ? $tarjetaMascara : ("**** **** **** " . $tarjetaUltimos4),
        ]);
        exit;
    }

    $conexion->beginTransaction();

    $stmtLock = $conexion->prepare(
        "SELECT id, estado
         FROM pedidos
         WHERE id = :id
         LIMIT 1
         FOR UPDATE"
    );
    $stmtLock->execute(["id" => $pedidoId]);
    $pedidoLock = $stmtLock->fetch(PDO::FETCH_ASSOC);

    if (!$pedidoLock) {
        throw new RuntimeException("Pedido no encontrado");
    }

    if (($pedidoLock["estado"] ?? "") === "pagado") {
        $conexion->commit();
        echo json_encode([
            "ok" => true,
            "already_paid" => true,
            "pedido_id" => $pedidoId,
            "tarjeta_mascara" => $tarjetaMascara !== "" ? $tarjetaMascara : ("**** **** **** " . $tarjetaUltimos4),
        ]);
        exit;
    }

    $stmtUpdate = $conexion->prepare(
        "UPDATE pedidos
         SET estado = 'pagado'
         WHERE id = :id"
    );
    $stmtUpdate->execute(["id" => $pedidoId]);

    $conexion->commit();

    echo json_encode([
        "ok" => true,
        "pedido_id" => $pedidoId,
        "tarjeta_mascara" => $tarjetaMascara !== "" ? $tarjetaMascara : ("**** **** **** " . $tarjetaUltimos4),
        "mensaje" => "Pago simulado completado",
    ]);
} catch (Throwable $e) {
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }

    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "error" => "No se pudo procesar el pago simulado",
        "detalle" => $e->getMessage(),
    ]);
}
