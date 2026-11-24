<?php
/**
 * Bella Vista Oasis Spa - email sending.
 *
 * Used by:
 *   save_booking.php      -> "booking received" email right after the customer confirms
 *   admin_dashboard.php   -> status emails (confirmed / cancelled / completed)
 *   book.php, track.php   -> bv_spa_info() (address, hours, phone shown to customers)
 *
 * Sections
 *   1. Settings (edit these)
 *   2. Small helpers
 *   3. SMTP sender (no extra libraries needed)
 *   4. Email templates
 */

/* =====================================================================
   1. Settings
   ===================================================================== */

/*
 * SMTP account used to send emails. Example with Gmail:
 *   - turn on 2-Step Verification for the Gmail account
 *   - Google Account > Security > App passwords > create one
 *   - paste the 16-letter app password below (NOT your normal password)
 * While 'username' or 'password' is empty, no email is sent; a copy of each email
 * is saved in the "outbox" folder so you can open it in a browser instead.
 */
/*
 * ############################################################################
 *   ANG MGA KAILANGAN MONG PALITAN AY NAKA-MARKA NG:   <<<<<< PALITAN DITO
 *   (Wag galawin ang iba.)
 * ############################################################################
 */
const BV_MAIL = [
    'host'       => 'smtp.gmail.com',     // Gmail: wag palitan. (Outlook: smtp-mail.outlook.com)
    'port'       => 587,                  // wag palitan
    'encryption' => 'tls',                // wag palitan

    // <<<<<< PALITAN DITO #1: ang Gmail na magsesend ng emails
    'username'   => 'bellavistaoasisspa@gmail.com',

    // <<<<<< PALITAN DITO #2: ang BAGONG 16-letter app password (galing sa myaccount.google.com/apppasswords)
    //        Okay lang kahit may spaces. Wag ang normal na password ng Gmail.
    'password'   => 'bvkh ekev uvzj figi',

    // <<<<<< PALITAN DITO #3: dapat PAREHO ng username sa taas
    'from_email' => 'bellavistaoasisspa@gmail.com',

    'from_name'  => 'Bella Vista Oasis Spa',   // pangalan na makikita ng customer, pwedeng palitan
    'verify_ssl' => false,               // gawing false LANG kung may "certificate" error
];

/** Spa details shown to customers (booking page, tracking page and emails). */
function bv_spa_info(): array
{
    return [
        // <<<<<< PALITAN DITO #4: totoong address ng spa
        'address' => 'Bella Vista Oasis Spa front desk (add your street address here)',
        'hours'   => 'Tuesday to Sunday, 9:00 AM to 5:30 PM',
        // <<<<<< PALITAN DITO #5: totoong contact number ng spa
        'phone'   => '09765142518',
    ];
}


/* =====================================================================
   2. Small helpers
   ===================================================================== */

function bv_mail_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function bv_mail_money($amount): string
{
    return '₱' . number_format((float)$amount, 2);
}

/** Removes line breaks so a value can never inject extra email headers. */
function bv_mail_single_line(string $value): string
{
    return trim(preg_replace('/[\r\n]+/', ' ', $value));
}

/** Address of this site folder, e.g. http://localhost/bella_vista_oasis_spa */
function bv_base_url(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $folder = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    return $scheme . '://' . $host . $folder;
}

function bv_mail_outbox_dir(): string
{
    $dir = __DIR__ . '/outbox';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
        @file_put_contents($dir . '/.htaccess', "Require all denied\n");   // customer emails must not be public
    }
    return $dir;
}

/** Logo used in emails: assets/images/email_logo.png. Empty string if the file is missing (the email still works, just without the logo). */
function bv_mail_logo_bytes(): string
{
    $path = __DIR__ . '/assets/images/email_logo.png';
    if (!is_file($path)) return '';
    $bytes = @file_get_contents($path);
    return $bytes === false ? '' : $bytes;
}

function bv_mail_log_error(string $message): void
{
    @file_put_contents(bv_mail_outbox_dir() . '/mail_errors.log', date('Y-m-d H:i:s') . '  ' . $message . "\n", FILE_APPEND);
}


