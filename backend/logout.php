<?php

//cabeceras CORS para permitir peticiones desde el frontend de Astro en localhost
header("Access-Control-Allow-Origin: http://localhost:4321");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");


// Si el navegador hace una petición previa OPTIONS, respondemos OK y terminamos
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit();
}

// Iniciamos la sesión para poder guardar los datos del usuario una vez haga login
session_start();
header("Content-Type: application/json; charset=utf-8");

//cerramps sesion u devolvemos un json con sesion cerrada correctamente
session_unset();
session_destroy();

echo json_encode([
    "ok" => true,
    "mensaje" => "Sesión cerrada correctamente"
]);