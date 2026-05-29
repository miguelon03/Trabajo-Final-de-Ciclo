<?php

/**
 * mailer.php
 *
 * Cliente SMTP minimalista (sin dependencias externas) para enviar
 * correos desde XAMPP a través de un servidor SMTP con STARTTLS + AUTH LOGIN
 * (por ejemplo Gmail: smtp.gmail.com:587).
 *
 * La configuración se lee del archivo .env del backend:
 *   SMTP_HOST=smtp.gmail.com
 *   SMTP_PORT=587
 *   SMTP_USER=tucorreo@gmail.com
 *   SMTP_PASS=contraseña_de_aplicacion
 *   SMTP_FROM=tucorreo@gmail.com
 *   SMTP_FROM_NAME=DRIP MODE
 */

/**
 * Lee un archivo .env sencillo (CLAVE=valor por línea) y lo devuelve como array.
 */
function dmh_load_env(string $path): array
{
    $env = [];

    if (!is_file($path)) {
        return $env;
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);

        if ($line === "" || $line[0] === "#") {
            continue;
        }

        $pos = strpos($line, "=");
        if ($pos === false) {
            continue;
        }

        $clave = trim(substr($line, 0, $pos));
        $valor = trim(substr($line, $pos + 1));

        // Quitamos comillas envolventes si las hubiera.
        if (strlen($valor) >= 2) {
            $primero = $valor[0];
            $ultimo = $valor[strlen($valor) - 1];
            if (($primero === '"' && $ultimo === '"') || ($primero === "'" && $ultimo === "'")) {
                $valor = substr($valor, 1, -1);
            }
        }

        $env[$clave] = $valor;
    }

    return $env;
}

/**
 * Lee una línea de respuesta del servidor SMTP (soporta respuestas multilínea)
 * y comprueba que empiece por el código esperado (ej. "250").
 */
function dmh_smtp_read($conn, string $codigoEsperado, string &$error): bool
{
    $respuesta = "";

    while (($linea = fgets($conn, 600)) !== false) {
        $respuesta .= $linea;

        // En una respuesta multilínea el 4º carácter es '-'; en la última es ' '.
        if (strlen($linea) < 4 || $linea[3] === " ") {
            break;
        }
    }

    if (strncmp($respuesta, $codigoEsperado, strlen($codigoEsperado)) !== 0) {
        $error = trim($respuesta);
        return false;
    }

    return true;
}

/**
 * Envía un comando SMTP terminado en CRLF.
 */
function dmh_smtp_write($conn, string $comando): void
{
    fwrite($conn, $comando . "\r\n");
}

/**
 * Codifica una cabecera con posibles caracteres no ASCII (UTF-8) según RFC 1342.
 */
function dmh_mime_header(string $texto): string
{
    if (preg_match('/^[\x20-\x7E]*$/', $texto)) {
        return $texto;
    }

    return "=?UTF-8?B?" . base64_encode($texto) . "?=";
}

/**
 * Envía un correo HTML a través de SMTP.
 *
 * Devuelve un array: ["ok" => bool, "error" => string]
 */
