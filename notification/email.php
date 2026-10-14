<?php

require_once(__DIR__ . '/../connection/db.php');
require_once(__DIR__ . '/../vendor/autoload.php');
require_once(__DIR__ . '/../general.php');
require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/../log.php');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

/**
 * Send an email via SMTP.
 *
 * @param string|array $to      Single address string or [['email'=>..,'name'=>..], ...]
 * @param string       $subject
 * @param string       $body    HTML body
 * @param string       $altBody Plain-text fallback
 * @return array{success: bool, error?: string}
 */
function sendEmail($to, string $subject, string $body, string $altBody = ''): array {
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; // SSL port 465
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);

        if (is_string($to)) {
            $mail->addAddress($to);
        } else {
            foreach ($to as $recipient) {
                $mail->addAddress($recipient['email'], $recipient['name'] ?? '');
            }
        }

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->AltBody = $altBody ?: strip_tags($body);

        $mail->send();
        return ['success' => true];

    } catch (Exception $e) {
        return ['success' => false, 'error' => $mail->ErrorInfo];
    }
}

// ── Skip HTTP handling when included by another file ─────────────────────────
$isCli  = php_sapi_name() === 'cli';
$isHttp = !$isCli
    && isset($_SERVER['SCRIPT_FILENAME'])
    && basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__);

if (!$isCli && !$isHttp) {
    return;
}

if ($isCli || !$isHttp) {
    return; // CLI or included: only sendEmail() is needed
}

// ── HTTP endpoint: POST /notification/email.php ───────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$decoded = requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(405, 'Method Not Allowed. Use POST.');
}

$input = json_decode(file_get_contents('php://input'), true);

if (json_last_error() !== JSON_ERROR_NONE || empty($input)) {
    jsonResponse(400, 'Invalid JSON body.');
}

$to      = $input['to']       ?? null;
$subject = $input['subject']  ?? null;
$body    = $input['body']     ?? null;
$altBody = $input['alt_body'] ?? '';

if (empty($to) || empty($subject) || empty($body)) {
    jsonResponse(400, 'Missing required fields: to, subject, body.');
}

$conn = DB::conn();
$result = sendEmail($to, $subject, $body, $altBody);

if ($result['success']) {
    jsonResponse(200, 'Email sent successfully.');
} else {
    logApiError($conn, [
        'error_level'     => 'error',
        'http_status'     => 500,
        'endpoint'        => '/notification/email.php',
        'method'          => 'POST',
        'error_message'   => $result['error'] ?? 'Unknown SMTP error',
        'user_identifier' => $decoded->username ?? null,
        'company_id'      => null,
    ]);
    jsonResponse(500, 'Failed to send email.');
}

exit;
?>
