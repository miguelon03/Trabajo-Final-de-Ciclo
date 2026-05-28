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

try {
    $buscar = trim((string)($_GET["buscar"] ?? ""));
    $estado = trim((string)($_GET["estado"] ?? ""));
    $categoria = trim((string)($_GET["categoria"] ?? ""));

    $sql = "
        SELECT
            p.id,
            p.nombre,
            p.slug,
            p.descripcion,
            p.precio_base,
            p.precio_original,
            p.tallaje,
            p.tipo,
            p.color,
            p.badge,
            p.fecha_catalogo,
            p.estado,
            p.creado_en,
            COALESCE(MIN(c.nombre), 'General') AS categoria,
            COALESCE(stock.stock_total, 0) AS stock_total,
            (
                SELECT ip.url_imagen
                FROM imagenes_productos ip
                WHERE ip.producto_id = p.id
                ORDER BY ip.posicion ASC, ip.id ASC
                LIMIT 1
            ) AS imagen
        FROM productos p
        LEFT JOIN productos_categorias pc ON pc.producto_id = p.id
        LEFT JOIN categorias c ON c.id = pc.categoria_id
        LEFT JOIN (
            SELECT producto_id, SUM(stock) AS stock_total
            FROM variantes_producto
            GROUP BY producto_id
        ) stock ON stock.producto_id = p.id
        WHERE 1=1
    ";

    $params = [];

    if ($buscar !== "") {
        $sql .= " AND (p.nombre LIKE :buscar OR p.slug LIKE :buscar) ";
        $params["buscar"] = "%" . $buscar . "%";
    }

    if ($estado !== "") {
        $sql .= " AND p.estado = :estado ";
        $params["estado"] = $estado;
    }

    if ($categoria !== "") {
        $sql .= " AND c.slug = :categoria ";
        $params["categoria"] = $categoria;
    }

    $sql .= "
        GROUP BY
            p.id,
            p.nombre,
            p.slug,
            p.descripcion,
            p.precio_base,
            p.precio_original,
            p.tallaje,
            p.tipo,
            p.color,
            p.badge,
            p.fecha_catalogo,
            p.estado,
            p.creado_en,
            stock.stock_total
        ORDER BY p.creado_en DESC, p.id DESC
    ";

    $stmt = $conexion->prepare($sql);
    $stmt->execute($params);
    $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "ok" => true,
        "productos" => $productos
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "error" => "Error al obtener productos",
        "detalle" => $e->getMessage()
    ]);
}