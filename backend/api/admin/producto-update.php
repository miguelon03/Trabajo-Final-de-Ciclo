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

function dmh_build_sku(string $slug): string {
    $sku = strtoupper(str_replace('-', '', $slug));
    $sku = preg_replace('/[^A-Z0-9]/', '', $sku) ?? '';
    return $sku !== '' ? substr($sku, 0, 24) : 'PRODUCTO';
}

function dmh_build_variant_sku(string $slug, string $talla): string {
    $base = dmh_build_sku($slug);
    $size = strtoupper(trim($talla));
    $size = preg_replace('/[^A-Z0-9]/', '', $size) ?? '';
    $size = $size !== '' ? $size : 'UNICA';
    return substr($base . '-' . $size, 0, 32);
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
    $badge = trim((string)($input["badge"] ?? ""));
    $estado = trim((string)($input["estado"] ?? "borrador"));
    $stockInicial = max(0, (int)($input["stock_inicial"] ?? 0));
    $variantes = is_array($input["variantes"] ?? null) ? $input["variantes"] : [];

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

    $conexion->beginTransaction();

    $stmt = $conexion->prepare("
        UPDATE productos
        SET
            nombre = :nombre,
            slug = :slug,
            descripcion = :descripcion,
            precio_base = :precio_base,
            precio_original = :precio_original,
            tipo = :tipo,
            color = NULL,
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
        "badge" => $badge !== "" ? $badge : null,
        "estado" => in_array($estado, ["borrador", "publicado", "archivado"], true) ? $estado : "borrador",
    ]);

    if ($stockInicial > 0) {
        $stockCalculado = 0;

        foreach ($variantes as $variante) {
            $stockCalculado += max(0, (int)($variante["stock"] ?? 0));
        }

        if ($stockCalculado !== $stockInicial) {
            throw new RuntimeException("La suma del stock por tallas no coincide con la cantidad a añadir");
        }

        $stmtBuscarVariante = $conexion->prepare("
            SELECT id
            FROM variantes_producto
            WHERE producto_id = :producto_id AND talla = :talla
            LIMIT 1
        ");

        $stmtActualizarVariante = $conexion->prepare("
            UPDATE variantes_producto
            SET stock = stock + :stock
            WHERE id = :id
        ");

        $stmtInsertarVariante = $conexion->prepare("
            INSERT INTO variantes_producto (
                producto_id, talla, color, sku, stock, precio_extra
            ) VALUES (
                :producto_id, :talla, NULL, :sku, :stock, 0
            )
        ");

        foreach ($variantes as $variante) {
            $talla = trim((string)($variante["talla"] ?? "Única"));
            $stock = max(0, (int)($variante["stock"] ?? 0));

            if ($stock <= 0) {
                continue;
            }

            $tallaFinal = $talla !== "" ? $talla : "Única";

            $stmtBuscarVariante->execute([
                "producto_id" => $id,
                "talla" => $tallaFinal,
            ]);

            $varianteExistente = $stmtBuscarVariante->fetch(PDO::FETCH_ASSOC);

            if ($varianteExistente) {
                $stmtActualizarVariante->execute([
                    "id" => (int)$varianteExistente["id"],
                    "stock" => $stock,
                ]);
            } else {
                $stmtInsertarVariante->execute([
                    "producto_id" => $id,
                    "talla" => $tallaFinal,
                    "sku" => dmh_build_variant_sku($slug, $tallaFinal),
                    "stock" => $stock,
                ]);
            }
        }
    }

    $conexion->commit();

    echo json_encode([
        "ok" => true,
        "mensaje" => "Producto actualizado correctamente"
    ]);
} catch (Throwable $e) {
    if (isset($conexion) && $conexion instanceof PDO && $conexion->inTransaction()) {
        $conexion->rollBack();
    }

    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "error" => "Error al actualizar producto",
        "detalle" => $e->getMessage()
    ]);
}