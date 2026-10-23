<?php

declare(strict_types=1);

require_once __DIR__ . '/SmtpCrypto.php';

/**
 * Friendly SMTP error translator that redacts passwords and full debug strings.
 */
function smtp_format_error(string $rawError): string
{
    $clean = SmtpCrypto::redactSensitive($rawError);
    $lower = strtolower($rawError);

    // Authentication failures (Gmail, Outlook, Zoho, standard SMTP)
    if (
        str_contains($lower, '535') ||
        str_contains($lower, '534') ||
        str_contains($lower, 'authentication failed') ||
        str_contains($lower, 'badcredentials') ||
        str_contains($lower, 'username and password not accepted') ||
        str_contains($lower, 'auth login failed') ||
        str_contains($lower, 'invalid credentials')
    ) {
        return 'Authentication failed: Invalid SMTP username or password. For Gmail or Outlook, please ensure you are using a 16-character App Password (not your regular login password or 2FA password).';
    }

    // Connection and Network Timeouts
    if (
        str_contains($lower, 'timed out') ||
        str_contains($lower, 'time out') ||
        str_contains($lower, 'connection failed') ||
        str_contains($lower, '10060') ||
        str_contains($lower, '110') ||
        str_contains($lower, 'refused') ||
        str_contains($lower, '10061')
    ) {
        return 'SMTP Connection timed out or server refused connection. Verify that the SMTP host and port are correct and that your network or firewall allows outbound connections on that port.';
    }

    // TLS / SSL Crypto Errors
    if (
        str_contains($lower, 'tls') ||
        str_contains($lower, 'ssl') ||
        str_contains($lower, 'crypto') ||
        str_contains($lower, 'certificate')
    ) {
        return 'TLS/SSL negotiation failed. Please check your encryption type and port settings (Port 587 uses TLS, Port 465 uses SSL).';
    }

    // Recipient Rejected / Relay Access Denied
    if (
        str_contains($lower, '550') ||
        str_contains($lower, '553') ||
        str_contains($lower, '501') ||
        str_contains($lower, 'relay access denied') ||
        str_contains($lower, 'recipient rejected') ||
        str_contains($lower, 'mailbox unavailable')
    ) {
        return 'Recipient email was rejected by the mail server or does not exist.';
    }

    // Provider Rate / Quota Limit
    if (
        str_contains($lower, 'limit reached') ||
        str_contains($lower, 'daily quota') ||
        str_contains($lower, 'exceeded') ||
        str_contains($lower, '452') ||
        str_contains($lower, '421') ||
        str_contains($lower, 'too many')
    ) {
        return 'Mail provider sending limit or temporary rate limit reached. Please wait before sending more emails.';
    }

    // Generic fallback with sensitive information stripped
    return 'SMTP Error: ' . preg_replace('/[^\w\s\.\-:,]/', '', $clean);
}

/**
 * Tests SMTP authentication without sending any email.
 * Connects, handshakes EHLO, starts TLS if required, and attempts AUTH LOGIN.
 *
 * @param array $cfg [host, port, encryption, username, password, from_email, from_name]
 * @return array [bool $success, string $message]
 */
