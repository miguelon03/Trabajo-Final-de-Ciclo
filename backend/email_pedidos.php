<?php

/**
 * email_pedidos.php
 *
 * Genera y envía el correo formal de confirmación de pedido a un comprador
 * que ha comprado como INVITADO (sin cuenta).
 *
 * El correo incluye:
 *   - Número de pedido y artículos comprados.
 *   - Aviso de que dispone de 30 días para devolver.
 *   - Instrucción de registrarse en DRIP MODE con el mismo email para poder
 *     gestionar la devolución (al registrarse, el pedido aparece en "Mis pedidos").
 */

require_once __DIR__ . "/mailer.php";

/**
 * Formatea un importe como precio en euros (ej. "29,90 €").
 */
function dmh_formato_precio($valor): string
{
    return number_format((float)$valor, 2, ",", ".") . " €";
}

/**
 * Construye el HTML del correo de pedido para invitado.
 */
function dmh_build_email_pedido_invitado_html(array $pedido, array $items, string $urlRegistro): string
{
    $nombre = htmlspecialchars((string)($pedido["nombre_invitado"] ?? "cliente"), ENT_QUOTES, "UTF-8");
    $pedidoId = (int)($pedido["id"] ?? 0);
    $numeroPedido = "DMH-" . str_pad((string)$pedidoId, 6, "0", STR_PAD_LEFT);
    $direccion = htmlspecialchars((string)($pedido["direccion_envio"] ?? ""), ENT_QUOTES, "UTF-8");
    $total = dmh_formato_precio($pedido["importe_total"] ?? 0);

    $filas = "";
    foreach ($items as $item) {
        $nombreProd = htmlspecialchars((string)($item["nombre_producto"] ?? ""), ENT_QUOTES, "UTF-8");
        $talla = htmlspecialchars((string)($item["talla"] ?? "Única"), ENT_QUOTES, "UTF-8");
        $color = trim((string)($item["color"] ?? ""));
        $color = $color !== "" ? htmlspecialchars($color, ENT_QUOTES, "UTF-8") : "—";
        $cantidad = (int)($item["cantidad"] ?? 0);
        $precio = dmh_formato_precio($item["precio_unitario"] ?? 0);
        $subtotal = dmh_formato_precio($item["subtotal"] ?? 0);

        $filas .= "
            <tr>
                <td style=\"padding:10px 8px;border-bottom:1px solid #eee;font-size:14px;color:#111;\">
                    <strong>{$nombreProd}</strong><br>
                    <span style=\"color:#777;font-size:12px;\">Talla: {$talla} · Color: {$color}</span>
                </td>
                <td style=\"padding:10px 8px;border-bottom:1px solid #eee;font-size:14px;color:#111;text-align:center;\">{$cantidad}</td>
                <td style=\"padding:10px 8px;border-bottom:1px solid #eee;font-size:14px;color:#111;text-align:right;\">{$precio}</td>
                <td style=\"padding:10px 8px;border-bottom:1px solid #eee;font-size:14px;color:#111;text-align:right;\">{$subtotal}</td>
            </tr>";
    }

    return "<!DOCTYPE html>
<html lang=\"es\">
<head><meta charset=\"UTF-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\"></head>
<body style=\"margin:0;padding:0;background:#f4f4f5;font-family:Arial,Helvetica,sans-serif;\">
    <table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"background:#f4f4f5;padding:24px 0;\">
        <tr><td align=\"center\">
            <table role=\"presentation\" width=\"600\" cellpadding=\"0\" cellspacing=\"0\" style=\"max-width:600px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e5e5e5;\">

                <tr><td style=\"background:#111111;padding:28px 32px;text-align:center;\">
                    <span style=\"color:#ffffff;font-size:24px;font-weight:bold;letter-spacing:4px;\">DRIP MODE</span>
                </td></tr>

                <tr><td style=\"padding:32px;\">
                    <h1 style=\"margin:0 0 8px;font-size:20px;color:#111;\">¡Gracias por tu compra, {$nombre}!</h1>
                    <p style=\"margin:0 0 20px;font-size:15px;color:#555;line-height:1.5;\">
                        Hemos recibido y confirmado tu pago correctamente. A continuación tienes el resumen de tu pedido.
                    </p>

                    <table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"margin-bottom:24px;\">
                        <tr>
                            <td style=\"font-size:13px;color:#777;padding:4px 0;\">Número de pedido</td>
                            <td style=\"font-size:13px;color:#111;font-weight:bold;text-align:right;padding:4px 0;\">{$numeroPedido}</td>
                        </tr>
                        <tr>
                            <td style=\"font-size:13px;color:#777;padding:4px 0;\">Dirección de envío</td>
                            <td style=\"font-size:13px;color:#111;text-align:right;padding:4px 0;\">{$direccion}</td>
                        </tr>
                    </table>

                    <table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"border-collapse:collapse;\">
                        <tr style=\"background:#fafafa;\">
                            <th align=\"left\" style=\"padding:10px 8px;font-size:12px;color:#777;text-transform:uppercase;border-bottom:2px solid #eee;\">Artículo</th>
                            <th align=\"center\" style=\"padding:10px 8px;font-size:12px;color:#777;text-transform:uppercase;border-bottom:2px solid #eee;\">Uds.</th>
                            <th align=\"right\" style=\"padding:10px 8px;font-size:12px;color:#777;text-transform:uppercase;border-bottom:2px solid #eee;\">Precio</th>
                            <th align=\"right\" style=\"padding:10px 8px;font-size:12px;color:#777;text-transform:uppercase;border-bottom:2px solid #eee;\">Subtotal</th>
                        </tr>
                        {$filas}
                        <tr>
                            <td colspan=\"3\" style=\"padding:14px 8px;font-size:15px;color:#111;font-weight:bold;text-align:right;\">Total</td>
                            <td style=\"padding:14px 8px;font-size:15px;color:#111;font-weight:bold;text-align:right;\">{$total}</td>
                        </tr>
                    </table>

                    <div style=\"margin-top:28px;padding:20px;background:#f4f4f5;border-radius:10px;\">
                        <h2 style=\"margin:0 0 8px;font-size:16px;color:#111;\">Devoluciones — 30 días</h2>
                        <p style=\"margin:0 0 12px;font-size:14px;color:#555;line-height:1.6;\">
                            Dispones de un periodo de <strong>30 días naturales</strong> desde la recepción del pedido
                            para solicitar la devolución de cualquier artículo.
                        </p>
                        <p style=\"margin:0 0 16px;font-size:14px;color:#555;line-height:1.6;\">
                            Como has comprado <strong>sin cuenta</strong>, para poder gestionar una devolución debes
                            <strong>registrarte en DRIP MODE usando este mismo correo electrónico</strong>.
                            Al hacerlo, este pedido aparecerá automáticamente en tu sección <strong>«Mis pedidos»</strong>
                            y podrás tramitar la devolución desde ahí.
                        </p>
                        <a href=\"{$urlRegistro}\" style=\"display:inline-block;background:#111;color:#fff;text-decoration:none;font-size:14px;font-weight:bold;padding:12px 24px;border-radius:8px;\">
                            Registrarme en DRIP MODE
                        </a>
                    </div>

                    <p style=\"margin:28px 0 0;font-size:13px;color:#999;line-height:1.5;\">
                        Si tienes cualquier duda, responde a este correo y te ayudaremos.
                    </p>
                </td></tr>

                <tr><td style=\"background:#111111;padding:20px 32px;text-align:center;\">
                    <span style=\"color:#888;font-size:12px;\">© DRIP MODE · Este es un correo automático de confirmación de pedido.</span>
                </td></tr>

            </table>
        </td></tr>
    </table>
</body>
</html>";
}

