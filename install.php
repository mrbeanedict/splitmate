<?php
// One-time web installer for hosts without a terminal.
// 1. Upload Setlo, and create config/local.php with the database details and an INSTALL_KEY.
// 2. Open  https://YOUR-SITE/install.php?key=YOUR_INSTALL_KEY
// It shows whether this host can run Setlo (PHP, extensions, database, calls to Gemini/PayMongo/Google), then creates the
// tables and your admin account. After that it locks itself (config/installed.lock); delete this file from the server.
// It refuses to run without the key, and refuses to touch a database that already has Setlo tables.

declare(strict_types=1);

putenv('AUTO_MIGRATE=0'); // the empty database has nothing to migrate yet; the first normal page view after install does it
require __DIR__ . '/includes/bootstrap.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

const LOCK_FILE = __DIR__ . '/config/installed.lock';

$h = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

function page(string $title, string $body): void
{
    global $h;
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex"><title>' . $h($title) . ' · Setlo</title><style>'
        . 'body{font:15px/1.5 system-ui,sans-serif;background:#f1f5f5;color:#0f172a;margin:0;padding:24px}'
        . 'main{max-width:640px;margin:auto;background:#fff;border-radius:18px;padding:24px;box-shadow:0 8px 30px -12px rgba(15,23,42,.25)}'
        . 'h1{margin:0 0 4px;font-size:22px;color:#0d9488}h2{font-size:16px;margin:22px 0 8px}p{margin:8px 0}'
        . 'table{width:100%;border-collapse:collapse;font-size:14px}td{padding:6px 4px;border-bottom:1px solid #e8eeee;vertical-align:top}'
        . '.ok{color:#047857;font-weight:700}.bad{color:#be123c;font-weight:700}.warn{color:#b45309;font-weight:700}.muted{color:#64748b;font-size:13px}'
        . 'label{display:block;font-weight:700;margin:12px 0 4px}input{width:100%;box-sizing:border-box;padding:11px 12px;border:1.5px solid #cbd5e1;border-radius:12px;font:inherit}'
        . 'button{margin-top:18px;width:100%;padding:13px;border:0;border-radius:999px;background:#0d9488;color:#fff;font:700 15px system-ui;cursor:pointer}'
        . '.box{background:#fffbeb;color:#92400e;border-radius:12px;padding:10px 12px;margin:12px 0}.err{background:#fff1f2;color:#be123c;border-radius:12px;padding:10px 12px;margin:12px 0}'
        . '</style></head><body><main><h1>Setlo installer</h1>' . $body . '</main></body></html>';
    exit;
}

// ---------- Gates ----------
if (is_file(LOCK_FILE)) {
    page('Already installed', '<p class="ok">Setlo is already installed.</p><p>For safety, delete <b>install.php</b> from your server. '
        . 'To install again from scratch, you would also need to delete <b>config/installed.lock</b> and empty the database.</p>');
}

$local = is_file(__DIR__ . '/config/local.php') ? (array) require __DIR__ . '/config/local.php' : [];
$installKey = (string) (getenv('INSTALL_KEY') !== false && getenv('INSTALL_KEY') !== '' ? getenv('INSTALL_KEY') : ($local['INSTALL_KEY'] ?? ''));
if (strlen($installKey) < 16) {
    page('Set an install key', '<p class="bad">Not ready yet.</p><p>Add a long random <b>INSTALL_KEY</b> (16+ characters) to <b>config/local.php</b> on the server, for example:</p>'
        . '<p><code>\'INSTALL_KEY\' => \'' . $h(bin2hex(random_bytes(12))) . '\',</code></p>'
        . '<p>Then open <b>install.php?key=</b> followed by that key. The key keeps strangers from running the installer first.</p>');
}
$given = (string) ($_POST['key'] ?? $_GET['key'] ?? '');
if (!hash_equals($installKey, $given)) {
    http_response_code(403);
    page('Locked', '<p class="bad">Wrong or missing key.</p><p>Open <b>install.php?key=YOUR_INSTALL_KEY</b>, using the INSTALL_KEY from your config/local.php.</p>');
}