function smtp_test_connection(array $cfg): array
{
    $host = trim($cfg['host'] ?? '');
    $port = (int)($cfg['port'] ?? 587);
    $encryption = strtolower(trim($cfg['encryption'] ?? 'tls'));
    $username = trim($cfg['username'] ?? '');
    $rawPassword = $cfg['password'] ?? '';

    // Strip spaces from Gmail App Password if entered with spaces (e.g. "abcd efgh ijkl mnop")
    $password = (strlen(str_replace(' ', '', $rawPassword)) === 16)
        ? str_replace(' ', '', $rawPassword)
        : $rawPassword;

    if ($host === '' || $username === '' || $password === '') {
        return [false, 'SMTP Host, Username, and Password are required.'];
    }

    $transport = ($encryption === 'ssl') ? 'ssl://' . $host : $host;
    $fp = @stream_socket_client($transport . ':' . $port, $errno, $errstr, 20, STREAM_CLIENT_CONNECT);
    if (!$fp) {
        return [false, smtp_format_error("SMTP connection failed: $errstr ($errno)")];
    }

    stream_set_timeout($fp, 20);

    $read = function () use ($fp) {
        $data = '';
        while (($line = fgets($fp, 515)) !== false) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return $data;
    };

    $expect = function (array $codes) use (&$read) {
        $r = $read();
        $code = (int)substr($r, 0, 3);
        if (!in_array($code, $codes, true)) {
            throw new RuntimeException('SMTP error: ' . trim($r));
        }
        return $r;
    };

    try {
        $expect([220]);
        $clientHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
        fwrite($fp, "EHLO " . $clientHost . "\r\n");
        $expect([250]);

        if ($encryption === 'tls') {
            fwrite($fp, "STARTTLS\r\n");
            $expect([220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Could not start TLS encryption.');
            }
            fwrite($fp, "EHLO " . $clientHost . "\r\n");
            $expect([250]);
        }

        fwrite($fp, "AUTH LOGIN\r\n");
        $expect([334]);

        fwrite($fp, base64_encode($username) . "\r\n");
        $expect([334]);

        fwrite($fp, base64_encode($password) . "\r\n");
        $expect([235]);

        fwrite($fp, "QUIT\r\n");
        fclose($fp);

        return [true, 'Connection and authentication successful! SMTP server is ready.'];
    } catch (Throwable $e) {
        if (is_resource($fp)) {
            @fwrite($fp, "QUIT\r\n");
            @fclose($fp);
        }
        return [false, smtp_format_error($e->getMessage())];
    }
}

/**
 * Sends an email using the specified SMTP configuration.
 *
 * @param array $cfg
 * @param string $to
 * @param string $subject
 * @param string $html
 * @param string $ics
 * @return array [bool $success, string $message]
 */
function smtp_send(array $cfg, string $to, string $subject, string $html, string $ics = ''): array
{
    // Sanitize recipient, subject, and sender headers against CRLF injection
    $to = SmtpCrypto::sanitizeHeader($to);
    $subject = SmtpCrypto::sanitizeHeader($subject);
    $fromEmail = SmtpCrypto::sanitizeHeader($cfg['from_email'] ?? '');
    $fromName = SmtpCrypto::sanitizeHeader($cfg['from_name'] ?? 'Webisdom CRM');

    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return [false, 'Invalid recipient email address.'];
    }

    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        return [false, 'Invalid sender (From) email address.'];
    }

    $host = trim($cfg['host'] ?? '');
    $port = (int)($cfg['port'] ?? 587);
    $encryption = strtolower(trim($cfg['encryption'] ?? 'tls'));
    $username = trim($cfg['username'] ?? '');
    $rawPassword = $cfg['password'] ?? '';

    // Strip spaces from Gmail App Password if needed
    $password = (strlen(str_replace(' ', '', $rawPassword)) === 16)
        ? str_replace(' ', '', $rawPassword)
        : $rawPassword;

    if ($host === '' || $username === '' || $password === '') {
        return [false, 'SMTP configuration is incomplete. Please check your email settings.'];
    }

    $transport = ($encryption === 'ssl') ? 'ssl://' . $host : $host;
    $fp = @stream_socket_client($transport . ':' . $port, $errno, $errstr, 20, STREAM_CLIENT_CONNECT);
    if (!$fp) {
        return [false, smtp_format_error("SMTP connection failed: $errstr ($errno)")];
    }

    stream_set_timeout($fp, 20);

    $read = function () use ($fp) {
        $data = '';
        while (($line = fgets($fp, 515)) !== false) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return $data;
    };

    $expect = function (array $codes) use (&$read) {
        $r = $read();
        $code = (int)substr($r, 0, 3);
        if (!in_array($code, $codes, true)) {
            throw new RuntimeException('SMTP error: ' . trim($r));
        }
        return $r;
    };

    try {
        $expect([220]);
        $clientHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
        fwrite($fp, "EHLO " . $clientHost . "\r\n");
        $expect([250]);

        if ($encryption === 'tls') {
            fwrite($fp, "STARTTLS\r\n");
            $expect([220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Could not start TLS encryption.');
            }
            fwrite($fp, "EHLO " . $clientHost . "\r\n");
            $expect([250]);
        }

        fwrite($fp, "AUTH LOGIN\r\n");
        $expect([334]);

        fwrite($fp, base64_encode($username) . "\r\n");
        $expect([334]);

        fwrite($fp, base64_encode($password) . "\r\n");
        $expect([235]);

        fwrite($fp, "MAIL FROM:<" . $fromEmail . ">\r\n");
        $expect([250]);

        fwrite($fp, "RCPT TO:<" . $to . ">\r\n");
        $expect([250, 251]);

        fwrite($fp, "DATA\r\n");
        $expect([354]);

        $boundary = 'b2bcrm_' . bin2hex(random_bytes(8));
        $encodedFromName = function_exists('mb_encode_mimeheader') ? mb_encode_mimeheader($fromName) : $fromName;
        $encodedSubject = function_exists('mb_encode_mimeheader') ? mb_encode_mimeheader($subject) : $subject;

        $headers = "From: " . $encodedFromName . " <" . $fromEmail . ">\r\n";
        $headers .= "To: <" . $to . ">\r\n";
        $headers .= "Subject: " . $encodedSubject . "\r\n";
        $headers .= "Date: " . date('r') . "\r\n";
        $headers .= "Message-ID: <" . time() . '.' . bin2hex(random_bytes(8)) . '@' . ($clientHost ?: 'webisdom.com') . ">\r\n";
        $headers .= "MIME-Version: 1.0\r\n";

        $body = '';
        if ($ics !== '') {
            $headers .= "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n\r\n";
            $body .= "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nA meeting has been scheduled. Please see the HTML invitation.\r\n";
            $body .= "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n$html\r\n";
            $body .= "--$boundary\r\nContent-Type: text/calendar; method=REQUEST; charset=UTF-8\r\nContent-Disposition: attachment; filename=meeting.ics\r\n\r\n$ics\r\n--$boundary--";
        } else {
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
            $body = $html;
        }

        fwrite($fp, $headers . $body . "\r\n.\r\n");
        $expect([250]);

        fwrite($fp, "QUIT\r\n");
        fclose($fp);

        return [true, 'Email sent successfully.'];
    } catch (Throwable $e) {
        if (is_resource($fp)) {
            @fwrite($fp, "QUIT\r\n");
            @fclose($fp);
        }
        return [false, smtp_format_error($e->getMessage())];
    }
}

