<?php
declare(strict_types=1);

$configCandidates = [
    getenv('ICDM_CONFIG_PATH') ?: '',
    __DIR__ . '/config.php',
    __DIR__ . '/icdm_config/config.php',
    dirname(__DIR__) . '/icdm_config/config.php',
];
$configFile = '';
foreach ($configCandidates as $candidate) {
    if ($candidate !== '' && file_exists($candidate)) {
        $configFile = $candidate;
        break;
    }
}
if ($configFile === '') {
    header('Location: contatti.html?newsletter=config-missing');
    exit;
}
$config = require $configFile;

function db(array $cfg): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $cfg['host'], $cfg['port'], $cfg['name'], $cfg['charset']);
    $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}

function tokenHash(string $v): string { return hash('sha256', $v); }
function randomToken(): string { return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '='); }
function ipBin(): ?string { return isset($_SERVER['REMOTE_ADDR']) ? @inet_pton($_SERVER['REMOTE_ADDR']) ?: null : null; }
function now(): string { return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'); }

function sendTextMail(string $to, string $subject, string $message, array $site, array $smtp): bool {
    $fromEmail = $site['from_email'];
    $fromName = $site['from_name'];
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'From: ' . mb_encode_mimeheader($fromName, 'UTF-8') . ' <' . $fromEmail . '>',
        'Reply-To: ' . $fromEmail,
        'List-Unsubscribe: <mailto:' . $fromEmail . '?subject=unsubscribe>',
        'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
        'Message-ID: <' . bin2hex(random_bytes(8)) . '@' . ($_SERVER['SERVER_NAME'] ?? 'localhost') . '>',
        'X-Mailer: PHP/' . phpversion(),
    ];

    return sendSmtpMail($to, $subject, $message, $headers, $site, $smtp);
}

