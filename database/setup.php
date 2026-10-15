<?php
// Creates the `setlo` database, all tables, and the App Manager (admin) account. No sample users or bills:
// people sign up on the app.
// Run from the project root:  C:\xampp\php\php.exe database\setup.php
//
// On a host where the database already exists (shared hosting; you can't CREATE DATABASE there), run it with --hosted:
//   php database/setup.php --hosted           (asks you to choose the admin password)
//   ADMIN_PASSWORD='...' php database/setup.php --hosted
// --hosted connects to the database named in config/local.php, skips CREATE DATABASE / USE, and refuses the
// well-known default password, so a public server never gets "admin12345".
// WARNING: drops and recreates every Setlo table.

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this script from the command line.');
}

require __DIR__ . '/../includes/bootstrap.php';

$hosted = in_array('--hosted', $argv ?? [], true) || getenv('HOSTED') === '1';

/** Ask for a line on the terminal (hidden while typing where the system allows it). */
function ask(string $label): string
{
    echo $label;
    $hide = DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec') && function_exists('stream_isatty') && stream_isatty(STDIN);
    if ($hide) {
        shell_exec('stty -echo');
    }
    $line = trim((string) fgets(STDIN));
    if ($hide) {
        shell_exec('stty echo');
        echo "\n";
    }
    return $line;
}

// ---------- Admin account details (checked first, so a bad answer never drops any tables) ----------
$adminEmail = strtolower(trim((string) (getenv('ADMIN_EMAIL') ?: 'admin@setlo.app')));
$adminPassword = (string) (getenv('ADMIN_PASSWORD') ?: '');
if ($hosted) {
    if ($adminPassword === '') {
        $adminPassword = ask('Choose a password for the admin account (' . $adminEmail . ', 10+ characters): ');
        if ($adminPassword !== ask('Type it again: ')) {
            exit("The two passwords differ. Nothing was changed.\n");
        }
    }
    if (strlen($adminPassword) < 10 || $adminPassword === 'admin12345' || !preg_match('/[A-Za-z]/', $adminPassword) || !preg_match('/\d/', $adminPassword)) {
        exit("The admin password needs 10+ characters with a letter and a number, and can't be the default. Nothing was changed.\n");
    }
} elseif ($adminPassword === '') {
    $adminPassword = 'admin12345'; // local XAMPP convenience; change it after the first sign-in
}
if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
    exit("ADMIN_EMAIL isn't a valid email address. Nothing was changed.\n");
}

// ---------- Schema ----------
$c = config('db');
$dsn = "mysql:host={$c['host']};port={$c['port']};" . ($hosted ? "dbname={$c['name']};" : '') . "charset={$c['charset']}";
$root = new PDO($dsn, $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$sql = preg_replace('/(^|\s)-- .*$/m', '$1', file_get_contents(__DIR__ . '/schema.sql'));
foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
    if ($hosted && preg_match('/^(CREATE\s+DATABASE|USE)\b/i', $stmt)) {
        continue; // the host created the database; we're already connected to it
    }
    $root->exec($stmt);
}
echo "Schema created.\n";

// ---------- App Manager ----------
q("INSERT INTO users (full_name, email, password_hash, role, avatar_color) VALUES ('App Manager', ?, ?, 'admin', '#0d9488')",
    [$adminEmail, password_hash($adminPassword, PASSWORD_DEFAULT)]);

if ($hosted) {
    echo "\nDone. Admin account: $adminEmail (sign in on the admin page with the password you chose).\n";
} else {
    echo "\nSign up at http://localhost" . config('base_url') . "/pages/register\n";
    echo "  App Manager:  $adminEmail  (password: " . ($adminPassword === 'admin12345' ? 'admin12345 — change it after the first sign-in' : 'the one you set') . ")\n";
}
