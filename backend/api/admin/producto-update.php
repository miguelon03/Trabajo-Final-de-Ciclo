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

function dmh_ensure_tallaje_column(PDO $conexion): void
{
    $stmt = $conexion->query(
        "SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'productos'
           AND COLUMN_NAME = 'tallaje'"
    );

    if ((int)$stmt->fetchColumn() === 0) {
        $conexion->exec("ALTER TABLE productos ADD COLUMN tallaje ENUM('clasico','pantalon','unica') NOT NULL DEFAULT 'clasico' AFTER precio_original");
    }
}

dmh_ensure_tallaje_column($conexion);

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

function dmh_has_money_format(string $value): bool {
    return (bool)preg_match('/^\d+(?:[\.,]\d{1,2})?$/', trim($value));
}

function dmh_parse_money(string $value): float {
    return (float)str_replace(',', '.', trim($value));
}

function dmh_normalize_badge(string $value): ?string {
    $normalized = strtolower(trim($value));

    if ($normalized === '') return null;
    if ($normalized === 'new') return 'NEW';
    if ($normalized === 'oferta') return 'Oferta';

    return null;
}

try {
    $input = json_decode(file_get_contents("php://input"), true);

    $id = (int)($input["id"] ?? 0);
    $nombre = trim((string)($input["nombre"] ?? ""));
    $slug = trim((string)($input["slug"] ?? ""));
    $descripcion = trim((string)($input["descripcion"] ?? ""));
    $precioBaseRaw = trim((string)($input["precio_base"] ?? ""));
    $precioOriginalRaw = trim((string)($input["precio_original"] ?? ""));
    $precioOriginalInformado = $precioOriginalRaw !== "";
    $precioBase = 0.0;
    $precioOriginal = null;
    $tallaje = strtolower(trim((string)($input["tallaje"] ?? "clasico")));
    $tipo = trim((string)($input["tipo"] ?? ""));
    $badgeRaw = trim((string)($input["badge"] ?? ""));
    $badge = dmh_normalize_badge($badgeRaw);
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

    if ($descripcion === "") {
        echo json_encode([
            "ok" => false,
            "error" => "La descripción es obligatoria"
        ]);
        exit;
    }

    if (!dmh_has_money_format($precioBaseRaw)) {
        echo json_encode([
            "ok" => false,
            "error" => "El precio oferta debe tener 2 decimales (ej: 100,00)"
        ]);
        exit;
    }

    $precioBase = dmh_parse_money($precioBaseRaw);

    if ($precioBase <= 0) {
        echo json_encode([
            "ok" => false,
            "error" => "El precio actual debe ser mayor que 0"
        ]);
        exit;
    }

    if ($tipo === "") {
        echo json_encode([
            "ok" => false,
            "error" => "El tipo es obligatorio"
        ]);
        exit;
    }

    if ($badgeRaw !== '' && $badge === null) {
        echo json_encode([
            "ok" => false,
            "error" => "La badge seleccionada no es válida"
        ]);
        exit;
    }

    if (!in_array($tallaje, ["clasico", "pantalon", "unica"], true)) {
        $tallaje = "clasico";
    }

    if ($precioOriginalInformado) {
        if (!dmh_has_money_format($precioOriginalRaw)) {
            echo json_encode([
                "ok" => false,
                "error" => "El precio original debe tener 2 decimales (ej: 100,00)"
            ]);
            exit;
        }

        $precioOriginal = dmh_parse_money($precioOriginalRaw);

        if ($precioOriginal <= 0) {
            echo json_encode([
                "ok" => false,
                "error" => "El precio original debe ser mayor que 0"
            ]);
            exit;
        }

        if ($precioBase >= $precioOriginal) {
            echo json_encode([
                "ok" => false,
                "error" => "El precio actual debe ser inferior al precio original"
            ]);
            exit;
        }
    }

    if ($badge === 'Oferta' && !$precioOriginalInformado) {
        echo json_encode([
            "ok" => false,
            "error" => "Con badge Oferta debes completar el precio original"
        ]);
        exit;
    }

    if ($badge !== 'Oferta' && $precioOriginalInformado) {
        echo json_encode([
            "ok" => false,
            "error" => "El precio original solo se permite cuando la badge es Oferta"
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
            tallaje = :tallaje,
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
        "tallaje" => $tallaje,
        "tipo" => $tipo !== "" ? $tipo : null,
        "badge" => $badge,
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