function sendSmtpMail(string $to, string $subject, string $message, array $headers, array $site, array $smtp): bool {
    $host = $smtp['host'] ?? '';
    $port = (int)($smtp['port'] ?? 465);
    $username = $smtp['username'] ?? '';
    $password = $smtp['password'] ?? '';
    if ($host === '' || $username === '' || $password === '') return false;

    $remote = ($port === 465 ? 'ssl://' : '') . $host;
    $fp = @stream_socket_client($remote . ':' . $port, $errno, $errstr, 15);
    if (!$fp) return false;

    $read = static function($fp): string { return (string)fgets($fp, 512); };
    $write = static function($fp, string $cmd): void { fwrite($fp, $cmd . "\r\n"); };
    $expect = static function($line, string $code): bool { return str_starts_with((string)$line, $code); };

    if (!$expect($read($fp), '220')) { fclose($fp); return false; }
    $write($fp, 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
    $ehlo = '';
    do { $line = $read($fp); $ehlo .= $line; } while (isset($line[3]) && $line[3] === '-');
    if (!str_starts_with($ehlo, '250')) { fclose($fp); return false; }

    if ($port === 587 && stripos($ehlo, 'STARTTLS') !== false) {
        $write($fp, 'STARTTLS');
        if (!$expect($read($fp), '220')) { fclose($fp); return false; }
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { fclose($fp); return false; }
        $write($fp, 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
        do { $line = $read($fp); } while (isset($line[3]) && $line[3] === '-');
    }

    $write($fp, 'AUTH LOGIN');
    if (!$expect($read($fp), '334')) { fclose($fp); return false; }
    $write($fp, base64_encode($username));
    if (!$expect($read($fp), '334')) { fclose($fp); return false; }
    $write($fp, base64_encode($password));
    if (!$expect($read($fp), '235')) { fclose($fp); return false; }

    $from = $site['from_email'];
    $write($fp, 'MAIL FROM:<' . $from . '>');
    if (!$expect($read($fp), '250')) { fclose($fp); return false; }
    $write($fp, 'RCPT TO:<' . $to . '>');
    if (!$expect($read($fp), '250')) { fclose($fp); return false; }
    $write($fp, 'DATA');
    if (!$expect($read($fp), '354')) { fclose($fp); return false; }

    $payload = 'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=' . "\r\n"
        . implode("\r\n", $headers) . "\r\n\r\n"
        . str_replace(["\r\n", "\r", "\n"], "\r\n", $message) . "\r\n.";
    $write($fp, $payload);
    if (!$expect($read($fp), '250')) { fclose($fp); return false; }

    $write($fp, 'QUIT');
    fclose($fp);
    return true;
}


function logEvent(PDO $pdo, int $subscriberId, string $eventType, ?string $note = null): void {
    $stmt = $pdo->prepare('INSERT INTO icdm_subscriber_events (subscriber_id, event_type, ip_address, note) VALUES (:subscriber_id, :event_type, :ip, :note)');
    $stmt->execute([
        ':subscriber_id' => $subscriberId,
        ':event_type' => $eventType,
        ':ip' => ipBin(),
        ':note' => $note,
    ]);
}

function findSubscriberId(PDO $pdo, string $email): ?int {
    $stmt = $pdo->prepare('SELECT id FROM icdm_subscribers WHERE email = :email LIMIT 1');
    $stmt->execute([':email' => $email]);
    $id = $stmt->fetchColumn();
    return $id !== false ? (int)$id : null;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$pdo = db($config['db']);

if ($action === 'subscribe' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
    $name = trim((string)($_POST['full_name'] ?? ''));
    $consent = isset($_POST['consent']) ? 1 : 0;
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !$consent || $name === '') {
        header('Location: contatti.html?newsletter=invalid'); exit;
    }

    $confirmToken = randomToken();
    $unsubscribeToken = randomToken();
    $expires = (new DateTimeImmutable('+72 hours', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare('INSERT INTO icdm_subscribers (email, full_name, status, confirm_token_hash, unsubscribe_token_hash, consent_marketing, consent_at, confirm_expires_at, source_page, ip_address, user_agent) VALUES (:email,:full_name,\'pending\',:confirm_hash,:unsubscribe_hash,1,:consent_at,:confirm_expires_at,:source_page,:ip,:ua)
    ON DUPLICATE KEY UPDATE full_name=VALUES(full_name), status=\'pending\', confirm_token_hash=VALUES(confirm_token_hash), consent_marketing=1, consent_at=VALUES(consent_at), confirm_expires_at=VALUES(confirm_expires_at), ip_address=VALUES(ip_address), user_agent=VALUES(user_agent), updated_at=CURRENT_TIMESTAMP');
    $stmt->execute([
      ':email'=>$email, ':full_name'=>$name ?: null, ':confirm_hash'=>tokenHash($confirmToken), ':unsubscribe_hash'=>tokenHash($unsubscribeToken),
      ':consent_at'=>now(), ':confirm_expires_at'=>$expires, ':source_page'=>'website', ':ip'=>ipBin(), ':ua'=>substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''),0,255)
    ]);

    $subscriberId = findSubscriberId($pdo, $email);
    if ($subscriberId !== null) {
        logEvent($pdo, $subscriberId, 'subscribe_request');
    }

    $confirmUrl = $config['site']['base_url'].'/newsletter.php?action=confirm&token='.$confirmToken;
    $subject = 'Conferma iscrizione newsletter - Il Custode dei Miracoli';
    $message = "Conferma la tua iscrizione cliccando qui: $confirmUrl";
    $okSubscriber = sendTextMail($email, $subject, $message, $config['site'], $config['smtp'] ?? []);
    sendTextMail($config['site']['ops_email'], 'Nuova richiesta iscrizione', "Richiesta iscrizione: $email", $config['site'], $config['smtp'] ?? []);
    if (!$okSubscriber) { header('Location: contatti.html?newsletter=mail-failed'); exit; }
    header('Location: contatti.html?newsletter=check-email'); exit;
}

if ($action === 'confirm' && isset($_GET['token'])) {
    $hash = tokenHash((string)$_GET['token']);
    $stmt = $pdo->prepare('UPDATE icdm_subscribers SET status=\'active\', confirmed_at=:now WHERE confirm_token_hash=:hash AND status=\'pending\' AND confirm_expires_at >= :now');
    $current = now();
    $stmt->execute([':now'=>$current, ':hash'=>$hash]);
    if ($stmt->rowCount() > 0) {
        $subStmt = $pdo->prepare('SELECT id FROM icdm_subscribers WHERE confirm_token_hash = :hash LIMIT 1');
        $subStmt->execute([':hash' => $hash]);
        $subscriberId = $subStmt->fetchColumn();
        if ($subscriberId !== false) {
            logEvent($pdo, (int)$subscriberId, 'subscribe_confirmed');
        }
    }
    echo $stmt->rowCount() ? 'Iscrizione confermata con successo.' : 'Token non valido o scaduto.';
    exit;
}

if ($action === 'unsubscribe' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { header('Location: contatti.html?newsletter=invalid'); exit; }
    $stmt = $pdo->prepare('UPDATE icdm_subscribers SET status=\'unsubscribed\', unsubscribed_at=:now WHERE email=:email AND status IN (\'active\',\'pending\')');
    $stmt->execute([':now'=>now(), ':email'=>$email]);
    $subscriberId = findSubscriberId($pdo, $email);
    if ($subscriberId !== null) {
        logEvent($pdo, $subscriberId, 'unsubscribe_request');
        if ($stmt->rowCount() > 0) {
            logEvent($pdo, $subscriberId, 'unsubscribe_confirmed');
        }
    }
    sendTextMail($config['site']['ops_email'], 'Disiscrizione newsletter', "Disiscrizione: $email", $config['site'], $config['smtp'] ?? []);
    header('Location: contatti.html?newsletter=unsubscribed'); exit;
}

http_response_code(400);
echo 'Richiesta non valida.';
