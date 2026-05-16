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
function base64url_encode(string $d): string { return rtrim(strtr(base64_encode($d), '+/', '-_'), '='); }
function base64url_decode(string $d): string { return base64_decode(strtr($d, '-_', '+/')) ?: ''; }
function buildUnsubscribeUrl(array $site, int $subscriberId, string $email): string {
    $payload = $subscriberId . '|' . $email;
    $key = (string)($site['unsubscribe_secret'] ?? 'change-me');
    $sig = hash_hmac('sha256', $payload, $key);
    return rtrim($site['base_url'], '/') . '/newsletter.php?action=unsubscribe_token&token=' . base64url_encode($payload . '|' . $sig);
}
function verifyUnsubscribeToken(array $site, string $token): ?array {
    $raw = base64url_decode($token);
    $parts = explode('|', $raw);
    if (count($parts) !== 3) return null;
    [$id,$email,$sig] = $parts;
    $check = hash_hmac('sha256', $id.'|'.$email, (string)($site['unsubscribe_secret'] ?? 'change-me'));
    if (!hash_equals($check, $sig)) return null;
    return ['id'=>(int)$id,'email'=>$email];
}


function buildEmailHtml(array $site, string $title, string $subtitle, array $paragraphs, ?string $buttonUrl = null, ?string $buttonLabel = null, ?string $unsubscribeUrl = null): string {
    $base = rtrim($site['base_url'], '/');
    $headerUrl = $base . '/assets/email/header-newsletter.png';
    $footerUrl = $base . '/assets/email/footer-newsletter.png';
    $buttonImageUrl = $base . '/assets/email/button-conferma.png';

    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $safeSubtitle = htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8');

    $html = '<!doctype html><html><body style="margin:0;padding:0;background:#efefeb;font-family:Georgia,Times New Roman,serif;color:#112133;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:24px 0;">'
        . '<tr><td align="center">'
        . '<table role="presentation" width="700" cellpadding="0" cellspacing="0" style="max-width:700px;background:#f8f5ef;border:1px solid #d8c8a5;border-radius:16px;overflow:hidden;">'
        . '<tr><td style="padding:0;"><img src="' . $headerUrl . '" alt="Header newsletter" width="700" style="display:block;width:100%;height:auto;border:0;outline:none;text-decoration:none;"></td></tr>'
        . '<tr><td style="padding:34px 44px 22px;">'
        . '<h1 style="margin:0 0 14px;font-size:40px;line-height:1.15;color:#0b1b2e;">' . $safeTitle . '</h1>'
        . '<p style="margin:0 0 18px;font-size:22px;color:#9b7b33;font-style:italic;">' . $safeSubtitle . '</p>';

    foreach ($paragraphs as $paragraph) {
        $html .= '<p style="margin:0 0 14px;font-size:22px;line-height:1.5;">' . $paragraph . '</p>';
    }

    if ($buttonUrl !== null && $buttonLabel !== null) {
        $safeUrl = htmlspecialchars($buttonUrl, ENT_QUOTES, 'UTF-8');
        $safeLabel = htmlspecialchars($buttonLabel, ENT_QUOTES, 'UTF-8');
        $html .= '<p style="text-align:center;margin:8px 0 24px;">'
            . '<a href="' . $safeUrl . '" style="display:inline-block;text-decoration:none;">'
            . '<img src="' . $buttonImageUrl . '" alt="' . $safeLabel . '" width="560" style="display:block;max-width:100%;height:auto;border:0;outline:none;text-decoration:none;">'
            . '</a></p>';
    }

    $privacyUrl = rtrim($site['base_url'], '/') . '/privacy.html';
    $footerLegal = '<p style="font-size:14px;line-height:1.5;color:#5f6670;text-align:center;margin:16px 0 6px;">';
    if ($unsubscribeUrl !== null) {
        $footerLegal .= 'Ricevi questa email perché ti sei iscritto alla newsletter ufficiale. Puoi revocare il consenso in qualsiasi momento: <a href="' . htmlspecialchars($unsubscribeUrl, ENT_QUOTES, 'UTF-8') . '">disiscriviti qui</a>.';
    } else {
        $footerLegal .= 'Se non sei stato tu, puoi ignorare questa email.';
    }
    $footerLegal .= '</p><p style="font-size:13px;line-height:1.5;color:#777;text-align:center;margin:0 0 6px;">Consulta l\' <a href="' . htmlspecialchars($privacyUrl, ENT_QUOTES, 'UTF-8') . '">informativa privacy</a>.</p>';
    $html .= $footerLegal
        . '</td></tr>'
        . '<tr><td style="padding:0;"><img src="' . $footerUrl . '" alt="Footer newsletter" width="700" style="display:block;width:100%;height:auto;border:0;outline:none;text-decoration:none;"></td></tr>'
        . '</table></td></tr></table></body></html>';

    return $html;
}