/* =====================================================================
   3. SMTP sender
   ===================================================================== */

/** Reads one (possibly multi-line) server reply and checks its status code. */
function bv_smtp_command($socket, ?string $command, array $expectedCodes): string
{
    if ($command !== null) {
        fwrite($socket, $command . "\r\n");
    }
    $reply = '';
    while (($line = fgets($socket, 515)) !== false) {
        $reply .= $line;
        if (strlen($line) < 4 || $line[3] === ' ') {
            break;                                           // last line of this reply
        }
    }
    $code = (int)substr($reply, 0, 3);
    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException('Mail server said: ' . trim($reply !== '' ? $reply : 'no answer (timeout)'));
    }
    return $reply;
}

/** Opens the connection, secures it and signs in. Returns the open socket. */
function bv_smtp_connect(array $cfg)
{
    $verify = (bool)$cfg['verify_ssl'];
    $context = stream_context_create(['ssl' => [
        'verify_peer'       => $verify,
        'verify_peer_name'  => $verify,
        'allow_self_signed' => !$verify,
    ]]);
    $scheme = $cfg['encryption'] === 'ssl' ? 'ssl' : 'tcp';
    $socket = @stream_socket_client($scheme . '://' . $cfg['host'] . ':' . $cfg['port'], $errorNumber, $errorText, 15, STREAM_CLIENT_CONNECT, $context);
    if (!$socket) {
        throw new RuntimeException('Could not connect to the mail server (' . $errorText . ').');
    }
    stream_set_timeout($socket, 15);

    bv_smtp_command($socket, null, [220]);
    bv_smtp_command($socket, 'EHLO localhost', [250]);

    if ($cfg['encryption'] === 'tls') {
        bv_smtp_command($socket, 'STARTTLS', [220]);
        if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new RuntimeException('Could not start a secure connection. If this is a certificate error, set verify_ssl to false in mailer.php.');
        }
        bv_smtp_command($socket, 'EHLO localhost', [250]);
    }

    bv_smtp_command($socket, 'AUTH LOGIN', [334]);
    bv_smtp_command($socket, base64_encode($cfg['username']), [334]);
    // Gmail shows app passwords in groups of 4 separated by spaces; the spaces must not be sent.
    bv_smtp_command($socket, base64_encode(str_replace(' ', '', $cfg['password'])), [235]);
    return $socket;
}

/** For the outbox copy only: swaps the embedded-logo reference for a data URI so it opens in a browser. */
function bv_mail_preview_html(string $html): string
{
    if (bv_mail_logo_bytes() === '') return $html;
    return str_replace('cid:bvlogo', 'data:image/png;base64,' . base64_encode(bv_mail_logo_bytes()), $html);
}

/** Builds the full message: headers + plain-text part + HTML part. */
function bv_mail_build_message(array $cfg, string $fromEmail, string $toEmail, string $toName, string $subject, string $html, string $text): string
{
    $encodeHeader = fn(string $value): string => '=?UTF-8?B?' . base64_encode($value) . '?=';
    $alt = 'bvA_' . bin2hex(random_bytes(8));
    $rel = 'bvR_' . bin2hex(random_bytes(8));
    $hasLogo = strpos($html, 'cid:bvlogo') !== false && bv_mail_logo_bytes() !== '';

    $altBody = '--' . $alt . "\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
             . chunk_split(base64_encode($text))
             . '--' . $alt . "\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
             . chunk_split(base64_encode($html))
             . '--' . $alt . '--';

    if ($hasLogo) {
        // The logo travels inside the email itself (works in Gmail even from localhost).
        $contentType = 'multipart/related; boundary="' . $rel . '"';
        $body = '--' . $rel . "\r\nContent-Type: multipart/alternative; boundary=\"" . $alt . "\"\r\n\r\n" . $altBody . "\r\n"
              . '--' . $rel . "\r\nContent-Type: image/png; name=\"logo.png\"\r\nContent-Transfer-Encoding: base64\r\nContent-ID: <bvlogo>\r\nContent-Disposition: inline; filename=\"logo.png\"\r\n\r\n"
              . chunk_split(base64_encode(bv_mail_logo_bytes()))
              . '--' . $rel . '--';
    } else {
        $contentType = 'multipart/alternative; boundary="' . $alt . '"';
        $body = $altBody;
    }

    $headers = [
        'Date: ' . date('r'),
        'From: ' . $encodeHeader($cfg['from_name']) . ' <' . $fromEmail . '>',
        'To: ' . $encodeHeader($toName) . ' <' . $toEmail . '>',
        'Subject: ' . $encodeHeader($subject),
        'Message-ID: <' . bin2hex(random_bytes(10)) . '@bellavista.local>',
        'MIME-Version: 1.0',
        'Content-Type: ' . $contentType,
    ];
    return implode("\r\n", $headers) . "\r\n\r\n" . $body;
}

