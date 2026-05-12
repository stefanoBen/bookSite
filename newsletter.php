<?php
declare(strict_types=1);

$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    echo 'Config mancante: copia config.sample.php in config.php';
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

function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
function tokenHash(string $v): string { return hash('sha256', $v); }
function randomToken(): string { return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '='); }
function ipBin(): ?string { return isset($_SERVER['REMOTE_ADDR']) ? @inet_pton($_SERVER['REMOTE_ADDR']) ?: null : null; }
function now(): string { return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'); }

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$pdo = db($config['db']);

if ($action === 'subscribe' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
    $name = trim((string)($_POST['full_name'] ?? ''));
    $consent = isset($_POST['consent']) ? 1 : 0;
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !$consent) {
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

    $confirmUrl = $config['site']['base_url'].'/newsletter.php?action=confirm&token='.$confirmToken;
    $subject = 'Conferma iscrizione newsletter - Il Custode dei Miracoli';
    $message = "Conferma la tua iscrizione cliccando qui: $confirmUrl";
    @mail($email, $subject, $message, 'From: '.$config['site']['from_email']);
    @mail($config['site']['ops_email'], 'Nuova richiesta iscrizione', "Richiesta iscrizione: $email");
    header('Location: contatti.html?newsletter=check-email'); exit;
}

if ($action === 'confirm' && isset($_GET['token'])) {
    $hash = tokenHash((string)$_GET['token']);
    $stmt = $pdo->prepare('UPDATE icdm_subscribers SET status=\'active\', confirmed_at=:now WHERE confirm_token_hash=:hash AND status=\'pending\' AND confirm_expires_at >= :now');
    $current = now();
    $stmt->execute([':now'=>$current, ':hash'=>$hash]);
    echo $stmt->rowCount() ? 'Iscrizione confermata con successo.' : 'Token non valido o scaduto.';
    exit;
}

if ($action === 'unsubscribe' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { header('Location: contatti.html?newsletter=invalid'); exit; }
    $stmt = $pdo->prepare('UPDATE icdm_subscribers SET status=\'unsubscribed\', unsubscribed_at=:now WHERE email=:email AND status IN (\'active\',\'pending\')');
    $stmt->execute([':now'=>now(), ':email'=>$email]);
    @mail($config['site']['ops_email'], 'Disiscrizione newsletter', "Disiscrizione: $email");
    header('Location: contatti.html?newsletter=unsubscribed'); exit;
}

http_response_code(400);
echo 'Richiesta non valida.';
