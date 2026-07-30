<?php
// OKR mail utility. Hand-rolled SMTP over a raw socket, same approach as
// atem/mailer.php (which itself follows odb/voucher/email_helper.php) - no
// PHPMailer/Composer vendor dependency, so there is nothing to
// `composer install` on deploy and no vendor/ to go stale.
// Sending is always best-effort: callers must not let a mail failure block
// the underlying action (e.g. a card suspend/appeal already committed to the DB).

/**
 * Daily mail-attempt log, mirrors atem/mailer.php's logMailOperation() so
 * mail delivery is traceable from a file this app controls even when PHP's
 * own error_log() path isn't something ops actually checks on production.
 */
function logOkrMailOperation($event, $message, $data = null, $level = 'INFO')
{
    $log_dir = __DIR__ . '/logs';
    $log_file = $log_dir . '/mail_operations-' . date('Y-m-d') . '.log';

    if (!is_dir($log_dir)) {
        if (!@mkdir($log_dir, 0755, true)) {
            @mkdir($log_dir, 0755);
        }
    }
    if (!is_dir($log_dir)) {
        return false;
    }
    if (!is_writable($log_dir)) {
        @chmod($log_dir, 0755);
    }

    $timestamp = date('Y-m-d H:i:s');
    $log_message = "[$timestamp] [$level] [$event] $message";
    if ($data !== null) {
        $log_message .= ' | ' . (is_array($data) ? json_encode($data) : $data);
    }
    $log_message .= "\n";

    $result = @file_put_contents($log_file, $log_message, FILE_APPEND);
    if ($result === false && !file_exists($log_file)) {
        @touch($log_file);
        @chmod($log_file, 0644);
        $result = @file_put_contents($log_file, $log_message, FILE_APPEND);
    }
    return $result !== false;
}

/**
 * SMTP credentials, sourced from .env (production mailbox) when present,
 * falling back to mail_config.local.php (e.g. a local Mailtrap sandbox).
 * Both files are gitignored - never commit real credentials. Same MAIL_*
 * key names as atem/mailer.php's getMailConfig() - each module keeps its
 * own .env, but the format matches so the two stay easy to compare.
 */
function getOkrMailConfig()
{
    static $config = null;
    if ($config === null) {
        $envPath = __DIR__ . '/.env';
        $env = (is_file($envPath) && is_readable($envPath))
            ? parse_ini_file($envPath, false, INI_SCANNER_RAW)
            : false;

        if (!empty($env['MAIL_HOST'])) {
            $port = isset($env['MAIL_PORT']) ? (int)$env['MAIL_PORT'] : 465;
            $config = array(
                'host'       => $env['MAIL_HOST'],
                'port'       => $port,
                'username'   => isset($env['MAIL_USERNAME']) ? $env['MAIL_USERNAME'] : '',
                'password'   => isset($env['MAIL_PASSWORD']) ? $env['MAIL_PASSWORD'] : '',
                'secure'     => isset($env['MAIL_ENCRYPTION']) ? $env['MAIL_ENCRYPTION'] : (($port === 465) ? 'ssl' : 'tls'),
                'from_email' => !empty($env['MAIL_FROM_ADDRESS']) ? $env['MAIL_FROM_ADDRESS'] : (isset($env['MAIL_USERNAME']) ? $env['MAIL_USERNAME'] : 'noreply@okr.local'),
                'from_name'  => !empty($env['MAIL_FROM_NAME']) ? $env['MAIL_FROM_NAME'] : 'OKR System',
            );
        } else {
            $path = __DIR__ . '/mail_config.local.php';
            $config = file_exists($path) ? include $path : array();
        }
    }
    return $config;
}

/**
 * Absolute link back to an OKR card - email clients can't resolve a
 * host-relative path, so scheme+host are added the same way
 * atem/mailer.php's buildAtemCardLink() and common/index_adv.php do.
 */
function buildOkrCardLink($cardId)
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $scheme = $https ? 'https://' : 'http://';
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    return $scheme . $host . '/odb/okr/view.php?id=' . (int)$cardId;
}

/**
 * Shared HTML email shell: a colored header band, a light content area, and a
 * footer note. Mirrors atem/mailer.php's atemEmailShell().
 */
function okrEmailShell($headerColor, $headerTitle, $innerHtml)
{
    return "
    <div style=\"font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; color: #333;\">
        <div style=\"background-color: {$headerColor}; color: #fff; padding: 16px 20px;\">
            <h2 style=\"margin: 0; font-size: 18px;\">" . htmlspecialchars($headerTitle) . "</h2>
        </div>
        <div style=\"background-color: #f9f9f9; padding: 20px;\">
            {$innerHtml}
        </div>
        <div style=\"text-align: center; padding: 16px; font-size: 12px; color: #6c757d;\">
            This is an automated message from OKR. Please do not reply to this email.
        </div>
    </div>
    ";
}

