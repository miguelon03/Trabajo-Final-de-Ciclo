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

function dmh_slugify(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/i', '-', $value) ?? '';
    $value = trim($value, '-');
    return $value !== '' ? $value : 'producto';
}

function dmh_build_sku(string $slug): string
{
    $sku = strtoupper(str_replace('-', '', $slug));
    $sku = preg_replace('/[^A-Z0-9]/', '', $sku) ?? '';
    return $sku !== '' ? substr($sku, 0, 24) : 'PRODUCTO';
}

function dmh_build_variant_sku(string $slug, string $talla): string
{
    $base = dmh_build_sku($slug);
    $size = strtoupper(trim($talla));
    $size = preg_replace('/[^A-Z0-9]/', '', $size) ?? '';
    $size = $size !== '' ? $size : 'UNICA';
    return substr($base . '-' . $size, 0, 32);
}

function dmh_save_product_image(array $image, string $slug, int $position): string
{
    $dataUrl = trim((string)($image["data_url"] ?? ""));

    if ($dataUrl === "") {
        throw new RuntimeException("Imagen vacía");
    }

    if (!preg_match('/^data:image\/(jpeg|jpg|png|webp);base64,(.+)$/i', $dataUrl, $matches)) {
        throw new RuntimeException("Formato de imagen no válido");
    }

    $extension = strtolower($matches[1]);
    if ($extension === "jpeg") {
        $extension = "jpg";
    }

    $binary = base64_decode($matches[2], true);
    if ($binary === false) {
        throw new RuntimeException("No se pudo decodificar una imagen");
    }

    $maxBytes = 4 * 1024 * 1024;
    if (strlen($binary) > $maxBytes) {
        throw new RuntimeException("Cada imagen no puede superar los 4MB");
    }

    $allowed = ["jpg", "png", "webp"];
    if (!in_array($extension, $allowed, true)) {
        throw new RuntimeException("Las imágenes deben ser JPG, PNG o WEBP");
    }

    $projectRoot = dirname(__DIR__, 3);
    $uploadDir = $projectRoot . "/frontend/public/uploads/productos";

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        throw new RuntimeException("No se pudo crear la carpeta de imágenes");
    }

    $safeSlug = preg_replace('/[^a-z0-9\\-]/', '', strtolower($slug)) ?: 'producto';
    $filename = $safeSlug . "-" . time() . "-" . $position . "." . $extension;
    $absolutePath = $uploadDir . "/" . $filename;

    if (file_put_contents($absolutePath, $binary) === false) {
        throw new RuntimeException("No se pudo guardar una imagen");
    }

    return "/uploads/productos/" . $filename;
}

try {
    $input = json_decode(file_get_contents("php://input"), true);

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
    $imagenes = is_array($input["imagenes"] ?? null) ? $input["imagenes"] : [];

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

    if (count($imagenes) > 3) {
        throw new RuntimeException("Solo puedes subir hasta 3 imágenes");
    }

    $stmtExiste = $conexion->prepare("SELECT id FROM productos WHERE slug = :slug LIMIT 1");
    $stmtExiste->execute(["slug" => $slug]);

    if ($stmtExiste->fetch()) {
        echo json_encode([
            "ok" => false,
            "error" => "Ya existe un producto con ese slug"
        ]);
        exit;
    }

    $conexion->beginTransaction();

    $stmt = $conexion->prepare("
        INSERT INTO productos (
            nombre, slug, descripcion, precio_base, precio_original,
            tipo, color, badge, fecha_catalogo, estado
        ) VALUES (
            :nombre, :slug, :descripcion, :precio_base, :precio_original,
            :tipo, NULL, :badge, CURDATE(), :estado
        )
    ");

    $stmt->execute([
        "nombre" => $nombre,
        "slug" => $slug,
        "descripcion" => $descripcion !== "" ? $descripcion : null,
        "precio_base" => $precioBase,
        "precio_original" => $precioOriginal,
        "tipo" => $tipo !== "" ? $tipo : null,
        "badge" => $badge !== "" ? $badge : null,
        "estado" => in_array($estado, ["borrador", "publicado", "archivado"], true) ? $estado : "borrador",
    ]);

    $productoId = (int)$conexion->lastInsertId();

    if ($stockInicial > 0) {
        $stockCalculado = 0;

        foreach ($variantes as $variante) {
            $stockCalculado += max(0, (int)($variante["stock"] ?? 0));
        }

        if ($stockCalculado !== $stockInicial) {
            throw new RuntimeException("La suma del stock por tallas no coincide con el stock inicial");
        }

        $stmtVariante = $conexion->prepare("
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

            $stmtVariante->execute([
                "producto_id" => $productoId,
                "talla" => $talla !== "" ? $talla : "Única",
                "sku" => dmh_build_variant_sku($slug, $talla),
                "stock" => $stock,
            ]);
        }
    }

    if (!empty($imagenes)) {
        $stmtImagen = $conexion->prepare("
            INSERT INTO imagenes_productos (producto_id, url_imagen, posicion)
            VALUES (:producto_id, :url_imagen, :posicion)
        ");

        foreach ($imagenes as $index => $imagen) {
            $urlImagen = dmh_save_product_image($imagen, $slug, $index + 1);

            $stmtImagen->execute([
                "producto_id" => $productoId,
                "url_imagen" => $urlImagen,
                "posicion" => $index + 1,
            ]);
        }
    }

    $conexion->commit();

    echo json_encode([
        "ok" => true,
        "mensaje" => "Producto creado correctamente",
        "id" => $productoId
    ]);
} catch (Throwable $e) {
    if (isset($conexion) && $conexion instanceof PDO && $conexion->inTransaction()) {
        $conexion->rollBack();
    }

    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "error" => "Error al crear producto",
        "detalle" => $e->getMessage()
    ]);
}