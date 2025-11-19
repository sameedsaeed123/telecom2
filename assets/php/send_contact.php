<?php
// send_contact.php
// Handles POST from contact form and sends an email. Uses PHPMailer if available (composer/autoload or PHPMailer present),
// otherwise falls back to PHP mail(). Reads configuration from a simple .env file at project root.

header('Content-Type: application/json; charset=utf-8');

// Attempt to load Composer autoloader if present (vendor/autoload.php)
$composerAutoload = __DIR__ . '/../../vendor/autoload.php';
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
}

// Simple .env loader (only key=val pairs)
function load_env($path)
{
    $result = [];
    if (!is_readable($path)) return $result;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') === false) continue;
        list($k, $v) = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v);
        // strip surrounding quotes
        if ((substr($v,0,1) === '"' && substr($v,-1) === '"') || (substr($v,0,1) === "'" && substr($v,-1) === "'")) {
            $v = substr($v,1,-1);
        }
        $result[$k] = $v;
    }
    return $result;
}

$env = load_env(__DIR__ . '/../../.env');

// Helper to log errors/debug
function env_log($msg)
{
    global $env;
    if (!empty($env['LOG_FILE'])) {
        $path = __DIR__ . '/../../' . $env['LOG_FILE'];
        @file_put_contents($path, date('[Y-m-d H:i:s] ') . $msg . PHP_EOL, FILE_APPEND);
    }
}

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Collect and sanitize inputs
$name = isset($_POST['name']) ? trim($_POST['name']) : '';
$email = isset($_POST['email']) ? trim($_POST['email']) : '';
$subject = isset($_POST['subject']) ? trim($_POST['subject']) : 'New contact message';
$message = isset($_POST['message']) ? trim($_POST['message']) : '';

$errors = [];
if ($name === '') $errors[] = 'Name is required';
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required';
if ($message === '') $errors[] = 'Message is required';

if (!empty($errors)) {
    echo json_encode(['success' => false, 'errors' => $errors]);
    exit;
}

$to = isset($env['MAIL_TO']) && $env['MAIL_TO'] !== '' ? $env['MAIL_TO'] : null;
if (!$to) {
    echo json_encode(['success' => false, 'error' => 'Recipient (MAIL_TO) not configured.']);
    env_log('MAIL_TO not configured in .env');
    exit;
}

$body = "You have a new contact form submission:\n\n";
$body .= "Name: " . $name . "\n";
$body .= "Email: " . $email . "\n";
$body .= "Subject: " . $subject . "\n\n";
$body .= "Message:\n" . $message . "\n";

// Prefer PHPMailer if available
if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        // If SMTP settings present in .env use SMTP
        if (!empty($env['SMTP_HOST'])) {
            $mail->isSMTP();
            $mail->Host = $env['SMTP_HOST'];
            $mail->SMTPAuth = true;
            $mail->Username = isset($env['SMTP_USER']) ? $env['SMTP_USER'] : '';
            $mail->Password = isset($env['SMTP_PASS']) ? $env['SMTP_PASS'] : '';
            if (!empty($env['SMTP_PORT'])) $mail->Port = (int)$env['SMTP_PORT'];
            if (!empty($env['SMTP_SECURE'])) $mail->SMTPSecure = $env['SMTP_SECURE'];
        }
        $from = !empty($env['MAIL_FROM']) ? $env['MAIL_FROM'] : 'no-reply@' . ($_SERVER['SERVER_NAME'] ?? 'localhost');
        $mail->setFrom($from, 'Website Contact');
        $mail->addAddress($to);
        // Reply-To visitor
        $mail->addReplyTo($email, $name);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->AltBody = $body;
        $mail->send();
        echo json_encode(['success' => true, 'message' => 'Message sent']);
        exit;
    } catch (Exception $e) {
        env_log('PHPMailer error: ' . $e->getMessage());
        // fallback to mail()
    }
}

// Fallback: use PHP mail()
$headers = [];
$fromHeader = !empty($env['MAIL_FROM']) ? $env['MAIL_FROM'] : 'no-reply@' . ($_SERVER['SERVER_NAME'] ?? 'localhost');
$headers[] = 'From: ' . $fromHeader;
$headers[] = 'Reply-To: ' . $email;
$headers[] = 'MIME-Version: 1.0';
$headers[] = 'Content-Type: text/plain; charset=UTF-8';
$ok = @mail($to, $subject, $body, implode("\r\n", $headers));
if ($ok) {
    echo json_encode(['success' => true, 'message' => 'Message sent (mail fallback)']);
    exit;
} else {
    env_log('mail() failed to send.');
    echo json_encode(['success' => false, 'error' => 'Unable to send message.']);
    exit;
}

?>
