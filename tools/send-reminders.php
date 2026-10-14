<?php
// Sends the automatic payment reminders (in-app + email) for debts that have a due date. Run it every hour:
//   C:\xampp\php\php.exe tools\send-reminders.php            send what is due now
//   C:\xampp\php\php.exe tools\send-reminders.php --dry-run  only list what it would send
//   C:\xampp\php\php.exe tools\send-reminders.php --force    ignore the quiet hours (9 pm - 8 am)
// tools\schedule-reminders.cmd registers it with the Windows Task Scheduler. On a Linux host: 0 * * * * php tools/send-reminders.php
// Schedule per debt: 3 days before the due date, on the due date, then every 3 days while overdue, until it is paid.

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this script from the command line.');
}

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/domain.php';
require __DIR__ . '/../includes/reminders.php';

echo '[' . date('Y-m-d H:i') . "] Payment reminders\n";
if (!mail_configured()) {
    echo "Note: SMTP is not set up (SMTP_USER / SMTP_PASS in config/local.php), so only in-app reminders are sent.\n";
}
foreach (run_automatic_reminders(in_array('--dry-run', $argv, true), in_array('--force', $argv, true)) as $line) {
    echo $line . "\n";
}
