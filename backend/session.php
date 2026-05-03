<?php

// Permitimos peticiones desde el frontend Astro
header("Access-Control-Allow-Origin: http://localhost:4321");

// Permitimos uso de cookies/sesión
header("Access-Control-Allow-Credentials: true");

// Permitimos peticiones GET y OPTIONS
header("Access-Control-Allow-Methods: GET, OPTIONS");

// Permitimos la cabecera Content-Type
header("Access-Control-Allow-Headers: Content-Type");

// Respondemos a la preflight request OPTIONS
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit();
}

// Iniciamos la sesión para poder leer los datos guardados del usuario
session_start();

// Indicamos que la respuesta será JSON
header("Content-Type: application/json; charset=utf-8");

// Devolvemos:
// - si hay sesión iniciada
// - y, si la hay, los datos básicos del usuario
echo json_encode([
    "ok" => true,
    "logueado" => isset($_SESSION["usuario_id"]),
    "autenticado" => isset($_SESSION["usuario_id"]),
    "usuario" => isset($_SESSION["usuario_id"]) ? [
        "id" => $_SESSION["usuario_id"],
        "nombre" => $_SESSION["usuario_nombre"] ?? "",
        "email" => $_SESSION["usuario_email"] ?? "",
        "rol" => $_SESSION["usuario_rol"] ?? "cliente"
    ] : null
]);