/**
 * Sends one email. Returns [true, 'sent'] or [false, 'reason'].
 * It never throws, so a mail problem can never stop a booking from being saved.
 */
function bv_send_mail(string $toEmail, string $toName, string $subject, string $html, string $text): array
{
    $cfg = BV_MAIL;
    $toEmail = bv_mail_single_line($toEmail);
    $toName = bv_mail_single_line($toName);
    $subject = bv_mail_single_line($subject);

    // Not set up yet: keep a copy in the outbox folder so the email can still be shown.
    if ($cfg['username'] === '' || $cfg['password'] === '') {
        $file = bv_mail_outbox_dir() . '/' . date('Ymd_His') . '_' . preg_replace('/[^A-Za-z0-9]+/', '-', $toEmail) . '.html';
        @file_put_contents($file, '<!-- To: ' . bv_mail_h($toEmail) . ' | Subject: ' . bv_mail_h($subject) . " -->\n" . bv_mail_preview_html($html));
        return [false, 'Email is not set up yet (fill in BV_MAIL in mailer.php). A copy was saved in the outbox folder.'];
    }

    try {
        $fromEmail = bv_mail_single_line($cfg['from_email'] !== '' ? $cfg['from_email'] : $cfg['username']);
        $socket = bv_smtp_connect($cfg);
        bv_smtp_command($socket, 'MAIL FROM:<' . $fromEmail . '>', [250]);
        bv_smtp_command($socket, 'RCPT TO:<' . $toEmail . '>', [250, 251]);
        bv_smtp_command($socket, 'DATA', [354]);
        fwrite($socket, bv_mail_build_message($cfg, $fromEmail, $toEmail, $toName, $subject, $html, $text) . "\r\n.\r\n");
        bv_smtp_command($socket, null, [250]);
        @fwrite($socket, "QUIT\r\n");
        fclose($socket);
        return [true, 'sent'];
    } catch (Throwable $error) {
        bv_mail_log_error($error->getMessage());
        return [false, $error->getMessage()];
    }
}


/* =====================================================================
   4. Email templates
   ===================================================================== */

/** Table of label / value rows. $rows = [['Date', 'Monday...'], ...] */
function bv_email_rows(array $rows): string
{
    $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-top:1px solid #d9e2d3;margin-bottom:6px;">';
    foreach ($rows as [$label, $value]) {
        $html .= '<tr><td style="padding:10px 0;border-bottom:1px solid #d9e2d3;font-size:14px;color:#5d6a5f;">' . bv_mail_h($label)
               . '</td><td align="right" style="padding:10px 0;border-bottom:1px solid #d9e2d3;font-size:14px;font-weight:bold;">' . bv_mail_h($value) . '</td></tr>';
    }
    return $html . '</table>';
}

