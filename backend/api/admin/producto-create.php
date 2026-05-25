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
    $value = trim($value);

    if (function_exists("iconv")) {
        $normalized = iconv("UTF-8", "ASCII//TRANSLIT", $value);
        if ($normalized !== false) {
            $value = $normalized;
        }
    }

    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/i', '-', $value) ?? '';
    $value = trim($value, '-');

    return $value !== '' ? $value : 'producto';
}

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

function dmh_build_unique_sku(PDO $conexion, string $slug): string {
    $base = strtoupper(preg_replace('/[^A-Z0-9]+/i', '-', $slug) ?? 'PRODUCTO');
    $base = trim($base, '-');
    $base = $base !== '' ? $base : 'PRODUCTO';
    $base = substr($base, 0, 80);

    $candidate = $base . '-UNICA';
    $suffix = 1;

    $stmt = $conexion->prepare("SELECT COUNT(*) FROM variantes_producto WHERE sku = :sku");

    while (true) {
        $stmt->execute(["sku" => $candidate]);
        $exists = (int)$stmt->fetchColumn() > 0;

        if (!$exists) {
            return $candidate;
        }

        $suffix++;
        $candidate = substr($base, 0, 74) . '-UNICA-' . $suffix;
    }
}

function dmh_get_product_images_input(array $input): array {
    $images = [];

    if (isset($input["imagenes"]) && is_array($input["imagenes"])) {
        foreach ($input["imagenes"] as $image) {
            if (!is_array($image)) {
                continue;
            }

            $dataUrl = trim((string)($image["data_url"] ?? ""));
            if ($dataUrl === "") {
                continue;
            }

            $images[] = [
                "data_url" => $dataUrl,
                "nombre" => trim((string)($image["nombre"] ?? "")),
            ];
        }
    }

    // Compatibilidad con el sistema anterior de una sola imagen.
    if (count($images) === 0) {
        $legacyDataUrl = trim((string)($input["imagen_data_url"] ?? ""));
        if ($legacyDataUrl !== "") {
            $images[] = [
                "data_url" => $legacyDataUrl,
                "nombre" => trim((string)($input["imagen_nombre"] ?? "")),
            ];
        }
    }

    return $images;
}

function dmh_save_uploaded_product_image(string $dataUrl, string $slug, int $position): string {
    $dataUrl = trim($dataUrl);

    if ($dataUrl === "") {
        throw new RuntimeException("Imagen vacía");
    }

    if (!preg_match('/^data:(image\/(?:jpeg|jpg|png|webp));base64,(.+)$/', $dataUrl, $matches)) {
        throw new RuntimeException("Las imágenes deben ser JPG, PNG o WEBP");
    }

    $mime = strtolower($matches[1]);
    $base64 = $matches[2];
    $binary = base64_decode($base64, true);

    if ($binary === false) {
        throw new RuntimeException("No se pudo leer una de las imágenes subidas");
    }

    $maxBytes = 4 * 1024 * 1024;
    if (strlen($binary) > $maxBytes) {
        throw new RuntimeException("Cada imagen no puede superar los 4MB");
    }

    $extensionByMime = [
        "image/jpeg" => "jpg",
        "image/jpg" => "jpg",
        "image/png" => "png",
        "image/webp" => "webp",
    ];

    $extension = $extensionByMime[$mime] ?? null;
    if ($extension === null) {
        throw new RuntimeException("Formato de imagen no permitido");
    }

    $safeSlug = dmh_slugify($slug);

    $uploadDir = realpath(__DIR__ . "/../../../frontend/public/productos");

    if ($uploadDir === false) {
        $uploadDir = __DIR__ . "/../../../frontend/public/productos";

        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true)) {
            throw new RuntimeException("No se pudo crear la carpeta de imágenes");
        }

        $uploadDir = realpath($uploadDir);
    }

    if ($uploadDir === false || !is_dir($uploadDir)) {
        throw new RuntimeException("La carpeta de imágenes no existe");
    }

    if (!is_writable($uploadDir)) {
        throw new RuntimeException("La carpeta frontend/public/productos no tiene permisos de escritura");
    }

    $random = bin2hex(random_bytes(4));
    $fileName = $safeSlug . '-' . date('YmdHis') . '-' . $position . '-' . $random . '.' . $extension;
    $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $fileName;

    if (file_put_contents($targetPath, $binary) === false) {
        throw new RuntimeException("No se pudo guardar una de las imágenes del producto");
    }

    return "/productos/" . $fileName;
}

