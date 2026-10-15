<?php
// Brings an existing Setlo database up to date without losing data. Safe to run repeatedly.
// Run from the project root:  C:\xampp\php\php.exe database\migrate.php
// (The web app also does this by itself when it notices the database is behind: see ensure_schema() in includes/bootstrap.php.)

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this script from the command line.');
}

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/migrations.php';

run_migrations();