/**
 * Loads and decrypts the SMTP configuration for a given CRM user.
 * Discards decrypted password from persistence immediately.
 *
 * @param PDO $pdo
 * @param int $userId
 * @return array|null Returns user SMTP config array, global fallback if enabled, or null
 */
function get_user_smtp_config(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM user_smtp_settings WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $decryptedPass = '';
        try {
            $decryptedPass = SmtpCrypto::decrypt($row['smtp_password_encrypted']);
        } catch (Throwable $e) {
            error_log('Failed to decrypt SMTP password for user ' . $userId . ': ' . $e->getMessage());
            $decryptedPass = '';
        }

        return [
            'id' => (int)$row['id'],
            'user_id' => (int)$row['user_id'],
            'from_name' => $row['from_name'],
            'from_email' => $row['from_email'],
            'host' => $row['smtp_host'],
            'port' => (int)$row['smtp_port'],
            'encryption' => $row['encryption'],
            'username' => $row['smtp_username'],
            'password' => $decryptedPass,
            'signature_text' => $row['signature_text'] ?? '',
            'is_verified' => (int)$row['is_verified'] === 1,
            'last_verified_at' => $row['last_verified_at'],
            'last_error' => $row['last_error'],
            'is_global_fallback' => false,
        ];
    }

    // Optional global fallback if explicitly enabled in .env
    $allowGlobal = filter_var(getenv('CRM_SMTP_ALLOW_GLOBAL_FALLBACK') ?: 'false', FILTER_VALIDATE_BOOLEAN);
    if ($allowGlobal) {
        $mailConfigFile = __DIR__ . '/../config/mail.php';
        if (file_exists($mailConfigFile)) {
            $globalCfg = require $mailConfigFile;
            if (!empty($globalCfg['username']) && !empty($globalCfg['password'])) {
                return [
                    'id' => 0,
                    'user_id' => $userId,
                    'from_name' => $globalCfg['from_name'] ?? 'Webisdom CRM',
                    'from_email' => $globalCfg['from_email'] ?? '',
                    'host' => $globalCfg['host'] ?? '',
                    'port' => (int)($globalCfg['port'] ?? 587),
                    'encryption' => $globalCfg['encryption'] ?? 'tls',
                    'username' => $globalCfg['username'] ?? '',
                    'password' => $globalCfg['password'] ?? '',
                    'signature_text' => '',
                    'is_verified' => true,
                    'last_verified_at' => null,
                    'last_error' => null,
                    'is_global_fallback' => true,
                ];
            }
        }
    }

    return null;
}

