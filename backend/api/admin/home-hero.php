<?php
$dmhAllowedOrigins = ["http://localhost:4321", "https://dripmode.com"];
$dmhOrigin = $_SERVER["HTTP_ORIGIN"] ?? "";
if (in_array($dmhOrigin, $dmhAllowedOrigins, true)) {
    header("Access-Control-Allow-Origin: " . $dmhOrigin);
}
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
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

$defaultHero = [
    "eyebrow" => "SS26 COLLECTION",
    "title" => "DRIP\nMODE\nHOOD",
    "description" => "Prendas premium de edición limitada. Diseñadas para durar. Fabricadas en Europa.",
    "primary_label" => "VER COLECCIÓN",
    "primary_href" => "/catalogo",
    "secondary_label" => "Novedades",
    "secondary_href" => "/novedades",
    "product_image" => "",
    "product_image_scale" => 100,
    "product_image_pos_x" => 0,
    "product_image_pos_y" => 0,
    "background_image" => "",
    "background_image_scale" => 100,
    "background_image_pos_x" => 0,
    "background_image_pos_y" => 0,
    "updated_at" => null,
];

function dmh_ensure_home_hero_table(PDO $conexion): void
{
    $conexion->exec(
        "CREATE TABLE IF NOT EXISTS home_hero_config (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            eyebrow VARCHAR(80) NOT NULL,
            title VARCHAR(120) NOT NULL,
            description VARCHAR(320) NOT NULL,
            primary_label VARCHAR(40) NOT NULL,
            primary_href VARCHAR(255) NOT NULL,
            secondary_label VARCHAR(40) NOT NULL,
            secondary_href VARCHAR(255) NOT NULL,
            product_image VARCHAR(255) NOT NULL DEFAULT '',
            product_image_scale DECIMAL(6,2) NOT NULL DEFAULT 100,
            product_image_pos_x DECIMAL(6,2) NOT NULL DEFAULT 0,
            product_image_pos_y DECIMAL(6,2) NOT NULL DEFAULT 0,
            background_image VARCHAR(255) NOT NULL DEFAULT '',
            background_image_scale DECIMAL(6,2) NOT NULL DEFAULT 100,
            background_image_pos_x DECIMAL(6,2) NOT NULL DEFAULT 0,
            background_image_pos_y DECIMAL(6,2) NOT NULL DEFAULT 0,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function dmh_normalize_hero_row(array $row, array $defaultHero): array
{
    return [
        "eyebrow" => (string)($row["eyebrow"] ?? $defaultHero["eyebrow"]),
        "title" => (string)($row["title"] ?? $defaultHero["title"]),
        "description" => (string)($row["description"] ?? $defaultHero["description"]),
        "primary_label" => (string)($row["primary_label"] ?? $defaultHero["primary_label"]),
        "primary_href" => (string)($row["primary_href"] ?? $defaultHero["primary_href"]),
        "secondary_label" => (string)($row["secondary_label"] ?? $defaultHero["secondary_label"]),
        "secondary_href" => (string)($row["secondary_href"] ?? $defaultHero["secondary_href"]),
        "product_image" => (string)($row["product_image"] ?? ""),
        "product_image_scale" => (float)($row["product_image_scale"] ?? $defaultHero["product_image_scale"]),
        "product_image_pos_x" => (float)($row["product_image_pos_x"] ?? $defaultHero["product_image_pos_x"]),
        "product_image_pos_y" => (float)($row["product_image_pos_y"] ?? $defaultHero["product_image_pos_y"]),
        "background_image" => (string)($row["background_image"] ?? ""),
        "background_image_scale" => (float)($row["background_image_scale"] ?? $defaultHero["background_image_scale"]),
        "background_image_pos_x" => (float)($row["background_image_pos_x"] ?? $defaultHero["background_image_pos_x"]),
        "background_image_pos_y" => (float)($row["background_image_pos_y"] ?? $defaultHero["background_image_pos_y"]),
        "updated_at" => isset($row["updated_at"]) ? (string)$row["updated_at"] : null,
    ];
}

function dmh_insert_default_hero(PDO $conexion, array $hero): void
{
    $stmt = $conexion->prepare(
        "INSERT INTO home_hero_config (
            id,
            eyebrow,
            title,
            description,
            primary_label,
            primary_href,
            secondary_label,
            secondary_href,
            product_image,
            product_image_scale,
            product_image_pos_x,
            product_image_pos_y,
            background_image,
            background_image_scale,
            background_image_pos_x,
            background_image_pos_y,
            updated_at
        ) VALUES (
            1,
            :eyebrow,
            :title,
            :description,
            :primary_label,
            :primary_href,
            :secondary_label,
            :secondary_href,
            :product_image,
            :product_image_scale,
            :product_image_pos_x,
            :product_image_pos_y,
            :background_image,
            :background_image_scale,
            :background_image_pos_x,
            :background_image_pos_y,
            NOW()
        )"
    );

    $stmt->execute([
        "eyebrow" => $hero["eyebrow"],
        "title" => $hero["title"],
        "description" => $hero["description"],
        "primary_label" => $hero["primary_label"],
        "primary_href" => $hero["primary_href"],
        "secondary_label" => $hero["secondary_label"],
        "secondary_href" => $hero["secondary_href"],
        "product_image" => $hero["product_image"],
        "product_image_scale" => $hero["product_image_scale"],
        "product_image_pos_x" => $hero["product_image_pos_x"],
        "product_image_pos_y" => $hero["product_image_pos_y"],
        "background_image" => $hero["background_image"],
        "background_image_scale" => $hero["background_image_scale"],
        "background_image_pos_x" => $hero["background_image_pos_x"],
        "background_image_pos_y" => $hero["background_image_pos_y"],
    ]);
}

function dmh_read_hero_config(PDO $conexion, array $defaultHero): array
{
    dmh_ensure_home_hero_table($conexion);

    $stmt = $conexion->query("SELECT * FROM home_hero_config WHERE id = 1 LIMIT 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (is_array($row)) {
        return dmh_normalize_hero_row($row, $defaultHero);
    }

    dmh_insert_default_hero($conexion, $defaultHero);

    $stmt = $conexion->query("SELECT * FROM home_hero_config WHERE id = 1 LIMIT 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return $defaultHero;
    }

    return dmh_normalize_hero_row($row, $defaultHero);
}

function dmh_sanitize_text($value, int $maxLen): string
{
    $text = trim((string)$value);
    if ($text === "") {
        return "";
    }

    return mb_substr($text, 0, $maxLen);
}

function dmh_sanitize_number($value, float $min, float $max, float $fallback): float
{
    if (!is_numeric($value)) {
        return $fallback;
    }

    $number = (float)$value;
    if ($number < $min) {
        return $min;
    }

    if ($number > $max) {
        return $max;
    }

    return $number;
}

function dmh_save_hero_image(string $dataUrl, string $prefix): string
{
    if (!preg_match('/^data:image\/(jpeg|jpg|png|webp);base64,(.+)$/i', $dataUrl, $matches)) {
        throw new RuntimeException("Formato de imagen no válido");
    }

    $extension = strtolower($matches[1]);
    if ($extension === "jpeg") {
        $extension = "jpg";
    }

    $binary = base64_decode($matches[2], true);
    if ($binary === false) {
        throw new RuntimeException("No se pudo leer la imagen");
    }

    $maxBytes = 6 * 1024 * 1024;
    if (strlen($binary) > $maxBytes) {
        throw new RuntimeException("Cada imagen no puede superar los 6MB");
    }

    $projectRoot = dirname(__DIR__, 3);
    $uploadDir = $projectRoot . "/frontend/public/uploads/home";

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        throw new RuntimeException("No se pudo crear la carpeta de imágenes del hero");
    }

    $safePrefix = preg_replace('/[^a-z0-9\-]/', '-', strtolower($prefix)) ?: 'hero';
    $filename = sprintf(
        "%s-%s-%s.%s",
        $safePrefix,
        date("YmdHis"),
        substr(bin2hex(random_bytes(4)), 0, 8),
        $extension
    );

    $absolutePath = $uploadDir . "/" . $filename;
    if (file_put_contents($absolutePath, $binary) === false) {
        throw new RuntimeException("No se pudo guardar la imagen del hero");
    }

    return "/uploads/home/" . $filename;
}