/** The shared look: logo header, heading, content, optional button, footer. $heading and $intro are plain text. */
function bv_email_layout(string $heading, string $intro, string $contentHtml, string $buttonLabel = '', string $buttonUrl = ''): string
{
    $spa = bv_spa_info();
    $button = $buttonUrl === '' ? '' :
        '<p style="text-align:center;margin:28px 0 6px;"><a href="' . bv_mail_h($buttonUrl) . '" style="display:inline-block;background:#4f6b55;color:#ffffff;text-decoration:none;padding:14px 32px;border-radius:999px;font-size:13px;letter-spacing:2px;text-transform:uppercase;">' . bv_mail_h($buttonLabel) . '</a></p>';

    $logo = bv_mail_logo_bytes() === '' ? '' : '<img src="cid:bvlogo" width="84" height="84" alt="Bella Vista Oasis Spa" style="display:block;margin:0 auto 12px;border:0;width:84px;height:84px;">';

    return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light only"><meta name="supported-color-schemes" content="light only"><style>:root{color-scheme:light only;supported-color-schemes:light only;}</style></head><body bgcolor="#edf2e9" style="margin:0;padding:0;background:#edf2e9;font-family:Arial,Helvetica,sans-serif;color:#2c3a30;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" bgcolor="#edf2e9" style="background:#edf2e9;padding:24px 12px;"><tr><td align="center">'
        . '<table role="presentation" width="560" cellpadding="0" cellspacing="0" bgcolor="#fdf9f2" style="max-width:560px;width:100%;background:#fdf9f2;border:1px solid #d9e2d3;border-radius:18px;overflow:hidden;">'
        . '<tr><td bgcolor="#fdf9f2" style="background:#fdf9f2;padding:28px 28px 20px;text-align:center;border-bottom:3px solid #b08d57;">'
        . $logo
        . '<div style="font-family:Georgia,serif;font-size:18px;letter-spacing:5px;color:#2f4033;">BELLA VISTA</div>'
        . '<div style="font-size:11px;letter-spacing:6px;color:#5d6a5f;margin-top:4px;">OASIS SPA</div>'
        . '</td></tr>'
        . '<tr><td style="padding:30px 28px;">'
        . '<h1 style="margin:0 0 10px;font-family:Georgia,serif;font-size:27px;font-weight:normal;color:#2f4033;">' . bv_mail_h($heading) . '</h1>'
        . '<p style="margin:0 0 22px;font-size:15px;line-height:1.7;color:#5d6a5f;">' . bv_mail_h($intro) . '</p>'
        . $contentHtml . $button
        . '</td></tr>'
        . '<tr><td bgcolor="#e4ecdf" style="padding:20px 28px;background:#e4ecdf;border-top:1px solid #d1ddcb;font-size:12px;line-height:1.8;color:#4f6b55;text-align:center;">'
        . bv_mail_h($spa['address']) . '<br>' . bv_mail_h($spa['hours']) . ' &middot; ' . bv_mail_h($spa['phone'])
        . '</td></tr></table></td></tr></table></body></html>';
}

function bv_email_code_box(string $code): string
{
    return '<div style="margin:0 0 22px;padding:16px;border:1px dashed #b08d57;border-radius:12px;background:#f4f7f2;text-align:center;">'
         . '<div style="font-size:12px;letter-spacing:3px;color:#86652f;">BOOKING CODE</div>'
         . '<div style="font-size:26px;font-weight:bold;letter-spacing:3px;margin-top:4px;color:#2f4033;">' . bv_mail_h($code) . '</div></div>';
}

function bv_email_notice_box(string $title, string $text): string
{
    return '<div style="margin:18px 0 0;padding:16px 18px;border-radius:12px;background:#eaf0e7;border-left:4px solid #b08d57;color:#2f4033;font-size:14px;line-height:1.6;">'
         . '<strong>' . bv_mail_h($title) . '</strong><br>' . bv_mail_h($text) . '</div>';
}

/**
 * Email sent right after the customer confirms their booking request.
 * $booking keys: code, name, date (Y-m-d), time (H:i), guests, items [[name, line_total]...],
 *                total, down_payment, method (cash|ewallet|bank), provider, reference, hold_hours
 * Returns [subject, html, text].
 */