// ---------- Checks ----------
$checks = []; // [label, 'ok'|'warn'|'bad', detail]
$add = function (string $label, string $state, string $detail = '') use (&$checks) { $checks[] = [$label, $state, $detail]; };

$add('PHP version', version_compare(PHP_VERSION, '8.0.0', '>=') ? 'ok' : 'bad', PHP_VERSION . ' (needs 8.0 or newer)');
foreach (['pdo_mysql' => true, 'curl' => true, 'openssl' => true, 'mbstring' => true, 'gd' => false, 'exif' => false] as $ext => $required) {
    $has = extension_loaded($ext);
    $add("PHP extension: $ext", $has ? 'ok' : ($required ? 'bad' : 'warn'), $has ? 'available' : ($required ? 'missing: Setlo needs it' : 'missing: optional (photo duplicate detection / rotation)'));
}
$uploads = __DIR__ . '/uploads';
$add('Uploads folder writable', is_dir($uploads) && is_writable($uploads) ? 'ok' : 'bad', is_dir($uploads) ? ($uploads . (is_writable($uploads) ? '' : ' is not writable: set its permissions to 755/775')) : 'uploads/ is missing');
$add('Config folder writable (for the lock file)', is_writable(__DIR__ . '/config') ? 'ok' : 'warn', is_writable(__DIR__ . '/config') ? 'yes' : 'no: you will have to delete install.php yourself');
$add('Upload size limit', 'ok', 'upload_max_filesize ' . ini_get('upload_max_filesize') . ', post_max_size ' . ini_get('post_max_size') . ' (photos up to 8 MB are accepted)');

$dbOk = false;
$tablesExist = false;
try {
    $dbOk = (bool) db()->query('SELECT 1')->fetchColumn();
    $tablesExist = (bool) db()->query("SHOW TABLES LIKE 'users'")->fetchColumn();
    $add('Database connection', 'ok', 'connected to ' . config('db.name'));
} catch (Throwable $e) {
    // What the installer is actually trying (the password itself is never shown, only its length and oddities).
    $dc = config('db');
    $pw = (string) $dc['pass'];
    $oddities = array_filter([
        $pw === '' ? 'the password is EMPTY (is DB_PASS set in config/local.php?)' : '',
        $pw !== trim($pw) ? 'the password has a space at the start or end' : '',
        preg_match('/[^\x20-\x7E]/', $pw) ? 'the password has a non-standard character (smart quote or hidden symbol)' : '',
        strpbrk($pw, "'\"\\") !== false ? 'the password contains a quote or backslash' : '',
    ]);
    $tried = "host: {$dc['host']} | database: {$dc['name']} | user: {$dc['user']} | password length: " . strlen($pw) . ($oddities ? ' | WARNING: ' . implode('; ', $oddities) : '');
    $add('Database connection', 'bad', 'could not connect: ' . preg_replace('/\s+/', ' ', $e->getMessage()) . ' [Trying: ' . $tried . ']');
}

// Can PHP on this host call the outside services Setlo uses? Any HTTP answer (even 403/404) means the connection works.
$outbound = function (string $name, string $url, string $needFor) use ($add): void {
    if (!function_exists('curl_init')) {
        $add("Reach $name", 'bad', 'curl is missing');
        return;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_NOBODY => true, CURLOPT_TIMEOUT => 7, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_FOLLOWLOCATION => false]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $add("Reach $name", $code > 0 ? 'ok' : 'bad', $code > 0 ? "connected (HTTP $code)" : "blocked or unreachable ($err): $needFor won't work on this host");
};
$outbound('Gemini', 'https://generativelanguage.googleapis.com/', 'receipt scanning');
$outbound('PayMongo', 'https://api.paymongo.com/', '"Pay online"');
$outbound('Google sign-in', 'https://www.googleapis.com/', '"Continue with Google"');
$outbound('Groq', 'https://api.groq.com/', 'Setlo Buddy chat');
$fp = @fsockopen('smtp.gmail.com', 587, $errno, $errstr, 5);
$add('Email (SMTP port 587)', $fp ? 'ok' : 'warn', $fp ? 'open' : 'blocked: password-reset emails won\'t send (optional)');
if ($fp) {
    fclose($fp);
}