function dmh_send_email(
    string $destinatario,
    string $nombreDestinatario,
    string $asunto,
    string $cuerpoHtml,
    ?string $cuerpoTexto = null,
    ?string $rutaEnv = null
): array {
    $rutaEnv = $rutaEnv ?? (__DIR__ . "/.env");
    $env = dmh_load_env($rutaEnv);

    $host = $env["SMTP_HOST"] ?? "";
    $port = (int)($env["SMTP_PORT"] ?? 587);
    $user = $env["SMTP_USER"] ?? "";
    $pass = $env["SMTP_PASS"] ?? "";
    $from = $env["SMTP_FROM"] ?? $user;
    $fromName = $env["SMTP_FROM_NAME"] ?? "DRIP MODE";

    if ($host === "" || $user === "" || $pass === "") {
        return [
            "ok" => false,
            "error" => "Configuración SMTP incompleta en .env (SMTP_HOST/SMTP_USER/SMTP_PASS)",
        ];
    }

    if (!filter_var($destinatario, FILTER_VALIDATE_EMAIL)) {
        return ["ok" => false, "error" => "Email de destinatario inválido"];
    }

    $cuerpoTexto = $cuerpoTexto ?? trim(strip_tags($cuerpoHtml));
    $error = "";

    // Conexión TCP al servidor SMTP.
    $conn = @stream_socket_client(
        "tcp://{$host}:{$port}",
        $errno,
        $errstr,
        15,
        STREAM_CLIENT_CONNECT
    );

    if (!$conn) {
        return ["ok" => false, "error" => "No se pudo conectar al SMTP: {$errstr} ({$errno})"];
    }

    stream_set_timeout($conn, 15);

    try {
        if (!dmh_smtp_read($conn, "220", $error)) {
            throw new RuntimeException("Saludo SMTP inesperado: {$error}");
        }

        $ehloHost = $env["SMTP_EHLO"] ?? "localhost";

        dmh_smtp_write($conn, "EHLO {$ehloHost}");
        if (!dmh_smtp_read($conn, "250", $error)) {
            throw new RuntimeException("EHLO rechazado: {$error}");
        }

        // STARTTLS (obligatorio en Gmail por el puerto 587).
        dmh_smtp_write($conn, "STARTTLS");
        if (!dmh_smtp_read($conn, "220", $error)) {
            throw new RuntimeException("STARTTLS rechazado: {$error}");
        }

        $cryptoOk = stream_socket_enable_crypto(
            $conn,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
                | STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT
                | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
        );

        if ($cryptoOk !== true) {
            throw new RuntimeException("No se pudo activar el cifrado TLS");
        }

        // Tras STARTTLS hay que volver a saludar.
        dmh_smtp_write($conn, "EHLO {$ehloHost}");
        if (!dmh_smtp_read($conn, "250", $error)) {
            throw new RuntimeException("EHLO (TLS) rechazado: {$error}");
        }

        // Autenticación AUTH LOGIN.
        dmh_smtp_write($conn, "AUTH LOGIN");
        if (!dmh_smtp_read($conn, "334", $error)) {
            throw new RuntimeException("AUTH LOGIN no soportado: {$error}");
        }

        dmh_smtp_write($conn, base64_encode($user));
        if (!dmh_smtp_read($conn, "334", $error)) {
            throw new RuntimeException("Usuario SMTP rechazado: {$error}");
        }

        dmh_smtp_write($conn, base64_encode($pass));
        if (!dmh_smtp_read($conn, "235", $error)) {
            throw new RuntimeException("Autenticación SMTP fallida (revisa la contraseña de aplicación): {$error}");
        }

        // Sobre del mensaje.
        dmh_smtp_write($conn, "MAIL FROM:<{$from}>");
        if (!dmh_smtp_read($conn, "250", $error)) {
            throw new RuntimeException("MAIL FROM rechazado: {$error}");
        }

        dmh_smtp_write($conn, "RCPT TO:<{$destinatario}>");
        if (!dmh_smtp_read($conn, "25", $error)) {
            throw new RuntimeException("RCPT TO rechazado: {$error}");
        }

        dmh_smtp_write($conn, "DATA");
        if (!dmh_smtp_read($conn, "354", $error)) {
            throw new RuntimeException("DATA rechazado: {$error}");
        }

        // Construcción de cabeceras y cuerpo multipart (texto + HTML).
        $limite = "dmh-" . bin2hex(random_bytes(12));
        $fecha = date("r");

        $fromHeader = dmh_mime_header($fromName) . " <{$from}>";
        $toHeader = $nombreDestinatario !== ""
            ? dmh_mime_header($nombreDestinatario) . " <{$destinatario}>"
            : "<{$destinatario}>";

        $cabeceras = [];
        $cabeceras[] = "Date: {$fecha}";
        $cabeceras[] = "From: {$fromHeader}";
        $cabeceras[] = "To: {$toHeader}";
        $cabeceras[] = "Subject: " . dmh_mime_header($asunto);
        $cabeceras[] = "MIME-Version: 1.0";
        $cabeceras[] = "Content-Type: multipart/alternative; boundary=\"{$limite}\"";

        $mensaje = implode("\r\n", $cabeceras) . "\r\n\r\n";
        $mensaje .= "--{$limite}\r\n";
        $mensaje .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $mensaje .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $mensaje .= chunk_split(base64_encode($cuerpoTexto)) . "\r\n";
        $mensaje .= "--{$limite}\r\n";
        $mensaje .= "Content-Type: text/html; charset=UTF-8\r\n";
        $mensaje .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $mensaje .= chunk_split(base64_encode($cuerpoHtml)) . "\r\n";
        $mensaje .= "--{$limite}--\r\n";

        // Dot-stuffing: una línea que empiece por '.' debe duplicarla.
        $mensaje = preg_replace('/^\./m', '..', $mensaje);

        fwrite($conn, $mensaje . "\r\n.\r\n");
        if (!dmh_smtp_read($conn, "250", $error)) {
            throw new RuntimeException("El servidor rechazó el mensaje: {$error}");
        }

        dmh_smtp_write($conn, "QUIT");
        fclose($conn);

        return ["ok" => true, "error" => ""];
    } catch (Throwable $e) {
        if (is_resource($conn)) {
            @fclose($conn);
        }
        return ["ok" => false, "error" => $e->getMessage()];
    }
}
