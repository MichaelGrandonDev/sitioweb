<?php

declare(strict_types=1);

/**
 * Envía un correo HTML + texto plano vía mail() de HostGator.
 */
function send_mail(string $to, string $subject, string $htmlBody, string $textBody = ''): bool
{
    global $config;
    $to = trim($to);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $fromEmail = (string) ($config['mail_from'] ?? 'hola@fluxusterapia.com');
    $fromName = (string) ($config['mail_from_name'] ?? 'AcademiaFluxus');
    $replyTo = (string) ($config['mail_reply_to'] ?? $fromEmail);
    if ($textBody === '') {
        $textBody = trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $htmlBody)), ENT_QUOTES, 'UTF-8'));
    }

    $boundary = 'fluxus_' . bin2hex(random_bytes(8));
    $headers = [
        'MIME-Version: 1.0',
        'From: ' . sprintf('%s <%s>', encode_mail_header($fromName), $fromEmail),
        'Reply-To: ' . $replyTo,
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        'X-Mailer: AcademiaFluxus',
    ];

    $body = "--{$boundary}\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $body .= $textBody . "\r\n\r\n";
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $body .= $htmlBody . "\r\n\r\n";
    $body .= "--{$boundary}--\r\n";

    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    return @mail($to, $encodedSubject, $body, implode("\r\n", $headers));
}