$hasBad = (bool) array_filter($checks, fn ($c) => $c[1] === 'bad');

// ---------- Install ----------
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $dbOk) {
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $pw = (string) ($_POST['password'] ?? '');
    if ($tablesExist) {
        $error = 'This database already has Setlo tables, so the installer will not touch it.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address for the admin account.';
    } elseif (strlen($pw) < 10 || $pw === 'admin12345' || !preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) {
        $error = 'The admin password needs 10+ characters with a letter and a number.';
    } elseif ($pw !== (string) ($_POST['password2'] ?? '')) {
        $error = 'The two passwords don\'t match.';
    } else {
        try {
            $sql = preg_replace('/(^|\s)-- .*$/m', '$1', (string) file_get_contents(__DIR__ . '/database/schema.sql'));
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
                if (preg_match('/^(CREATE\s+DATABASE|USE)\b/i', $stmt)) {
                    continue; // the host already created the database and we're connected to it
                }
                db()->exec($stmt);
            }
            q("INSERT INTO users (full_name, email, password_hash, role, avatar_color) VALUES ('App Manager', ?, ?, 'admin', '#0d9488')", [$email, password_hash($pw, PASSWORD_DEFAULT)]);
            $locked = @file_put_contents(LOCK_FILE, "installed " . date('c') . "\n") !== false;
            $base = rtrim((string) config('base_url'), '/');
            page('Installed', '<p class="ok">Done. Setlo is installed.</p>'
                . '<p>Admin account: <b>' . $h($email) . '</b></p>'
                . '<h2>Now</h2><ol><li><b>Delete install.php</b> from your server' . ($locked ? ' (it has locked itself, but remove it anyway)' : ' (it could not lock itself, so this matters)') . '.</li>'
                . '<li>Open <a href="' . $h($base) . '/pages/login">the app</a> and sign up as a normal user to try it.</li>'
                . '<li>The admin panel is at <a href="' . $h($base) . '/pages/admin-login">/pages/admin-login</a>.</li></ol>');
        } catch (Throwable $e) {
            $error = 'Setup stopped part-way: ' . preg_replace('/\s+/', ' ', $e->getMessage()) . ' Empty the database (drop all tables) before trying again.';
        }
    }
}

// ---------- Page ----------
$rows = '';
foreach ($checks as [$label, $state, $detail]) {
    $rows .= '<tr><td>' . $h($label) . '</td><td class="' . $state . '">' . ['ok' => 'OK', 'warn' => 'Note', 'bad' => 'Problem'][$state] . '</td><td class="muted">' . $h($detail) . '</td></tr>';
}
$body = '<p class="muted">Check this host, then create the tables and your admin account.</p><h2>System check</h2><table>' . $rows . '</table>';
if ($hasBad) {
    $body .= '<p class="err">Fix the "Problem" lines first (the optional notes are fine). A blocked service only turns that feature off.</p>';
}
if ($error) {
    $body .= '<p class="err">' . $h($error) . '</p>';
}
if ($tablesExist) {
    $body .= '<p class="box">This database already has Setlo tables. Nothing will be changed. If this is a fresh install gone wrong, drop all tables in phpMyAdmin and reload.</p>';
} elseif ($dbOk) {
    $body .= '<h2>Create the tables and the admin account</h2><form method="post"><input type="hidden" name="key" value="' . $h($given) . '">'
        . '<label for="e">Admin email</label><input id="e" name="email" type="email" required value="' . $h($_POST['email'] ?? '') . '">'
        . '<label for="p">Admin password (10+ characters, a letter and a number)</label><input id="p" name="password" type="password" required minlength="10" autocomplete="new-password">'
        . '<label for="p2">Type it again</label><input id="p2" name="password2" type="password" required minlength="10" autocomplete="new-password">'
        . '<button>Install Setlo</button></form>';
}
page('Installer', $body);