try {
    $input = json_decode(file_get_contents("php://input"), true);

    if (!is_array($input)) {
        echo json_encode([
            "ok" => false,
            "error" => "Datos de producto no válidos"
        ]);
        exit;
    }

    $nombre = trim((string)($input["nombre"] ?? ""));
    $slug = trim((string)($input["slug"] ?? ""));
    $descripcion = trim((string)($input["descripcion"] ?? ""));
    $precioBase = (float)($input["precio_base"] ?? 0);
    $precioOriginal = isset($input["precio_original"]) && $input["precio_original"] !== ""
        ? (float)$input["precio_original"]
        : null;
    $tipo = trim((string)($input["tipo"] ?? ""));
    $color = trim((string)($input["color"] ?? ""));
    $badge = dmh_normalize_badge((string)($input["badge"] ?? ""));
    $estado = trim((string)($input["estado"] ?? "borrador"));

    $stockInicialRaw = $input["stock_inicial"] ?? 0;
    $stockInicial = is_numeric($stockInicialRaw) ? (int)$stockInicialRaw : -1;

    $imagenes = dmh_get_product_images_input($input);

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

    if ($badge === "__INVALID__") {
        echo json_encode([
            "ok" => false,
            "error" => "La badge solo puede ser NEW, oferta o sin badge"
        ]);
        exit;
    }

    if ($stockInicial < 0) {
        echo json_encode([
            "ok" => false,
            "error" => "El stock inicial no puede ser negativo"
        ]);
        exit;
    }

    if (count($imagenes) > 3) {
        echo json_encode([
            "ok" => false,
            "error" => "Solo puedes subir hasta 3 imágenes por producto"
        ]);
        exit;
    }

    if ($slug === "") {
        $slug = dmh_slugify($nombre);
    } else {
        $slug = dmh_slugify($slug);
    }

    $estadoFinal = in_array($estado, ["borrador", "publicado", "archivado"], true)
        ? $estado
        : "borrador";

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
            :tipo, :color, :badge, CURDATE(), :estado
        )
    ");

    $stmt->execute([
        "nombre" => $nombre,
        "slug" => $slug,
        "descripcion" => $descripcion !== "" ? $descripcion : null,
        "precio_base" => $precioBase,
        "precio_original" => $precioOriginal,
        "tipo" => $tipo !== "" ? $tipo : null,
        "color" => $color !== "" ? $color : null,
        "badge" => $badge,
        "estado" => $estadoFinal,
    ]);

    $productoId = (int)$conexion->lastInsertId();

    if (count($imagenes) > 0) {
        $stmtImagen = $conexion->prepare("
            INSERT INTO imagenes_productos (producto_id, url_imagen, posicion)
            VALUES (:producto_id, :url_imagen, :posicion)
        ");

        foreach ($imagenes as $index => $imagen) {
            $imageUrl = dmh_save_uploaded_product_image(
                (string)$imagen["data_url"],
                $slug,
                $index
            );

            $stmtImagen->execute([
                "producto_id" => $productoId,
                "url_imagen" => $imageUrl,
                "posicion" => $index,
            ]);
        }
    }

    if ($stockInicial > 0) {
        $sku = dmh_build_unique_sku($conexion, $slug);

        $stmtVariante = $conexion->prepare("
            INSERT INTO variantes_producto (producto_id, talla, color, sku, stock, precio_extra)
            VALUES (:producto_id, :talla, :color, :sku, :stock, 0)
        ");

        $stmtVariante->execute([
            "producto_id" => $productoId,
            "talla" => "Única",
            "color" => $color !== "" ? $color : null,
            "sku" => $sku,
            "stock" => $stockInicial,
        ]);
    }

    $conexion->commit();

    echo json_encode([
        "ok" => true,
        "mensaje" => "Producto creado correctamente",
        "id" => $productoId
    ]);
} catch (Throwable $e) {
    if (isset($conexion) && $conexion->inTransaction()) {
        $conexion->rollBack();
    }

    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "error" => "Error al crear producto",
        "detalle" => $e->getMessage()
    ]);
}