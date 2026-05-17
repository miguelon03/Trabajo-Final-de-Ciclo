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

function dmh_slugify(string $value): string {
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/i', '-', $value) ?? '';
    $value = trim($value, '-');
    return $value !== '' ? $value : 'producto';
}

try {
    $input = json_decode(file_get_contents("php://input"), true);

    $id = (int)($input["id"] ?? 0);
    $nombre = trim((string)($input["nombre"] ?? ""));
    $slug = trim((string)($input["slug"] ?? ""));
    $descripcion = trim((string)($input["descripcion"] ?? ""));
    $precioBase = (float)($input["precio_base"] ?? 0);
    $precioOriginal = isset($input["precio_original"]) && $input["precio_original"] !== ""
        ? (float)$input["precio_original"]
        : null;
    $tipo = trim((string)($input["tipo"] ?? ""));
    $color = trim((string)($input["color"] ?? ""));
    $badge = trim((string)($input["badge"] ?? ""));
    $estado = trim((string)($input["estado"] ?? "borrador"));

    if ($id <= 0) {
        echo json_encode([
            "ok" => false,
            "error" => "ID no válido"
        ]);
        exit;
    }

    if ($nombre === "") {
        echo json_encode([
            "ok" => false,
            "error" => "El nombre es obligatorio"
        ]);
        exit;
    }

    if ($precioBase <= 0) {
        echo json_encode([
            "ok" => false,
            "error" => "El precio base debe ser mayor que 0"
        ]);
        exit;
    }

    if ($slug === "") {
        $slug = dmh_slugify($nombre);
    }

    $stmtExiste = $conexion->prepare("SELECT id FROM productos WHERE slug = :slug AND id <> :id LIMIT 1");
    $stmtExiste->execute([
        "slug" => $slug,
        "id" => $id
    ]);

    if ($stmtExiste->fetch()) {
        echo json_encode([
            "ok" => false,
            "error" => "Ya existe otro producto con ese slug"
        ]);
        exit;
    }

    $stmt = $conexion->prepare("
        UPDATE productos
        SET
            nombre = :nombre,
            slug = :slug,
            descripcion = :descripcion,
            precio_base = :precio_base,
            precio_original = :precio_original,
            tipo = :tipo,
            color = :color,
            badge = :badge,
            estado = :estado
        WHERE id = :id
    ");

    $stmt->execute([
        "id" => $id,
        "nombre" => $nombre,
        "slug" => $slug,
        "descripcion" => $descripcion !== "" ? $descripcion : null,
        "precio_base" => $precioBase,
        "precio_original" => $precioOriginal,
        "tipo" => $tipo !== "" ? $tipo : null,
        "color" => $color !== "" ? $color : null,
        "badge" => $badge !== "" ? $badge : null,
        "estado" => in_array($estado, ["borrador", "publicado", "archivado"], true) ? $estado : "borrador",
    ]);

    echo json_encode([
        "ok" => true,
        "mensaje" => "Producto actualizado correctamente"
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "error" => "Error al actualizar producto",
        "detalle" => $e->getMessage()
    ]);
}