<?php
// OKR mail utility. Uses PHPMailer via Composer (see composer.json/vendor),
// same pattern as atem/mailer.php. Sending is always best-effort: callers
// must not let a mail failure block the underlying action (e.g. a card
// suspend/appeal already committed to the DB).

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * SMTP credentials, sourced from .env (production mailbox) when present,
 * falling back to mail_config.local.php (e.g. a local Mailtrap sandbox).
 * Both files are gitignored - never commit real credentials.
 */
function getOkrMailConfig()
{
    static $config = null;
    if ($config === null) {
        $envPath = __DIR__ . '/.env';
        $env = (is_file($envPath) && is_readable($envPath))
            ? parse_ini_file($envPath, false, INI_SCANNER_RAW)
            : false;

        if (!empty($env['outgoing_server'])) {
            $port = isset($env['smtp_port']) ? (int)$env['smtp_port'] : 465;
            $config = array(
                'host'       => $env['outgoing_server'],
                'port'       => $port,
                'username'   => isset($env['username']) ? $env['username'] : '',
                'password'   => isset($env['password']) ? $env['password'] : '',
                'secure'     => ($port === 465) ? 'ssl' : 'tls',
                'from_email' => !empty($env['username']) ? $env['username'] : 'noreply@okr.local',
                'from_name'  => 'OKR System',
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

function okrMailerFrom($mail, $cfg)
{
    $mail->setFrom(
        !empty($cfg['from_email']) ? $cfg['from_email'] : 'noreply@okr.local',
        !empty($cfg['from_name']) ? $cfg['from_name'] : 'OKR System'
    );
}

function okrMailerConfigured($cfg)
{
    return !empty($cfg['host']) && class_exists(PHPMailer::class);
}

/**
 * Notify an OKR card's Issuer that their card was suspended. Never throws -
 * returns array('success' => bool, 'message' => string) and logs failures.
 */
function sendOkrSuspensionEmail($toEmail, $toName, $cardId, $objective, $reason, $suspendedByName)
{
    $cfg = getOkrMailConfig();
    if (!okrMailerConfigured($cfg)) {
        error_log('OKR suspension email skipped: mail is not configured (missing vendor/ or mail_config.local.php).');
        return array('success' => false, 'message' => 'Mail is not configured.');
    }
    if (empty($toEmail)) {
        error_log('OKR suspension email skipped: issuer has no email on file (card_id=' . (int)$cardId . ').');
        return array('success' => false, 'message' => 'Issuer has no email on file.');
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = $cfg['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $cfg['username'];
        $mail->Password = $cfg['password'];
        $mail->Port = $cfg['port'];
        $mail->SMTPSecure = !empty($cfg['secure'])
            ? $cfg['secure']
            : (((int)$cfg['port'] === 465) ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS);
        $mail->CharSet = 'UTF-8';

        okrMailerFrom($mail, $cfg);
        $mail->addAddress($toEmail, $toName);

        $link = buildOkrCardLink($cardId);

        $mail->isHTML(true);
        $mail->Subject = 'OKR Suspended: ' . $objective;
        $mail->Body = "
        <div style=\"font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; color: #333;\">
            <div style=\"background-color: #dc3545; color: #fff; padding: 16px 20px;\">
                <h2 style=\"margin: 0; font-size: 18px;\">OKR Suspended</h2>
            </div>
            <div style=\"background-color: #f9f9f9; padding: 20px;\">
                <p>Hi " . htmlspecialchars($toName) . ",</p>
                <p>Your OKR <strong>" . htmlspecialchars($objective) . "</strong> (OKR #" . (int)$cardId . ") has been suspended by " . htmlspecialchars($suspendedByName) . ".</p>
                <p><strong>Reason for suspension:</strong><br>" . nl2br(htmlspecialchars($reason)) . "</p>
                <p>Any unattended suspended OKR will be terminated after 30 days. If you believe this was suspended in error, open the card and submit an Appeal.</p>
                <p style=\"margin-top: 24px;\">
                    <a href=\"" . htmlspecialchars($link) . "\" style=\"display: inline-block; padding: 10px 20px; background-color: #0d6efd; color: #fff; text-decoration: none; border-radius: 5px;\">View OKR Card</a>
                </p>
            </div>
            <div style=\"text-align: center; padding: 16px; font-size: 12px; color: #6c757d;\">
                This is an automated message from OKR. Please do not reply to this email.
            </div>
        </div>
        ";
        $mail->AltBody = "Your OKR \"{$objective}\" (OKR #{$cardId}) has been suspended by {$suspendedByName}.\n"
            . "Reason: {$reason}\n"
            . "Any unattended suspended OKR will be terminated after 30 days.\n"
            . "View the card: {$link}";

        $mail->send();
        return array('success' => true);
    } catch (PHPMailerException $e) {
        error_log('OKR suspension email failed (card_id=' . (int)$cardId . '): ' . $mail->ErrorInfo);
        return array('success' => false, 'message' => $mail->ErrorInfo);
    } catch (Throwable $e) {
        error_log('OKR suspension email failed (card_id=' . (int)$cardId . '): ' . $e->getMessage());
        return array('success' => false, 'message' => $e->getMessage());
    }
}

/**
 * Notify a CEO/admin recipient that an appeal was submitted against a
 * suspended OKR card. Never throws.
 */
function sendOkrAppealEmail($toEmail, $toName, $cardId, $objective, $justification, $issuerName)
{
    $cfg = getOkrMailConfig();
    if (!okrMailerConfigured($cfg)) {
        error_log('OKR appeal email skipped: mail is not configured (missing vendor/ or mail_config.local.php).');
        return array('success' => false, 'message' => 'Mail is not configured.');
    }
    if (empty($toEmail)) {
        error_log('OKR appeal email skipped: recipient has no email on file (card_id=' . (int)$cardId . ').');
        return array('success' => false, 'message' => 'Recipient has no email on file.');
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = $cfg['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $cfg['username'];
        $mail->Password = $cfg['password'];
        $mail->Port = $cfg['port'];
        $mail->SMTPSecure = !empty($cfg['secure'])
            ? $cfg['secure']
            : (((int)$cfg['port'] === 465) ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS);
        $mail->CharSet = 'UTF-8';

        okrMailerFrom($mail, $cfg);
        $mail->addAddress($toEmail, $toName);

        // Plain login-gated deep link, not a magic bypass token - lock_adv.php
        // already requires login on view.php, so there is no auth to bypass
        // here; a signed one-click link would be the less secure choice.
        $link = buildOkrCardLink($cardId);

        $mail->isHTML(true);
        $mail->Subject = 'OKR Appeal Submitted: ' . $objective . ' (OKR #' . (int)$cardId . ')';
        $mail->Body = "
        <div style=\"font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; color: #333;\">
            <div style=\"background-color: #fd7e14; color: #fff; padding: 16px 20px;\">
                <h2 style=\"margin: 0; font-size: 18px;\">OKR Appeal Submitted</h2>
            </div>
            <div style=\"background-color: #f9f9f9; padding: 20px;\">
                <p>Hi " . htmlspecialchars($toName) . ",</p>
                <p>" . htmlspecialchars($issuerName) . " has appealed the suspension of OKR <strong>" . htmlspecialchars($objective) . "</strong> (OKR #" . (int)$cardId . ").</p>
                <p><strong>Justification:</strong><br>" . nl2br(htmlspecialchars($justification)) . "</p>
                <p style=\"margin-top: 24px;\">
                    <a href=\"" . htmlspecialchars($link) . "\" style=\"display: inline-block; padding: 10px 20px; background-color: #0d6efd; color: #fff; text-decoration: none; border-radius: 5px;\">Review OKR Card</a>
                </p>
            </div>
            <div style=\"text-align: center; padding: 16px; font-size: 12px; color: #6c757d;\">
                This is an automated message from OKR. Please do not reply to this email. You'll need to log in to review and act on this appeal.
            </div>
        </div>
        ";
        $mail->AltBody = "{$issuerName} has appealed the suspension of OKR \"{$objective}\" (OKR #{$cardId}).\n"
            . "Justification: {$justification}\n"
            . "Review the card (login required): {$link}";

        $mail->send();
        return array('success' => true);
    } catch (PHPMailerException $e) {
        error_log('OKR appeal email failed (card_id=' . (int)$cardId . '): ' . $mail->ErrorInfo);
        return array('success' => false, 'message' => $mail->ErrorInfo);
    } catch (Throwable $e) {
        error_log('OKR appeal email failed (card_id=' . (int)$cardId . '): ' . $e->getMessage());
        return array('success' => false, 'message' => $e->getMessage());
    }
}
