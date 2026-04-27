<?php

// CORS para desarrollo en Astro (localhost:4321).
header("Access-Control-Allow-Origin: http://localhost:4321");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit();
}

session_start();
require_once __DIR__ . "/../conexion.php";

if (!isset($_SESSION["usuario_id"])) {
    http_response_code(401);
    echo json_encode([
        "ok" => false,
        "error" => "Debes iniciar sesión"
    ]);
    exit;
}

$usuarioId = (int)$_SESSION["usuario_id"];

// GET: devolver lista de slugs favoritos del usuario.
if ($_SERVER["REQUEST_METHOD"] === "GET") {
    try {
        $stmt = $conexion->prepare(
            "SELECT slug
             FROM usuarios_favoritos
             WHERE usuario_id = :usuario_id
             ORDER BY creado_en DESC"
        );
        $stmt->execute(["usuario_id" => $usuarioId]);
        $slugs = $stmt->fetchAll(PDO::FETCH_COLUMN);

        echo json_encode([
            "ok" => true,
            "favoritos" => $slugs,
        ]);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            "ok" => false,
            "error" => "Error al obtener favoritos",
            "detalle" => $e->getMessage(),
        ]);
        exit;
    }
}

// POST/DELETE: añadir o quitar favorito usando slug de producto.
if ($_SERVER["REQUEST_METHOD"] === "POST" || $_SERVER["REQUEST_METHOD"] === "DELETE") {
    try {
        $contentType = $_SERVER["CONTENT_TYPE"] ?? "";
        $body = [];

        if (stripos($contentType, "application/json") !== false) {
            $raw = file_get_contents("php://input");
            $body = json_decode($raw, true) ?? [];
        } else {
            $body = $_POST;
        }

        $slug = trim((string)($body["slug"] ?? ""));
        if ($slug === "") {
            echo json_encode([
                "ok" => false,
                "error" => "El slug del producto es obligatorio",
            ]);
            exit;
        }

        // Validación básica del slug: solo letras, números y guiones.
        if (!preg_match('/^[a-z0-9\-]+$/', $slug)) {
            http_response_code(400);
            echo json_encode([
                "ok" => false,
                "error" => "Slug inválido",
            ]);
            exit;
        }

        if ($_SERVER["REQUEST_METHOD"] === "POST") {
            $stmtInsert = $conexion->prepare(
                "INSERT INTO usuarios_favoritos (usuario_id, slug)
                 VALUES (:usuario_id, :slug)
                 ON DUPLICATE KEY UPDATE slug = slug"
            );
            $stmtInsert->execute([
                "usuario_id" => $usuarioId,
                "slug" => $slug,
            ]);

            echo json_encode([
                "ok" => true,
                "mensaje" => "Producto añadido a favoritos",
            ]);
            exit;
        }

        $stmtDelete = $conexion->prepare(
            "DELETE FROM usuarios_favoritos
             WHERE usuario_id = :usuario_id
               AND slug = :slug"
        );
        $stmtDelete->execute([
            "usuario_id" => $usuarioId,
            "slug" => $slug,
        ]);

        echo json_encode([
            "ok" => true,
            "mensaje" => "Producto eliminado de favoritos",
        ]);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            "ok" => false,
            "error" => "Error al actualizar favoritos",
            "detalle" => $e->getMessage(),
        ]);
        exit;
    }
}

http_response_code(405);
echo json_encode([
    "ok" => false,
    "error" => "Método no permitido",
]);
