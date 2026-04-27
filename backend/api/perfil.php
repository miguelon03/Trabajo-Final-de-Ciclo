<?php

// CORS para permitir peticiones desde el frontend en desarrollo.
header("Access-Control-Allow-Origin: http://localhost:4321");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=utf-8");

// Respondemos a la preflight request.
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit();
}

session_start();
require_once __DIR__ . "/../conexion.php";

// Todas las operaciones de perfil requieren sesión iniciada.
if (!isset($_SESSION["usuario_id"])) {
    http_response_code(401);
    echo json_encode([
        "ok" => false,
        "error" => "No autorizado. Inicia sesión para continuar"
    ]);
    exit;
}

$usuarioId = (int)$_SESSION["usuario_id"];

// GET => devolver perfil del usuario autenticado.
if ($_SERVER["REQUEST_METHOD"] === "GET") {
    try {
        $sql = "SELECT id, nombre, email, rol, estado, telefono, direccion, ciudad, codigo_postal, creado_en, actualizado_en
                FROM usuarios
                WHERE id = :id
                LIMIT 1";

        $stmt = $conexion->prepare($sql);
        $stmt->execute(["id" => $usuarioId]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$usuario) {
            http_response_code(404);
            echo json_encode([
                "ok" => false,
                "error" => "Usuario no encontrado"
            ]);
            exit;
        }

        echo json_encode([
            "ok" => true,
            "usuario" => $usuario
        ]);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            "ok" => false,
            "error" => "Error al obtener el perfil",
            "detalle" => $e->getMessage()
        ]);
        exit;
    }
}

// POST => actualizar datos editables del perfil.
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        // Aceptamos tanto JSON como form-data/x-www-form-urlencoded.
        $contentType = $_SERVER["CONTENT_TYPE"] ?? "";
        $body = [];

        if (stripos($contentType, "application/json") !== false) {
            $raw = file_get_contents("php://input");
            $body = json_decode($raw, true) ?? [];
        } else {
            $body = $_POST;
        }

        $nombre = trim((string)($body["nombre"] ?? ""));
        $telefono = trim((string)($body["telefono"] ?? ""));
        $direccion = trim((string)($body["direccion"] ?? ""));
        $ciudad = trim((string)($body["ciudad"] ?? ""));
        $codigoPostal = trim((string)($body["codigo_postal"] ?? $body["codigoPostal"] ?? ""));

        // Validaciones básicas para proteger consistencia de datos.
        if ($nombre === "") {
            echo json_encode([
                "ok" => false,
                "error" => "El nombre es obligatorio"
            ]);
            exit;
        }

        if (mb_strlen($nombre) > 100) {
            echo json_encode([
                "ok" => false,
                "error" => "El nombre no puede superar 100 caracteres"
            ]);
            exit;
        }

        if (mb_strlen($telefono) > 30 || mb_strlen($direccion) > 255 || mb_strlen($ciudad) > 120 || mb_strlen($codigoPostal) > 20) {
            echo json_encode([
                "ok" => false,
                "error" => "Alguno de los campos supera la longitud permitida"
            ]);
            exit;
        }

        $sql = "UPDATE usuarios
                SET nombre = :nombre,
                    telefono = :telefono,
                    direccion = :direccion,
                    ciudad = :ciudad,
                    codigo_postal = :codigo_postal
                WHERE id = :id
                LIMIT 1";

        $stmt = $conexion->prepare($sql);
        $stmt->execute([
            "nombre" => $nombre,
            "telefono" => $telefono !== "" ? $telefono : null,
            "direccion" => $direccion !== "" ? $direccion : null,
            "ciudad" => $ciudad !== "" ? $ciudad : null,
            "codigo_postal" => $codigoPostal !== "" ? $codigoPostal : null,
            "id" => $usuarioId,
        ]);

        // Mantenemos la sesión sincronizada con el nuevo nombre.
        $_SESSION["usuario_nombre"] = $nombre;

        $stmtPerfil = $conexion->prepare(
            "SELECT id, nombre, email, rol, estado, telefono, direccion, ciudad, codigo_postal, creado_en, actualizado_en
             FROM usuarios
             WHERE id = :id
             LIMIT 1"
        );
        $stmtPerfil->execute(["id" => $usuarioId]);
        $usuarioActualizado = $stmtPerfil->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            "ok" => true,
            "mensaje" => "Perfil actualizado correctamente",
            "usuario" => $usuarioActualizado
        ]);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            "ok" => false,
            "error" => "Error al actualizar el perfil",
            "detalle" => $e->getMessage()
        ]);
        exit;
    }
}

// Método no permitido para este endpoint.
http_response_code(405);
echo json_encode([
    "ok" => false,
    "error" => "Método no permitido"
]);