function encode_mail_header(string $value): string
{
    if (preg_match('/[^\x20-\x7E]/', $value)) {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
    return $value;
}

function welcome_email_html(string $name, string $username, string $password, string $loginUrl): string
{
    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $safeUser = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
    $safePass = htmlspecialchars($password, ENT_QUOTES, 'UTF-8');
    $safeUrl = htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8');

    return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><title>Bienvenida AcademiaFluxus</title></head>
<body style="margin:0;padding:0;background:#eef3ef;font-family:Georgia,'Times New Roman',serif;color:#12201c;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#eef3ef;padding:28px 12px;">
    <tr>
      <td align="center">
        <table role="presentation" width="560" cellspacing="0" cellpadding="0" style="max-width:560px;background:#f8fbf8;border:1px solid rgba(15,61,54,.12);border-radius:12px;overflow:hidden;">
          <tr>
            <td style="background:#0f3d36;color:#fff;padding:22px 28px;font-family:Georgia,serif;font-size:26px;">
              Fluxus<span style="opacity:.75;font-style:italic;">Academia</span>
            </td>
          </tr>
          <tr>
            <td style="padding:28px;">
              <p style="margin:0 0 12px;font-size:15px;letter-spacing:.12em;text-transform:uppercase;color:#2f8f6b;font-family:Arial,sans-serif;font-weight:700;">Bienvenida</p>
              <h1 style="margin:0 0 14px;font-size:28px;line-height:1.25;">Hola, {$safeName}</h1>
              <p style="margin:0 0 14px;font-size:16px;line-height:1.6;font-family:Arial,sans-serif;color:#4f635a;">
                Qué alegría tenerte en AcademiaFluxus. En este tiempo te vamos a acompañar
                con presencia, calma y una propuesta clara para que avances a tu ritmo.
              </p>
              <p style="margin:0 0 18px;font-size:16px;line-height:1.6;font-family:Arial,sans-serif;color:#4f635a;">
                Ya tenés tu acceso listo. Guardá estos datos en un lugar seguro:
              </p>
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#eef3ef;border-radius:8px;margin:0 0 20px;">
                <tr>
                  <td style="padding:16px 18px;font-family:Arial,sans-serif;font-size:15px;line-height:1.7;color:#12201c;">
                    <strong>Usuario:</strong> {$safeUser}<br>
                    <strong>Contraseña:</strong> {$safePass}<br>
                    <strong>Ingreso:</strong> <a href="{$safeUrl}" style="color:#0f3d36;">{$safeUrl}</a>
                  </td>
                </tr>
              </table>
              <p style="margin:0 0 22px;font-family:Arial,sans-serif;">
                <a href="{$safeUrl}" style="display:inline-block;background:#2f8f6b;color:#fff;text-decoration:none;padding:12px 18px;border-radius:6px;font-weight:700;">
                  Entrar a mi academia
                </a>
              </p>
              <p style="margin:0;font-size:15px;line-height:1.6;font-family:Arial,sans-serif;color:#4f635a;">
                Si tenés alguna duda, respondé este correo o escribinos por WhatsApp.
                Estamos para acompañarte.<br><br>
                Con cariño,<br>
                <strong>Michael · FluxusTerapia</strong>
              </p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
}

function send_welcome_email(string $email, string $name, string $username, string $password): bool
{
    global $config;
    $loginUrl = rtrim((string) ($config['site_url'] ?? 'https://fluxusterapia.com'), '/') . '/academia/login.php';
    $subject = 'Bienvenida a AcademiaFluxus · tus datos de acceso';
    $html = welcome_email_html($name, $username, $password, $loginUrl);
    $text = "Hola, {$name}\n\n"
        . "Qué alegría tenerte en AcademiaFluxus. En este tiempo te vamos a acompañar "
        . "con presencia, calma y una propuesta clara para que avances a tu ritmo.\n\n"
        . "Tus datos de acceso:\n"
        . "Usuario: {$username}\n"
        . "Contraseña: {$password}\n"
        . "Ingreso: {$loginUrl}\n\n"
        . "Con cariño,\nMichael · FluxusTerapia\n";
    return send_mail($email, $subject, $html, $text);
}

function admin_notify_email(): string
{
    global $config;
    $cfg = function_exists('payment_cfg') ? payment_cfg() : [];
    $custom = trim((string) ($cfg['admin_notify_email'] ?? ''));
    if ($custom !== '' && filter_var($custom, FILTER_VALIDATE_EMAIL)) {
        return $custom;
    }
    return (string) ($config['mail_from'] ?? 'hola@fluxusterapia.com');
}

function send_admin_inscription_notice(array $inscription): bool
{
    global $config;
    $to = admin_notify_email();
    $name = (string) ($inscription['name'] ?? '');
    $email = (string) ($inscription['email'] ?? '');
    $amount = (int) ($inscription['amount'] ?? 0);
    $course = (string) ($inscription['course_title'] ?? 'AcademiaFluxus');
    $id = (int) ($inscription['id'] ?? 0);
    $adminUrl = rtrim((string) ($config['site_url'] ?? 'https://fluxusterapia.com'), '/') . '/academia/admin/inscriptions.php';
    $amountLabel = $amount > 0 ? ('$' . number_format($amount, 0, ',', '.') . ' ARS') : 'Inscripción gratuita';
    $subject = 'Nueva solicitud de cupo · AcademiaFluxus #' . $id;
    $html = '<p>Nueva inscripción pendiente de aprobación de cupo.</p>'
        . '<p><strong>Nombre:</strong> ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '<br>'
        . '<strong>Email:</strong> ' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '<br>'
        . '<strong>Curso/área:</strong> ' . htmlspecialchars($course, ENT_QUOTES, 'UTF-8') . '<br>'
        . '<strong>Inscripción:</strong> ' . htmlspecialchars($amountLabel, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p><a href="' . htmlspecialchars($adminUrl, ENT_QUOTES, 'UTF-8') . '">Revisar y aprobar cupo</a></p>';
    $text = "Nueva inscripción #{$id}\n{$name} · {$email}\n{$course}\n{$amountLabel}\n{$adminUrl}\n";
    return send_mail($to, $subject, $html, $text);
}

function send_course_access_email(
    string $email,
    string $name,
    string $username,
    ?string $password,
    array $courseTitles
): bool {
    global $config;
    $loginUrl = rtrim((string) ($config['site_url'] ?? 'https://fluxusterapia.com'), '/') . '/academia/login.php';
    $list = implode(', ', $courseTitles);
    $subject = 'Acceso a tu curso grabado · FluxusTerapia';
    $passBlock = $password !== null && $password !== ''
        ? '<strong>Usuario:</strong> ' . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . '<br><strong>Contraseña:</strong> ' . htmlspecialchars($password, ENT_QUOTES, 'UTF-8') . '<br>'
        : '<strong>Usuario:</strong> ' . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . '<br>Usá tu contraseña habitual.<br>';
    $html = '<p>Hola, ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p>Ya tenés acceso a: <strong>' . htmlspecialchars($list, ENT_QUOTES, 'UTF-8') . '</strong>.</p>'
        . '<p>' . $passBlock
        . '<strong>Ingreso:</strong> <a href="' . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . '</a></p>'
        . '<p>Entrá a AcademiaFluxus → Mis cursos para ver el contenido.</p>';
    $text = "Hola, {$name}\n\nAcceso a: {$list}\nUsuario: {$username}\n"
        . ($password ? "Contraseña: {$password}\n" : "Usá tu contraseña habitual.\n")
        . "Ingreso: {$loginUrl}\n";
    return send_mail($email, $subject, $html, $text);
}