/**
 * Envía el correo de confirmación a un pedido de invitado.
 *
 * Solo envía si el pedido NO tiene usuario asociado y tiene email de invitado.
 * Nunca lanza excepción: devuelve ["ok" => bool, "error" => string, "skipped" => bool].
 */
function dmh_enviar_email_pedido_invitado(PDO $conexion, int $pedidoId): array
{
    try {
        $stmt = $conexion->prepare(
            "SELECT id, usuario_id, nombre_invitado, email_invitado, importe_total, direccion_envio
             FROM pedidos
             WHERE id = :id
             LIMIT 1"
        );
        $stmt->execute(["id" => $pedidoId]);
        $pedido = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$pedido) {
            return ["ok" => false, "error" => "Pedido no encontrado", "skipped" => true];
        }

        $usuarioId = $pedido["usuario_id"] !== null ? (int)$pedido["usuario_id"] : 0;
        $email = trim((string)($pedido["email_invitado"] ?? ""));

        // Solo enviamos a compradores invitados con email válido.
        if ($usuarioId > 0 || $email === "") {
            return ["ok" => false, "error" => "El pedido no es de un invitado", "skipped" => true];
        }

        $stmtItems = $conexion->prepare(
            "SELECT nombre_producto, talla, color, cantidad, precio_unitario, subtotal
             FROM items_pedido
             WHERE pedido_id = :pid
             ORDER BY id ASC"
        );
        $stmtItems->execute(["pid" => $pedidoId]);
        $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // URL del frontend para el registro (configurable en .env).
        $env = dmh_load_env(__DIR__ . "/.env");
        $appUrl = rtrim((string)($env["APP_URL"] ?? "http://localhost:4321"), "/");
        $urlRegistro = $appUrl . "/registro";

        $html = dmh_build_email_pedido_invitado_html($pedido, $items, $urlRegistro);
        $numeroPedido = "DMH-" . str_pad((string)$pedidoId, 6, "0", STR_PAD_LEFT);
        $asunto = "Confirmación de tu pedido {$numeroPedido} · DRIP MODE";

        return dmh_send_email(
            $email,
            (string)($pedido["nombre_invitado"] ?? ""),
            $asunto,
            $html
        ) + ["skipped" => false];
    } catch (Throwable $e) {
        return ["ok" => false, "error" => $e->getMessage(), "skipped" => false];
    }
}