function bv_email_booking_received(array $booking): array
{
    $firstName = explode(' ', trim($booking['name']))[0];
    $when = date('l, F j, Y', strtotime($booking['date']));
    $time = date('g:i A', strtotime($booking['time']));
    $balance = max($booking['total'] - $booking['down_payment'], 0);
    $trackUrl = bv_base_url() . '/track.php?code=' . urlencode($booking['code']);
    $deadline = date('F j, Y g:i A', strtotime('+' . (int)$booking['hold_hours'] . ' hours'));
    $spa = bv_spa_info();

    if ($booking['method'] === 'cash') {
        $nextTitle = 'Next step: pay your down payment';
        $nextText = 'Please pay ' . bv_mail_money($booking['down_payment']) . ' in cash at the spa front desk by ' . $deadline
                  . ' and give your booking code. Your booking is confirmed once the front desk records your payment. Unpaid reservations are released automatically.';
    } else {
        $nextTitle = 'Next step: we are verifying your payment';
        $nextText = 'We received your payment details (reference ' . $booking['reference'] . '). Our team will check them, and you will get another email as soon as your booking is confirmed.';
    }

    $rows = [['Date', $when], ['Time', $time], ['Guests', (string)$booking['guests']]];
    foreach ($booking['items'] as [$itemName, $lineTotal]) {
        $rows[] = [$itemName, bv_mail_money($lineTotal)];
    }
    $rows[] = ['Total', bv_mail_money($booking['total'])];
    $rows[] = ['Down payment', bv_mail_money($booking['down_payment'])];
    $rows[] = ['Balance at the spa', bv_mail_money($balance)];

    $html = bv_email_layout(
        'Thank you, ' . $firstName . '.',
        'We received your booking request. Keep your booking code: together with this email address it lets you check your booking status anytime.',
        bv_email_code_box($booking['code']) . bv_email_rows($rows) . bv_email_notice_box($nextTitle, $nextText),
        'Track my booking',
        $trackUrl
    );

    $text = "Thank you, $firstName.\n\n"
          . "We received your booking request.\n"
          . "Booking code: {$booking['code']}\n"
          . "Date: $when at $time\n"
          . "Guests: {$booking['guests']}\n"
          . 'Total: ' . bv_mail_money($booking['total']) . "\n"
          . 'Down payment: ' . bv_mail_money($booking['down_payment']) . "\n\n"
          . "$nextTitle\n$nextText\n\n"
          . "Track your booking (use this email address): $trackUrl\n\n"
          . $spa['address'] . "\n" . $spa['hours'] . ' - ' . $spa['phone'] . "\n";

    return ['Your booking request ' . $booking['code'] . ' - Bella Vista Oasis Spa', $html, $text];
}

/**
 * Email sent when the spa confirms, cancels or completes a booking.
 * $booking keys: code, name, date (Y-m-d), time (H:i). Returns [subject, html, text].
 */
function bv_email_status_changed(array $booking, string $status): array
{
    $firstName = explode(' ', trim($booking['name']))[0];
    $when = date('l, F j, Y', strtotime($booking['date'])) . ' at ' . date('g:i A', strtotime($booking['time']));
    $trackUrl = bv_base_url() . '/track.php?code=' . urlencode($booking['code']);

    $messages = [
        'Confirmed' => ['Your booking is confirmed', 'We look forward to seeing you on ' . $when . '. Please arrive about 10 minutes early. Your slot is held for 15 minutes after your appointment time, and any remaining balance is paid at the spa.'],
        'Cancelled' => ['Your booking was cancelled', 'Your booking for ' . $when . ' has been cancelled. You are welcome to make a new booking anytime.'],
        'Completed' => ['Thank you for visiting us', 'We hope you enjoyed your visit on ' . $when . '. We would love to see you again soon.'],
    ];
    [$heading, $message] = $messages[$status] ?? ['Booking update', 'Your booking status is now ' . $status . '.'];

    $html = bv_email_layout(
        $heading,
        'Hi ' . $firstName . ', ' . $message,
        bv_email_code_box($booking['code']),
        'View my booking',
        $trackUrl
    );
    $text = "Hi $firstName,\n\n$heading\n$message\n\nBooking code: {$booking['code']}\nView your booking (use your email address): $trackUrl\n";

    return ['Booking ' . $booking['code'] . ': ' . strtolower($status) . ' - Bella Vista Oasis Spa', $html, $text];
}
