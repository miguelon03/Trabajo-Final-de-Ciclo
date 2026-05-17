<?php

//definimos las variables del servidor, el usuario, la contraseña y el nombre de la base de datos
$host = "localhost";
$usuario = "root";
$contrasena = "";
$basedatos = "dripmode";

function dmh_open_database_connection(
    string $host,
    string $basedatos,
    string $usuario,
    string $contrasena
): PDO {
    $conexion = new PDO(
        "mysql:host=$host;dbname=$basedatos;charset=utf8mb4",
        $usuario,
        $contrasena
    );

    $conexion->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conexion->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    return $conexion;
}

function dmh_should_bootstrap_database(PDOException $e): bool {
    $driverCode = (int)($e->errorInfo[1] ?? 0);
    $message = strtolower($e->getMessage());

    if ($driverCode === 1049) {
        return true;
    }

    return str_contains($message, "unknown database")
        || str_contains($message, "base de datos");
}

function dmh_has_catalog_schema(PDO $conexion): bool {
    try {
        $stmt = $conexion->query("SHOW TABLES LIKE 'productos'");
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function dmh_bootstrap_database_if_needed(
    string $host,
    string $basedatos,
    string $usuario,
    string $contrasena
): void {
    $serverConnection = new PDO(
        "mysql:host=$host;charset=utf8mb4",
        $usuario,
        $contrasena
    );

    $serverConnection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $serverConnection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $serverConnection->exec("
        CREATE DATABASE IF NOT EXISTS `$basedatos`
        CHARACTER SET utf8mb4
        COLLATE utf8mb4_unicode_ci
    ");

    $schemaReady = false;

    try {
        $dbConnection = dmh_open_database_connection($host, $basedatos, $usuario, $contrasena);
        $schemaReady = dmh_has_catalog_schema($dbConnection);
    } catch (Throwable $e) {
        $schemaReady = false;
    }

    if ($schemaReady) {
        return;
    }

    require_once __DIR__ . "/instalador.php";

    if (!function_exists("dmh_run_instalador")) {
        throw new RuntimeException("No se pudo cargar el instalador de la base de datos");
    }

    $resultado = dmh_run_instalador();

    if (!($resultado["ok"] ?? false)) {
        throw new RuntimeException(
            (string)($resultado["detalle"] ?? $resultado["error"] ?? "No se pudo preparar la base de datos")
        );
    }
}

try {
    $conexion = dmh_open_database_connection($host, $basedatos, $usuario, $contrasena);

    if (!dmh_has_catalog_schema($conexion)) {
        dmh_bootstrap_database_if_needed($host, $basedatos, $usuario, $contrasena);
        $conexion = dmh_open_database_connection($host, $basedatos, $usuario, $contrasena);
    }
} catch (PDOException $e) {
    if (dmh_should_bootstrap_database($e)) {
        try {
            dmh_bootstrap_database_if_needed($host, $basedatos, $usuario, $contrasena);
            $conexion = dmh_open_database_connection($host, $basedatos, $usuario, $contrasena);
            return;
        } catch (Throwable $bootstrapError) {
            $e = $bootstrapError instanceof PDOException
                ? $bootstrapError
                : new PDOException($bootstrapError->getMessage(), (int)$bootstrapError->getCode());
        }
    }

    //si falla la conexion devolvemos la respuesta en JSON
    header("Content-Type: application/json; charset=utf-8");
    http_response_code(500);//error del servidor
    /**
     * JSON con:
     * ok: false indica fallo
     * error: mensage general
     * detalle: mensaje real del error
     */
    echo json_encode([
        "ok" => false,
        "error" => "Error de conexión a la base de datos",
        "detalle" => $e->getMessage()
    ]);
    exit;
}