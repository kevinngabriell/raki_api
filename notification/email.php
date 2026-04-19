<?php

require_once(__DIR__ . '/../connection/db.php');
require_once(__DIR__ . '/../vendor/autoload.php');
require_once(__DIR__ . '/../general.php');
require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/../log.php');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

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

// ── HTTP endpoint: POST /notification/email.php ───────────────────────────────

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: POST, OPTIONS");
    header("Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, Authorization");
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status_code' => 405, 'status_message' => 'Method Not Allowed. Use POST.']);
    exit;
}

$headers = getallheaders();
if (!isset($headers['Authorization'])) {
    http_response_code(401);
    echo json_encode(['status_code' => 401, 'status_message' => 'Authorization header not found.']);
    exit;
}

try {
    $token   = str_replace('Bearer ', '', $headers['Authorization']);
    $decoded = JWT::decode($token, new Key($_ENV['JWT_SECRET'], 'HS256'));
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['status_code' => 401, 'status_message' => 'Invalid or expired token.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (json_last_error() !== JSON_ERROR_NONE || empty($input)) {
    http_response_code(400);
    echo json_encode(['status_code' => 400, 'status_message' => 'Invalid JSON body.']);
    exit;
}

$to      = $input['to']       ?? null;
$subject = $input['subject']  ?? null;
$body    = $input['body']     ?? null;
$altBody = $input['alt_body'] ?? '';

if (empty($to) || empty($subject) || empty($body)) {
    http_response_code(400);
    echo json_encode(['status_code' => 400, 'status_message' => 'Missing required fields: to, subject, body.']);
    exit;
}

$conn = DB::conn();
$result = sendEmail($to, $subject, $body, $altBody);

if ($result['success']) {
    http_response_code(200);
    echo json_encode(['status_code' => 200, 'status_message' => 'Email sent successfully.']);
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
    http_response_code(500);
    echo json_encode(['status_code' => 500, 'status_message' => 'Failed to send email.', 'error' => $result['error'] ?? null]);
}

exit;
?>