function buildConfirmationEmailHtml(array $site, string $confirmUrl): string {
    return buildEmailHtml(
        $site,
        'Conferma la tua iscrizione',
        'Stefano Benedetti',
        [
            'Abbiamo ricevuto una richiesta di iscrizione alla newsletter ufficiale.',
            'Per attivare l\'iscrizione e iniziare a ricevere aggiornamenti su <em>Il Custode dei Miracoli</em>, clicca sul pulsante qui sotto.'
        ],
        $confirmUrl,
        'Conferma iscrizione'
    );
}

function buildWelcomeEmailHtml(array $site, string $unsubscribeUrl): string {
    return buildEmailHtml(
        $site,
        'Iscrizione confermata',
        'Benvenuto nella newsletter',
        [
            'Benvenuto: la tua iscrizione alla newsletter ufficiale è andata a buon fine.',
            'Da questo momento riceverai aggiornamenti sul libro, anticipazioni e novità sul percorso di pubblicazione.'
        ],
        null,
        null,
        $unsubscribeUrl
    );
}

function buildUnsubscribeEmailHtml(array $site): string {
    return buildEmailHtml(
        $site,
        'Disiscrizione confermata',
        'Ci dispiace vederti andare via',
        [
            'La tua disiscrizione è stata completata correttamente e non riceverai più newsletter.',
            'Se in futuro vorrai tornare, potrai iscriverti nuovamente dal sito ufficiale in qualsiasi momento.'
        ],
        null,
        null
    );
}

function sendTextMail(string $to, string $subject, string $textMessage, array $site, array $smtp, ?string $htmlMessage = null): bool {
    $fromEmail = $site['from_email'];
    $fromName = $site['from_name'];
    $boundary = 'b_' . bin2hex(random_bytes(12));
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        'From: ' . mb_encode_mimeheader($fromName, 'UTF-8') . ' <' . $fromEmail . '>',
        'Reply-To: ' . $fromEmail,
        'List-Unsubscribe: <mailto:' . $fromEmail . '?subject=unsubscribe>',
        'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
        'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
        'Message-ID: <' . bin2hex(random_bytes(8)) . '@' . ($_SERVER['SERVER_NAME'] ?? 'localhost') . '>',
        'X-Mailer: PHP/' . phpversion(),
    ];

    return sendSmtpMail($to, $subject, $textMessage, $headers, $site, $smtp, $htmlMessage, $boundary);
}

function sendSmtpMail(string $to, string $subject, string $textMessage, array $headers, array $site, array $smtp, ?string $htmlMessage, string $boundary): bool {
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

    $plainPart = str_replace(["\r\n", "\r", "\n"], "\r\n", $textMessage);
    $htmlPart = str_replace(["\r\n", "\r", "\n"], "\r\n", $htmlMessage ?? nl2br(htmlspecialchars($textMessage, ENT_QUOTES, 'UTF-8')));
    $multipartBody = '--' . $boundary . "\r\n"
        . 'Content-Type: text/plain; charset=UTF-8' . "\r\n\r\n"
        . $plainPart . "\r\n"
        . '--' . $boundary . "\r\n"
        . 'Content-Type: text/html; charset=UTF-8' . "\r\n\r\n"
        . $htmlPart . "\r\n"
        . '--' . $boundary . '--';

    $payload = 'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=' . "\r\n"
        . implode("\r\n", $headers) . "\r\n\r\n"
        . $multipartBody . "\r\n.";
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

    $stmt = $pdo->prepare('INSERT INTO icdm_subscribers (email, full_name, status, confirm_token_hash, unsubscribe_token_hash, consent_marketing, consent_at, confirm_expires_at, source_page, ip_address, user_agent, privacy_version, privacy_url, consent_text) VALUES (:email,:full_name,\'pending\',:confirm_hash,:unsubscribe_hash,1,:consent_at,:confirm_expires_at,:source_page,:ip,:ua,:privacy_version,:privacy_url,:consent_text)
    ON DUPLICATE KEY UPDATE full_name=VALUES(full_name), status=\'pending\', confirm_token_hash=VALUES(confirm_token_hash), consent_marketing=1, consent_at=VALUES(consent_at), confirm_expires_at=VALUES(confirm_expires_at), ip_address=VALUES(ip_address), user_agent=VALUES(user_agent), privacy_version=VALUES(privacy_version), privacy_url=VALUES(privacy_url), consent_text=VALUES(consent_text), updated_at=CURRENT_TIMESTAMP');
    $stmt->execute([
      ':email'=>$email, ':full_name'=>$name ?: null, ':confirm_hash'=>tokenHash($confirmToken), ':unsubscribe_hash'=>tokenHash($unsubscribeToken),
      ':consent_at'=>now(), ':confirm_expires_at'=>$expires, ':source_page'=>'website', ':ip'=>ipBin(), ':ua'=>substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''),0,255), ':privacy_version'=>'privacy-newsletter-2026-05-16', ':privacy_url'=>rtrim($config['site']['base_url'],'/').'/privacy.html', ':consent_text'=>'Ho letto l\'informativa privacy e acconsento a ricevere comunicazioni email sul libro, aggiornamenti editoriali, anticipazioni e notizie sulla pubblicazione.'
    ]);

    $subscriberId = findSubscriberId($pdo, $email);
    if ($subscriberId !== null) {
        logEvent($pdo, $subscriberId, 'subscribe_request');
    }

    $confirmUrl = $config['site']['base_url'].'/newsletter.php?action=confirm&token='.$confirmToken;
    $subject = 'Conferma iscrizione newsletter - Il Custode dei Miracoli';
    $message = "Conferma la tua iscrizione cliccando qui: $confirmUrl";
    $htmlMessage = buildConfirmationEmailHtml($config['site'], $confirmUrl);
    $okSubscriber = sendTextMail($email, $subject, $message, $config['site'], $config['smtp'] ?? [], $htmlMessage);
    sendTextMail($config['site']['ops_email'], 'Nuova richiesta iscrizione', "Richiesta iscrizione: $email", $config['site'], $config['smtp'] ?? [], null);
    if (!$okSubscriber) { header('Location: contatti.html?newsletter=mail-failed'); exit; }
    header('Location: contatti.html?newsletter=check-email'); exit;
}

