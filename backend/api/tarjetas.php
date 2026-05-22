<?php

$dmhAllowedOrigins = ["http://localhost:4321", "https://dripmode.com"];
$dmhOrigin = $_SERVER["HTTP_ORIGIN"] ?? "";
if (in_array($dmhOrigin, $dmhAllowedOrigins, true)) {
    header("Access-Control-Allow-Origin: " . $dmhOrigin);
}
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit();
}

require_once __DIR__ . "/../vendor/autoload.php";
require_once __DIR__ . "/../conexion.php";
session_start();

$usuarioId = isset($_SESSION["usuario_id"]) ? (int)$_SESSION["usuario_id"] : 0;
if ($usuarioId <= 0) {
    http_response_code(401);
    echo json_encode(["ok" => false, "error" => "Debes iniciar sesión"]);
    exit;
}

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

if ($stripeKey !== '') {
    \Stripe\Stripe::setApiKey($stripeKey);
}

try {
    if ($_SERVER["REQUEST_METHOD"] === "GET") {
        $stmt = $conexion->prepare(
            "SELECT id, stripe_payment_method_id, marca, ultimos4, exp_mes, exp_ano
             FROM tarjetas_guardadas
             WHERE usuario_id = :uid
             ORDER BY actualizado_en DESC, id DESC"
        );
        $stmt->execute(["uid" => $usuarioId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $tarjetas = array_map(function ($r) {
            return [
                "id" => (int)$r["id"],
                "payment_method_id" => (string)$r["stripe_payment_method_id"],
                "brand" => (string)($r["marca"] ?? "card"),
                "last4" => (string)($r["ultimos4"] ?? ""),
                "exp_month" => isset($r["exp_mes"]) ? (int)$r["exp_mes"] : null,
                "exp_year" => isset($r["exp_ano"]) ? (int)$r["exp_ano"] : null,
            ];
        }, $rows);

        echo json_encode(["ok" => true, "tarjetas" => $tarjetas]);
        exit;
    }

    if ($_SERVER["REQUEST_METHOD"] === "DELETE") {
        $body = json_decode(file_get_contents("php://input"), true) ?? [];
        $tarjetaId = (int)($body["tarjeta_id"] ?? 0);

        if ($tarjetaId <= 0) {
            http_response_code(400);
            echo json_encode(["ok" => false, "error" => "tarjeta_id inválido"]);
            exit;
        }

        $stmtTarjeta = $conexion->prepare(
            "SELECT id, stripe_payment_method_id
             FROM tarjetas_guardadas
             WHERE id = :id AND usuario_id = :uid
             LIMIT 1"
        );
        $stmtTarjeta->execute([
            "id" => $tarjetaId,
            "uid" => $usuarioId,
        ]);
        $tarjeta = $stmtTarjeta->fetch(PDO::FETCH_ASSOC);

        if (!$tarjeta) {
            http_response_code(404);
            echo json_encode(["ok" => false, "error" => "Tarjeta no encontrada"]);
            exit;
        }

        $pmId = (string)$tarjeta["stripe_payment_method_id"];

        if ($stripeKey !== '') {
            try {
                $pm = \Stripe\PaymentMethod::retrieve($pmId);
                $pm->detach();
            } catch (Throwable $e) {
                // Si Stripe ya no tiene la tarjeta, igualmente limpiamos nuestra BD.
            }
        }

        $stmtDelete = $conexion->prepare(
            "DELETE FROM tarjetas_guardadas
             WHERE id = :id AND usuario_id = :uid"
        );
        $stmtDelete->execute([
            "id" => $tarjetaId,
            "uid" => $usuarioId,
        ]);

        echo json_encode(["ok" => true]);
        exit;
    }

    http_response_code(405);
    echo json_encode(["ok" => false, "error" => "Método no permitido"]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "error" => "No se pudo gestionar tarjetas guardadas",
        "detalle" => $e->getMessage(),
    ]);
}
