<?php
$dmhAllowedOrigins = ["http://localhost:4321", "https://dripmode.com"];
$dmhOrigin = $_SERVER["HTTP_ORIGIN"] ?? "";
if (in_array($dmhOrigin, $dmhAllowedOrigins, true)) {
    header("Access-Control-Allow-Origin: " . $dmhOrigin);
}
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit();
}

require_once __DIR__ . "/../conexion.php";

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

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    http_response_code(405);
    echo json_encode([
        "ok" => false,
        "error" => "Método no permitido",
    ]);
    exit;
}

try {
    $hero = dmh_read_hero_config($conexion, $defaultHero);

    echo json_encode([
        "ok" => true,
        "hero" => $hero,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "error" => "Error al cargar configuración del hero",
        "detalle" => $e->getMessage(),
    ]);
}
