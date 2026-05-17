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

session_start();

if (!isset($_SESSION["usuario_id"]) || ($_SESSION["usuario_rol"] ?? "") !== "admin") {
    http_response_code(403);
    echo json_encode([
        "ok" => false,
        "error" => "Acceso no autorizado"
    ]);
    exit;
}

require_once __DIR__ . "/../../conexion.php";

try {
    $input = json_decode(file_get_contents("php://input"), true) ?? [];
    $opinionId = (int)($input["opinion_id"] ?? 0);

    if ($opinionId <= 0) {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "ID de opinión inválido"]);
        exit;
    }

    $stmt = $conexion->prepare("DELETE FROM opiniones WHERE id = :id LIMIT 1");
    $stmt->execute(["id" => $opinionId]);

    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(["ok" => false, "error" => "Opinión no encontrada"]);
        exit;
    }

    echo json_encode([
        "ok" => true,
        "mensaje" => "Opinión eliminada correctamente",
        "opinion_id" => $opinionId,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "error" => "No se pudo eliminar la opinión",
        "detalle" => $e->getMessage()
    ]);
}