if ($_SERVER["REQUEST_METHOD"] === "GET") {
    echo json_encode([
        "ok" => true,
        "hero" => dmh_read_hero_config($conexion, $defaultHero),
    ]);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode([
        "ok" => false,
        "error" => "Método no permitido"
    ]);
    exit;
}

try {
    $payload = json_decode(file_get_contents("php://input"), true);
    if (!is_array($payload)) {
        throw new RuntimeException("Payload inválido");
    }

    $current = dmh_read_hero_config($conexion, $defaultHero);

    $productImage = (string)($current["product_image"] ?? "");
    $backgroundImage = (string)($current["background_image"] ?? "");

    $productImageDataUrl = trim((string)($payload["product_image_data_url"] ?? ""));
    if ($productImageDataUrl !== "") {
        $productImage = dmh_save_hero_image($productImageDataUrl, "hero-product");
    }

    $backgroundImageDataUrl = trim((string)($payload["background_image_data_url"] ?? ""));
    if ($backgroundImageDataUrl !== "") {
        $backgroundImage = dmh_save_hero_image($backgroundImageDataUrl, "hero-bg");
    }

    if (!empty($payload["remove_product_image"])) {
        $productImage = "";
    }

    if (!empty($payload["remove_background_image"])) {
        $backgroundImage = "";
    }

    $hero = [
        "eyebrow" => dmh_sanitize_text($payload["eyebrow"] ?? $current["eyebrow"], 80),
        "title" => dmh_sanitize_text($payload["title"] ?? $current["title"], 120),
        "description" => dmh_sanitize_text($payload["description"] ?? $current["description"], 320),
        "primary_label" => dmh_sanitize_text($payload["primary_label"] ?? $current["primary_label"], 40),
        "primary_href" => "/catalogo",
        "secondary_label" => dmh_sanitize_text($payload["secondary_label"] ?? $current["secondary_label"], 40),
        "secondary_href" => "/novedades",
        "product_image" => $productImage,
        "product_image_scale" => dmh_sanitize_number(
            $payload["product_image_scale"] ?? $current["product_image_scale"],
            80,
            200,
            100
        ),
        "product_image_pos_x" => dmh_sanitize_number(
            $payload["product_image_pos_x"] ?? $current["product_image_pos_x"],
            -50,
            50,
            0
        ),
        "product_image_pos_y" => dmh_sanitize_number(
            $payload["product_image_pos_y"] ?? $current["product_image_pos_y"],
            -50,
            50,
            0
        ),
        "background_image" => $backgroundImage,
        "background_image_scale" => dmh_sanitize_number(
            $payload["background_image_scale"] ?? $current["background_image_scale"],
            80,
            220,
            100
        ),
        "background_image_pos_x" => dmh_sanitize_number(
            $payload["background_image_pos_x"] ?? $current["background_image_pos_x"],
            -50,
            50,
            0
        ),
        "background_image_pos_y" => dmh_sanitize_number(
            $payload["background_image_pos_y"] ?? $current["background_image_pos_y"],
            -50,
            50,
            0
        ),
        "updated_at" => date("Y-m-d H:i:s"),
    ];

    dmh_ensure_home_hero_table($conexion);

    $stmt = $conexion->prepare(
        "INSERT INTO home_hero_config (
            id,
            eyebrow,
            title,
            description,
            primary_label,
            primary_href,
            secondary_label,
            secondary_href,
            product_image,
            product_image_scale,
            product_image_pos_x,
            product_image_pos_y,
            background_image,
            background_image_scale,
            background_image_pos_x,
            background_image_pos_y,
            updated_at
        ) VALUES (
            1,
            :eyebrow,
            :title,
            :description,
            :primary_label,
            :primary_href,
            :secondary_label,
            :secondary_href,
            :product_image,
            :product_image_scale,
            :product_image_pos_x,
            :product_image_pos_y,
            :background_image,
            :background_image_scale,
            :background_image_pos_x,
            :background_image_pos_y,
            NOW()
        )
        ON DUPLICATE KEY UPDATE
            eyebrow = VALUES(eyebrow),
            title = VALUES(title),
            description = VALUES(description),
            primary_label = VALUES(primary_label),
            primary_href = VALUES(primary_href),
            secondary_label = VALUES(secondary_label),
            secondary_href = VALUES(secondary_href),
            product_image = VALUES(product_image),
            product_image_scale = VALUES(product_image_scale),
            product_image_pos_x = VALUES(product_image_pos_x),
            product_image_pos_y = VALUES(product_image_pos_y),
            background_image = VALUES(background_image),
            background_image_scale = VALUES(background_image_scale),
            background_image_pos_x = VALUES(background_image_pos_x),
            background_image_pos_y = VALUES(background_image_pos_y),
            updated_at = NOW()"
    );

    $stmt->execute([
        "eyebrow" => $hero["eyebrow"],
        "title" => $hero["title"],
        "description" => $hero["description"],
        "primary_label" => $hero["primary_label"],
        "primary_href" => $hero["primary_href"],
        "secondary_label" => $hero["secondary_label"],
        "secondary_href" => $hero["secondary_href"],
        "product_image" => $hero["product_image"],
        "product_image_scale" => $hero["product_image_scale"],
        "product_image_pos_x" => $hero["product_image_pos_x"],
        "product_image_pos_y" => $hero["product_image_pos_y"],
        "background_image" => $hero["background_image"],
        "background_image_scale" => $hero["background_image_scale"],
        "background_image_pos_x" => $hero["background_image_pos_x"],
        "background_image_pos_y" => $hero["background_image_pos_y"],
    ]);

    $hero = dmh_read_hero_config($conexion, $defaultHero);

    echo json_encode([
        "ok" => true,
        "hero" => $hero,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "error" => "Error al guardar la personalización del hero",
        "detalle" => $e->getMessage(),
    ]);
}
