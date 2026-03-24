<?php
$host = "localhost";
$usuario = "root";
$contraseña = "";

/*
 * OJO IMPORTANTE:
 * No ponemos dbname=dripmode porque aún NO existe.
 * El instalador la creará.
 */
try {
    $conexion = new PDO(
        "mysql:host=$host;charset=utf8",
        $usuario,
        $contraseña
    );
    $conexion->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

} catch (PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}
?>