/**
 * Raw-socket SMTP send, identical protocol handling to atem/mailer.php's
 * _atemSmtpSend(): connect (implicit TLS or plain, per $useTLS), EHLO, AUTH
 * LOGIN, MAIL FROM/RCPT TO/DATA, reading the reply code after each step.
 * Returns array('ok' => bool, 'error' => string).
 */
function _okrSmtpSend($host, $port, $user, $pass, $fromName, $toEmail, $toName, $subject, $htmlBody, $useTLS = true)
{
    $ctx = stream_context_create(array('ssl' => array(
        'verify_peer'      => false,
        'verify_peer_name' => false,
        'allow_self_signed' => true,
    )));
    $target = ($useTLS ? 'ssl://' : '') . $host . ':' . $port;
    $sock = @stream_socket_client($target, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$sock) {
        return array('ok' => false, 'error' => "Could not connect to {$host}:{$port} - [{$errno}] {$errstr}");
    }
    stream_set_timeout($sock, 15);

    $read = function () use ($sock) {
        $r = '';
        while ($l = fgets($sock, 515)) {
            $r .= $l;
            if (isset($l[3]) && $l[3] === ' ') break;
        }
        return $r;
    };
    $cmd = function ($c) use ($sock, &$read) { fwrite($sock, $c . "\r\n"); return $read(); };

    $reply = $read(); // server greeting
    $reply = $cmd('EHLO localhost');
    $cmd('AUTH LOGIN');
    $cmd(base64_encode($user));
    $reply = $cmd(base64_encode($pass));
    if (strpos($reply, '235') === false) {
        fclose($sock);
        return array('ok' => false, 'error' => 'SMTP auth failed: ' . trim($reply));
    }

    $reply = $cmd('MAIL FROM:<' . $user . '>');
    if (strpos($reply, '250') === false) {
        fclose($sock);
        return array('ok' => false, 'error' => 'MAIL FROM rejected: ' . trim($reply));
    }
    $reply = $cmd('RCPT TO:<' . trim($toEmail) . '>');
    if (strpos($reply, '250') === false && strpos($reply, '251') === false) {
        fclose($sock);
        return array('ok' => false, 'error' => 'RCPT TO rejected: ' . trim($reply));
    }

    $reply = $cmd('DATA');
    if (strpos($reply, '354') === false) {
        fclose($sock);
        return array('ok' => false, 'error' => 'DATA rejected: ' . trim($reply));
    }

    $msg = 'Date: ' . date('r') . "\r\n"
        . 'From: =?UTF-8?B?' . base64_encode($fromName) . '?= <' . $user . '>' . "\r\n"
        . 'To: =?UTF-8?B?' . base64_encode($toName) . '?= <' . trim($toEmail) . '>' . "\r\n"
        . 'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=' . "\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n"
        . "\r\n"
        . chunk_split(base64_encode($htmlBody))
        . "\r\n.\r\n";
    fwrite($sock, $msg);
    $reply = $read();
    $cmd('QUIT');
    fclose($sock);

    if (strpos($reply, '250') === false) {
        return array('ok' => false, 'error' => 'Message not accepted: ' . trim($reply));
    }
    return array('ok' => true, 'error' => '');
}

/**
 * Low-level sender shared by every OKR notification email. Never throws -
 * returns array('success' => bool, 'message' => string) and logs failures.
 * Mirrors atem/mailer.php's dispatchAtemEmail().
 */
