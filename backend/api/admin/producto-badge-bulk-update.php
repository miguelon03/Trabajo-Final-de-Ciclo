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

function dmh_normalize_badge(string $badge): ?string {
    $badge = trim($badge);

    if ($badge === "" || $badge === "sin_badge") {
        return null;
    }

    $lower = strtolower($badge);

    if ($lower === "new") {
        return "NEW";
    }

    if ($lower === "oferta") {
        return "Oferta";
    }

    return "__INVALID__";
}

try {
    $input = json_decode(file_get_contents("php://input"), true);

    if (!is_array($input)) {
        echo json_encode([
            "ok" => false,
            "error" => "Datos no válidos"
        ]);
        exit;
    }

    $rawIds = $input["ids"] ?? [];
    $badge = dmh_normalize_badge((string)($input["badge"] ?? ""));

    if (!is_array($rawIds)) {
        echo json_encode([
            "ok" => false,
            "error" => "La lista de productos no es válida"
        ]);
        exit;
    }

    if ($badge === "__INVALID__") {
        echo json_encode([
            "ok" => false,
            "error" => "La badge solo puede ser NEW, oferta o sin badge"
        ]);
        exit;
    }

    $ids = [];
    foreach ($rawIds as $rawId) {
        $id = (int)$rawId;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }

    $ids = array_values($ids);

    if (count($ids) === 0) {
        echo json_encode([
            "ok" => false,
            "error" => "Selecciona al menos un producto"
        ]);
        exit;
    }

    if (count($ids) > 200) {
        echo json_encode([
            "ok" => false,
            "error" => "No puedes modificar más de 200 productos a la vez"
        ]);
        exit;
    }

    $placeholders = [];
    $params = [
        "badge" => $badge,
    ];

    foreach ($ids as $index => $id) {
        $key = "id" . $index;
        $placeholders[] = ":" . $key;
        $params[$key] = $id;
    }

    $sql = "UPDATE productos SET badge = :badge WHERE id IN (" . implode(", ", $placeholders) . ")";
    $stmt = $conexion->prepare($sql);
    $stmt->execute($params);

    echo json_encode([
        "ok" => true,
        "mensaje" => "Badge actualizado correctamente",
        "actualizados" => $stmt->rowCount()
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "error" => "Error al actualizar badges",
        "detalle" => $e->getMessage()
    ]);
}