if ($action === 'confirm' && isset($_GET['token'])) {
    $hash = tokenHash((string)$_GET['token']);
    $stmt = $pdo->prepare('UPDATE icdm_subscribers SET status=\'active\', confirmed_at=:now WHERE confirm_token_hash=:hash AND status=\'pending\' AND confirm_expires_at >= :now');
    $current = now();
    $stmt->execute([':now'=>$current, ':hash'=>$hash]);
    if ($stmt->rowCount() > 0) {
        $subStmt = $pdo->prepare('SELECT id, email FROM icdm_subscribers WHERE confirm_token_hash = :hash LIMIT 1');
        $subStmt->execute([':hash' => $hash]);
        $subscriber = $subStmt->fetch();
        if (is_array($subscriber)) {
            logEvent($pdo, (int)$subscriber['id'], 'subscribe_confirmed');
            $welcomeText = 'La tua iscrizione alla newsletter è confermata. Da ora riceverai aggiornamenti sul libro.';
            $unsubscribeUrl = buildUnsubscribeUrl($config['site'], (int)$subscriber['id'], (string)$subscriber['email']);
            $welcomeHtml = buildWelcomeEmailHtml($config['site'], $unsubscribeUrl);
            sendTextMail((string)$subscriber['email'], 'Benvenuto nella newsletter - Il Custode dei Miracoli', $welcomeText, $config['site'], $config['smtp'] ?? [], $welcomeHtml);
        }
    }
    echo $stmt->rowCount() ? 'Iscrizione confermata con successo.' : 'Token non valido o scaduto.';
    exit;
}

if ($action === 'unsubscribe_token' && isset($_GET['token'])) {
    $decoded = verifyUnsubscribeToken($config['site'], (string)$_GET['token']);
    if ($decoded === null) { echo 'Link di disiscrizione non valido.'; exit; }
    $stmt = $pdo->prepare('UPDATE icdm_subscribers SET status=\'unsubscribed\', unsubscribed_at=:now WHERE id=:id AND email=:email AND status=\'active\'');
    $stmt->execute([':now'=>now(), ':id'=>$decoded['id'], ':email'=>$decoded['email']]);
    if ($stmt->rowCount() > 0) {
        logEvent($pdo, (int)$decoded['id'], 'unsubscribe_confirmed', 'one-click');
    }
    echo 'Disiscrizione completata. Non riceverai più comunicazioni dalla newsletter.';
    exit;
}

if ($action === 'unsubscribe' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { header('Location: contatti.html?newsletter=invalid'); exit; }
    $stmt = $pdo->prepare('UPDATE icdm_subscribers SET status=\'unsubscribed\', unsubscribed_at=:now WHERE email=:email AND status = \'active\'');
    $stmt->execute([':now'=>now(), ':email'=>$email]);
    $subscriberId = findSubscriberId($pdo, $email);
    if ($subscriberId !== null) {
        logEvent($pdo, $subscriberId, 'unsubscribe_request');
        if ($stmt->rowCount() > 0) {
            logEvent($pdo, $subscriberId, 'unsubscribe_confirmed');
        }
    }
    if ($stmt->rowCount() > 0) {
        $goodbyeText = 'La tua disiscrizione dalla newsletter è confermata. Ci dispiace vederti andare via.';
        $goodbyeHtml = buildUnsubscribeEmailHtml($config['site']);
        sendTextMail($email, 'Disiscrizione confermata - Il Custode dei Miracoli', $goodbyeText, $config['site'], $config['smtp'] ?? [], $goodbyeHtml);
    }
    sendTextMail($config['site']['ops_email'], 'Disiscrizione newsletter', "Disiscrizione: $email", $config['site'], $config['smtp'] ?? [], null);
    header('Location: contatti.html?newsletter=unsubscribed'); exit;
}

http_response_code(400);
echo 'Richiesta non valida.';