function dispatchOkrEmail($toEmail, $toName, $subject, $htmlBody, $altBody, $cardId)
{
    $cfg = getOkrMailConfig();
    if (empty($cfg['host']) || empty($cfg['username']) || empty($cfg['password'])) {
        error_log('OKR email skipped: mail is not configured (missing .env/mail_config.local.php values).');
        logOkrMailOperation('dispatchOkrEmail', 'Skipped - mail not configured', array('card_id' => (int)$cardId, 'reason' => 'no host/username/password in .env/mail_config.local.php', 'subject' => $subject), 'ERROR');
        return array('success' => false, 'message' => 'Mail is not configured.');
    }
    if (empty($toEmail)) {
        error_log('OKR email skipped: recipient has no email on file (card_id=' . (int)$cardId . ').');
        logOkrMailOperation('dispatchOkrEmail', 'Skipped - recipient has no email on file', array('card_id' => (int)$cardId, 'to_name' => $toName, 'subject' => $subject), 'WARNING');
        return array('success' => false, 'message' => 'Recipient has no email on file.');
    }

    $secure = isset($cfg['secure']) ? strtolower((string)$cfg['secure']) : '';
    $useTLS = !in_array($secure, array('', 'none', 'false', '0'), true);

    try {
        $result = _okrSmtpSend(
            $cfg['host'],
            (int)$cfg['port'],
            $cfg['username'],
            $cfg['password'],
            !empty($cfg['from_name']) ? $cfg['from_name'] : 'OKR System',
            $toEmail,
            $toName,
            $subject,
            $htmlBody,
            $useTLS
        );
    } catch (Throwable $e) {
        // Catches anything unexpected in the socket/protocol handling so a
        // mail-step bug can never corrupt the JSON response of the action
        // that triggered it (e.g. suspend/appeal).
        error_log('OKR email failed (card_id=' . (int)$cardId . '): ' . $e->getMessage());
        logOkrMailOperation('dispatchOkrEmail', 'Failed - exception', array('card_id' => (int)$cardId, 'to' => $toEmail, 'subject' => $subject, 'error' => $e->getMessage()), 'ERROR');
        return array('success' => false, 'message' => $e->getMessage());
    }

    if ($result['ok']) {
        logOkrMailOperation('dispatchOkrEmail', 'Sent', array('card_id' => (int)$cardId, 'to' => $toEmail, 'subject' => $subject), 'INFO');
        return array('success' => true);
    }

    error_log('OKR email failed (card_id=' . (int)$cardId . '): ' . $result['error']);
    logOkrMailOperation('dispatchOkrEmail', 'Failed - SMTP error', array('card_id' => (int)$cardId, 'to' => $toEmail, 'subject' => $subject, 'error' => $result['error']), 'ERROR');
    return array('success' => false, 'message' => $result['error']);
}

/**
 * Notify an OKR card's Issuer that their card was suspended.
 */
function sendOkrSuspensionEmail($toEmail, $toName, $cardId, $objective, $reason, $suspendedByName)
{
    $link = buildOkrCardLink($cardId);

    $inner = "<p>Hi " . htmlspecialchars($toName) . ",</p>"
        . "<p>Your OKR <strong>" . htmlspecialchars($objective) . "</strong> (OKR #" . (int)$cardId . ") has been suspended by " . htmlspecialchars($suspendedByName) . ".</p>"
        . "<p><strong>Reason for suspension:</strong><br>" . nl2br(htmlspecialchars($reason)) . "</p>"
        . "<p>Any unattended suspended OKR will be terminated after 30 days. If you believe this was suspended in error, open the card and submit an Appeal.</p>"
        . "<p style=\"margin-top: 24px;\"><a href=\"" . htmlspecialchars($link) . "\" style=\"display: inline-block; padding: 10px 20px; background-color: #0d6efd; color: #fff; text-decoration: none; border-radius: 5px;\">View OKR Card</a></p>";

    $altBody = "Your OKR \"{$objective}\" (OKR #{$cardId}) has been suspended by {$suspendedByName}.\n"
        . "Reason: {$reason}\n"
        . "Any unattended suspended OKR will be terminated after 30 days.\n"
        . "View the card: {$link}";

    return dispatchOkrEmail(
        $toEmail,
        $toName,
        'OKR Suspended: ' . $objective,
        okrEmailShell('#dc3545', 'OKR Suspended', $inner),
        $altBody,
        $cardId
    );
}

/**
 * Notify a CEO/admin recipient that an appeal was submitted against a
 * suspended OKR card.
 */
function sendOkrAppealEmail($toEmail, $toName, $cardId, $objective, $justification, $issuerName)
{
    // Plain login-gated deep link, not a magic bypass token - lock_adv.php
    // already requires login on view.php, so there is no auth to bypass
    // here; a signed one-click link would be the less secure choice.
    $link = buildOkrCardLink($cardId);

    $inner = "<p>Hi " . htmlspecialchars($toName) . ",</p>"
        . "<p>" . htmlspecialchars($issuerName) . " has appealed the suspension of OKR <strong>" . htmlspecialchars($objective) . "</strong> (OKR #" . (int)$cardId . ").</p>"
        . "<p><strong>Justification:</strong><br>" . nl2br(htmlspecialchars($justification)) . "</p>"
        . "<p style=\"margin-top: 24px;\"><a href=\"" . htmlspecialchars($link) . "\" style=\"display: inline-block; padding: 10px 20px; background-color: #0d6efd; color: #fff; text-decoration: none; border-radius: 5px;\">Review OKR Card</a></p>"
        . "<p style=\"font-size: 12px; color: #6c757d; margin-top: 12px;\">You'll need to log in to review and act on this appeal.</p>";

    $altBody = "{$issuerName} has appealed the suspension of OKR \"{$objective}\" (OKR #{$cardId}).\n"
        . "Justification: {$justification}\n"
        . "Review the card (login required): {$link}";

    return dispatchOkrEmail(
        $toEmail,
        $toName,
        'OKR Appeal Submitted: ' . $objective . ' (OKR #' . (int)$cardId . ')',
        okrEmailShell('#fd7e14', 'OKR Appeal Submitted', $inner),
        $altBody,
        $cardId
    );
}
