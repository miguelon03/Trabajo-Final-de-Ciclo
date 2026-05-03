<?php

header("Access-Control-Allow-Origin: http://localhost:4321");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit();
}

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    http_response_code(405);
    echo json_encode([
        "ok" => false,
        "error" => "Método no permitido",
    ]);
    exit;
}

require_once __DIR__ . "/../conexion.php";

try {
    $slug = trim((string)($_GET["slug"] ?? ""));

    $sqlProductos = "
        SELECT
            p.id,
            p.slug,
            p.nombre,
            p.descripcion,
            p.precio_base,
            p.precio_original,
            p.tipo,
            p.color,
            p.badge,
            p.fecha_catalogo,
            p.creado_en,
            COALESCE(MIN(c.nombre), 'General') AS categoria
        FROM productos p
        LEFT JOIN productos_categorias pc ON pc.producto_id = p.id
        LEFT JOIN categorias c ON c.id = pc.categoria_id
        WHERE p.estado = 'publicado'
    ";

    $params = [];
    if ($slug !== "") {
        $sqlProductos .= " AND p.slug = :slug ";
        $params["slug"] = $slug;
    }

    $sqlProductos .= "
        GROUP BY
            p.id,
            p.slug,
            p.nombre,
            p.descripcion,
            p.precio_base,
            p.precio_original,
            p.tipo,
            p.color,
            p.badge,
            p.fecha_catalogo,
            p.creado_en
        ORDER BY COALESCE(p.fecha_catalogo, DATE(p.creado_en)) DESC, p.id DESC
    ";

    $stmtProductos = $conexion->prepare($sqlProductos);
    $stmtProductos->execute($params);
    $rowsProductos = $stmtProductos->fetchAll();

    if (!$rowsProductos) {
        echo json_encode([
            "ok" => true,
            "products" => [],
            "stock" => [],
            "reviews" => [],
        ]);
        exit;
    }

    $productIds = array_map(static fn($row) => (int)$row["id"], $rowsProductos);
    $inClause = implode(",", array_fill(0, count($productIds), "?"));

    $stmtImagenes = $conexion->prepare(
        "SELECT producto_id, url_imagen FROM imagenes_productos WHERE producto_id IN ($inClause) ORDER BY producto_id ASC, posicion ASC"
    );
    $stmtImagenes->execute($productIds);
    $rowsImagenes = $stmtImagenes->fetchAll();

    $stmtVariantes = $conexion->prepare(
        "SELECT producto_id, talla, color, sku, stock FROM variantes_producto WHERE producto_id IN ($inClause) ORDER BY producto_id ASC, id ASC"
    );
    $stmtVariantes->execute($productIds);
    $rowsVariantes = $stmtVariantes->fetchAll();

    $stmtOpiniones = $conexion->prepare(
        "
        SELECT
            o.id,
            o.producto_id,
            o.puntuacion,
            o.comentario,
            o.creado_en,
            u.nombre AS usuario_nombre
        FROM opiniones o
        INNER JOIN usuarios u ON u.id = o.usuario_id
        WHERE o.producto_id IN ($inClause)
        ORDER BY o.producto_id ASC, o.creado_en DESC, o.id DESC
        "
    );
    $stmtOpiniones->execute($productIds);
    $rowsOpiniones = $stmtOpiniones->fetchAll();

    $imagesByProductId = [];
    foreach ($rowsImagenes as $img) {
        $pid = (int)$img["producto_id"];
        if (!isset($imagesByProductId[$pid])) {
            $imagesByProductId[$pid] = [];
        }
        $imagesByProductId[$pid][] = (string)$img["url_imagen"];
    }

    $stockByProductId = [];
    foreach ($rowsVariantes as $variant) {
        $pid = (int)$variant["producto_id"];
        if (!isset($stockByProductId[$pid])) {
            $stockByProductId[$pid] = [
                "productId" => $pid,
                "productSlug" => "",
                "totalStock" => 0,
                "bySize" => [],
            ];
        }

        $size = (string)$variant["talla"];
        $stock = (int)$variant["stock"];
        $stockByProductId[$pid]["totalStock"] += $stock;
        $stockByProductId[$pid]["bySize"][$size] = [
            "stock" => $stock,
            "sku" => (string)$variant["sku"],
        ];
    }

    $reviewsByProductId = [];
    foreach ($rowsOpiniones as $review) {
        $pid = (int)$review["producto_id"];
        if (!isset($reviewsByProductId[$pid])) {
            $reviewsByProductId[$pid] = [
                "productId" => $pid,
                "productSlug" => "",
                "averageRating" => 0,
                "totalReviews" => 0,
                "items" => [],
                "_ratingSum" => 0,
            ];
        }

        $rating = (int)$review["puntuacion"];
        $reviewsByProductId[$pid]["totalReviews"] += 1;
        $reviewsByProductId[$pid]["_ratingSum"] += $rating;

        $reviewsByProductId[$pid]["items"][] = [
            "id" => (string)$review["id"],
            "user" => (string)$review["usuario_nombre"],
            "rating" => $rating,
            "comment" => (string)($review["comentario"] ?? ""),
            "date" => substr((string)$review["creado_en"], 0, 10),
            "verifiedPurchase" => true,
        ];
    }

    $products = [];
    foreach ($rowsProductos as $row) {
        $pid = (int)$row["id"];
        $slugProducto = (string)$row["slug"];
        $images = $imagesByProductId[$pid] ?? [];

        $products[] = [
            "id" => $pid,
            "slug" => $slugProducto,
            "title" => (string)$row["nombre"],
            "category" => (string)$row["categoria"],
            "price" => (float)$row["precio_base"],
            "originalPrice" => $row["precio_original"] !== null ? (float)$row["precio_original"] : null,
            "type" => (string)($row["tipo"] ?? ""),
            "color" => (string)($row["color"] ?? ""),
            "badge" => (string)($row["badge"] ?? ""),
            "createdAt" => $row["fecha_catalogo"] ? (string)$row["fecha_catalogo"] : substr((string)$row["creado_en"], 0, 10),
            "image" => count($images) ? $images[0] : "placeholder",
            "images" => $images,
            "description" => (string)($row["descripcion"] ?? ""),
        ];

        if (!isset($stockByProductId[$pid])) {
            $stockByProductId[$pid] = [
                "productId" => $pid,
                "productSlug" => $slugProducto,
                "totalStock" => 0,
                "bySize" => [],
            ];
        } else {
            $stockByProductId[$pid]["productSlug"] = $slugProducto;
        }

        if (!isset($reviewsByProductId[$pid])) {
            $reviewsByProductId[$pid] = [
                "productId" => $pid,
                "productSlug" => $slugProducto,
                "averageRating" => 0,
                "totalReviews" => 0,
                "items" => [],
                "_ratingSum" => 0,
            ];
        } else {
            $reviewsByProductId[$pid]["productSlug"] = $slugProducto;
        }
    }

    $stock = array_values($stockByProductId);

    $reviews = [];
    foreach ($reviewsByProductId as $reviewGroup) {
        $totalReviews = (int)$reviewGroup["totalReviews"];
        $ratingSum = (int)$reviewGroup["_ratingSum"];
        $reviewGroup["averageRating"] = $totalReviews > 0 ? round($ratingSum / $totalReviews, 1) : 0;
        unset($reviewGroup["_ratingSum"]);
        $reviews[] = $reviewGroup;
    }

    echo json_encode([
        "ok" => true,
        "products" => $products,
        "stock" => $stock,
        "reviews" => $reviews,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "error" => "Error al obtener catálogo",
        "detalle" => $e->getMessage(),
    ]);
}
