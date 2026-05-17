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
    $input = json_decode(file_get_contents("php://input"), true);
    $id = (int)($input["id"] ?? 0);

    if ($id <= 0) {
        echo json_encode([
            "ok" => false,
            "error" => "ID no válido"
        ]);
        exit;
    }

    $conexion->beginTransaction();

    $stmt = $conexion->prepare("DELETE FROM productos_categorias WHERE producto_id = :id");
    $stmt->execute(["id" => $id]);

    $stmt = $conexion->prepare("DELETE FROM imagenes_productos WHERE producto_id = :id");
    $stmt->execute(["id" => $id]);

    $stmt = $conexion->prepare("DELETE FROM variantes_producto WHERE producto_id = :id");
    $stmt->execute(["id" => $id]);

    $stmt = $conexion->prepare("DELETE FROM opiniones WHERE producto_id = :id");
    $stmt->execute(["id" => $id]);

    $stmt = $conexion->prepare("DELETE FROM vistas_producto WHERE producto_id = :id");
    $stmt->execute(["id" => $id]);

    $stmt = $conexion->prepare("DELETE FROM productos WHERE id = :id");
    $stmt->execute(["id" => $id]);

    $conexion->commit();

    echo json_encode([
        "ok" => true,
        "mensaje" => "Producto eliminado correctamente"
    ]);
} catch (Throwable $e) {
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }

    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "error" => "Error al eliminar producto",
        "detalle" => $e->getMessage()
    ]);
}