/**
 * Checks per-user outbound email rate limits.
 * Default: 20 emails / hour, 100 emails / day.
 *
 * @param PDO $pdo
 * @param int $userId
 * @return array [bool $allowed, string $message]
 */
function check_user_email_rate_limit(PDO $pdo, int $userId): array
{
    $hourlyLimit = (int)(getenv('CRM_USER_HOURLY_LIMIT') ?: 20);
    $dailyLimit = (int)(getenv('CRM_USER_DAILY_LIMIT') ?: 100);

    // Check last hour
    $stmtH = $pdo->prepare("
        SELECT COUNT(*) FROM email_messages 
        WHERE user_id = ? AND direction = 'outbound' 
          AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ");
    $stmtH->execute([$userId]);
    $countH = (int)$stmtH->fetchColumn();

    if ($countH >= $hourlyLimit) {
        return [false, "Hourly sending limit reached ($countH/$hourlyLimit emails this hour). Please wait before sending more."];
    }

    // Check last 24 hours
    $stmtD = $pdo->prepare("
        SELECT COUNT(*) FROM email_messages 
        WHERE user_id = ? AND direction = 'outbound' 
          AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ");
    $stmtD->execute([$userId]);
    $countD = (int)$stmtD->fetchColumn();

    if ($countD >= $dailyLimit) {
        return [false, "Daily sending limit reached ($countD/$dailyLimit emails in the last 24 hours). Please wait until your quota resets."];
    }

    return [true, ''];
}

/**
 * Generates an iCalendar (.ics) string for meeting invitations.
 */
function meeting_ics(array $m, string $organizer, string $attendee): string
{
    $uid = 'meeting-' . $m['id'] . '@webisdom-crm';
    $start = date('Ymd\THis', strtotime($m['meeting_date'] . ' ' . $m['start_time']));
    $end = $m['end_time']
        ? date('Ymd\THis', strtotime($m['meeting_date'] . ' ' . $m['end_time']))
        : date('Ymd\THis', strtotime($m['meeting_date'] . ' ' . $m['start_time'] . ' +1 hour'));
    $summary = addcslashes($m['title'], "\\,;");
    $desc = addcslashes($m['description'] ?? '', "\\,;");
    $location = addcslashes($m['location'] ?? ($m['meeting_link'] ?? ''), "\\,;");

    return "BEGIN:VCALENDAR\r\n" .
        "VERSION:2.0\r\n" .
        "PRODID:-//Webisdom//B2B CRM//EN\r\n" .
        "METHOD:REQUEST\r\n" .
        "BEGIN:VEVENT\r\n" .
        "UID:$uid\r\n" .
        "DTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n" .
        "DTSTART:$start\r\n" .
        "DTEND:$end\r\n" .
        "SUMMARY:$summary\r\n" .
        "DESCRIPTION:$desc\r\n" .
        "LOCATION:$location\r\n" .
        "ORGANIZER:MAILTO:$organizer\r\n" .
        "ATTENDEE;RSVP=TRUE:MAILTO:$attendee\r\n" .
        "END:VEVENT\r\n" .
        "END:VCALENDAR\r\n";
}
