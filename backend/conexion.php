<?php

//definimos las variables del servidor, el usuario, la contraseña y el nombre de la base de datos
$host = "localhost";
$usuario = "root";
$contrasena = "";
$basedatos = "dripmode";

try {
    //creamos una nueva conexion PDO que es una forma de conectar PHP cin una base de datos usando la clase PDO: PHP Data Objects
    /**
     * -host: servidor MySQL
     * -dbname: base de datos 
     * -charset=utf8mb4: codificacion para soportar tildes, emojis, etc
     */
    $conexion = new PDO(
        "mysql:host=$host;dbname=$basedatos;charset=utf8mb4",
        $usuario,
        $contrasena
    );
    //excepciones cuando ocurre un error SQL
    $conexion->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    //los resultados de las consultas se devuelven por defecto como array asociativo ejemplo $fila["nombre]
    $conexion->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
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