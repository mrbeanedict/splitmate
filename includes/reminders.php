<?php
// Payment reminders: one message (in-app + email over SMTP) for a debt, used by the "Remind" button
// (api/settlements.php, action nudge) and by the scheduled job (tools/send-reminders.php).
//
// Kinds: manual     the person owed pressed Remind (to the debtor)
//        before_due / due_today / overdue   automatic, following the debt's due date (to the debtor)
//        due_set    the person owed set (or changed) the due date (to the debtor)
//        confirm    the debtor says they paid and asks the person owed to confirm (to the person owed)

declare(strict_types=1);

require_once __DIR__ . '/mail.php';

const REMINDER_BEFORE_DAYS = 3;     // first automatic reminder this many days before the due date
const REMINDER_REPEAT_DAYS = 3;     // once overdue, remind again this often until it is paid
const REMINDER_QUIET_FROM  = 21;    // no automatic reminders from 9 pm...
const REMINDER_QUIET_UNTIL = 8;     // ...until 8 am (server time)

/** The debt with everything a reminder needs, or null when it is gone. */
function reminder_debt(int $settlementId): ?array
{
    return q(
        'SELECT s.id, s.status, s.currency, s.amount, s.paid_amount, s.due_on, s.from_user_id, s.to_user_id, b.name AS bill_name,
                fu.full_name AS from_name, fu.email AS from_email, fu.role AS from_role, fu.email_reminders AS from_email_ok,
                tu.full_name AS to_name, tu.email AS to_email, tu.email_reminders AS to_email_ok, tu.payment_method, tu.payment_account, tu.pay_code
         FROM settlements s JOIN bills b ON b.id = s.bill_id
         JOIN users fu ON fu.id = s.from_user_id JOIN users tu ON tu.id = s.to_user_id
         WHERE s.id = ?',
        [$settlementId]
    )->fetch() ?: null;
}

/** Whole days from today to the due date (negative = overdue), or null when there is no due date. */
function reminder_days_to_due(?string $dueOn): ?int
{
    return $dueOn ? (int) round((strtotime($dueOn) - strtotime(date('Y-m-d'))) / 86400) : null;
}

/** "due Oct 20 (in 3 days)" / "due today" / "was due Oct 20 (3 days overdue)"; '' when there is no due date. */
function reminder_due_phrase(?string $dueOn): string
{
    $d = reminder_days_to_due($dueOn);
    if ($d === null) {
        return '';
    }
    $date = date('M j', strtotime($dueOn));
    if ($d === 0) {
        return 'due today';
    }
    $n = abs($d) . ' day' . (abs($d) === 1 ? '' : 's');
    return $d > 0 ? "due $date (in $n)" : "was due $date ($n overdue)";
}

/** An absolute address for an app page (email links). APP_URL wins; on the web it is this host; on the command line, XAMPP's localhost. */
function reminder_link(string $path): string
{
    $base = rtrim((string) config('app_url'), '/');
    if ($base === '' && !empty($_SERVER['HTTP_HOST'])) {
        $base = (is_https() ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . config('base_url');
    }
    if ($base === '') {
        $base = 'http://localhost/' . basename(dirname(__DIR__));
    }
    return $base . '/' . ltrim($path, '/');
}

/**
 * What a reminder says. Returns [target user id, in-app message, email subject, email lines, button label, button link].
 * $actor is the first name of whoever pressed the button (null for automatic ones).
 */
function reminder_content(array $d, string $kind, ?string $actor): array
{
    $left = money_str(max(0, cents($d['amount']) - cents($d['paid_amount'])), $d['currency']);
    $bill = $d['bill_name'];
    $creditor = first_name($d['to_name']);
    $due = reminder_due_phrase($d['due_on']);
    $payTo = $d['payment_method'] && $d['payment_method'] !== 'Cash' && $d['payment_account'] ? "{$d['payment_method']}: {$d['payment_account']}" : '';
    $payLink = $d['pay_code'] ? reminder_link('pages/pay?u=' . rawurlencode((string) $d['pay_code'])) : reminder_link('pages/my-settlements');

    if ($kind === 'confirm') {
        $who = first_name($d['from_name']);
        return [
            (int) $d['to_user_id'],
            "$who is waiting for you to confirm their payment for $bill.",
            "$who is waiting for you to confirm a payment",
            ["$who says they paid you for <b>" . h($bill) . '</b> and is waiting for you to confirm it.'],
            'Review the payment',
            reminder_link('pages/my-settlements?tab=owed'),
        ];
    }

    [$headline, $app] = match ($kind) {
        'before_due' => ["Reminder: $left to $creditor is $due", "Heads up: $left for $bill is $due."],
        'due_today'  => ["$left to $creditor is due today", "$left for $bill is due today. Pay $creditor."],
        'overdue'    => ["Overdue: $left to $creditor", "$left for $bill to $creditor $due."],
        'due_set'    => ["$creditor set a due date: $left is $due", "$creditor set a due date: $left for $bill is $due."],
        default      => ["$actor sent you a payment reminder", ($actor ?: $creditor) . " sent a reminder: you owe $left for $bill" . ($due ? ", $due" : '') . '.'],
    };
    $lines = [
        'You owe <b>' . h($creditor) . '</b> <b>' . h($left) . '</b> for <b>' . h($bill) . '</b>' . ($due ? ' — ' . h($due) : '') . '.',
    ];
    if ($payTo) {
        $lines[] = 'Pay ' . h($creditor) . ' via ' . h($payTo) . ', then mark it paid in Setlo.';
    }
    return [(int) $d['from_user_id'], $app, $headline, $lines, "Pay $creditor", $payLink];
}

/** [html, text] for one reminder email, in the same look as the password-reset email. */
function reminder_email(string $name, array $lines, string $button, string $link): array
{
    $n = h($name);
    $body = '';
    foreach ($lines as $l) {
        $body .= "<p style=\"font-size:15px;line-height:1.5;margin:0 0 12px\">$l</p>";
    }
    $html = '<div style="font-family:Arial,sans-serif;max-width:480px;margin:auto;color:#1e293b">'
        . '<h2 style="color:#0d9488;margin:0 0 12px">setlo</h2>'
        . "<p>Hi $n,</p>$body"
        . '<p style="margin:20px 0"><a href="' . h($link) . '" style="background:#0d9488;color:#fff;text-decoration:none;font-weight:bold;padding:12px 22px;border-radius:12px;display:inline-block">' . h($button) . '</a></p>'
        . '<p style="font-size:12px;color:#64748b">You get these because someone on Setlo is owed money. '
        . 'You can turn reminder emails off in Profile.</p></div>';
    $text = "Hi $name,\n\n" . html_entity_decode(strip_tags(implode("\n\n", $lines)), ENT_QUOTES) . "\n\n$button: $link\n\n"
        . "You can turn reminder emails off in Profile.\n";
    return [$html, $text];
}

/**
 * Send one reminder: always in-app, plus an email when SMTP is set up, the person has an address and has not opted out.
 * Returns ['sent' => bool (the debt exists and someone could be told), 'emailed' => bool, 'to' => first name].
 */
function send_settlement_reminder(int $settlementId, string $kind, ?string $actor = null): array
{
    $d = reminder_debt($settlementId);
    if (!$d) {
        return ['sent' => false, 'emailed' => false, 'to' => ''];
    }
    [$target, $app, $subject, $lines, $button, $link] = reminder_content($d, $kind, $actor);
    $toCreditor = $target === (int) $d['to_user_id'];
    $link_in_app = $toCreditor ? 'my-settlements?tab=owed' : 'my-settlements';
    notify($target, 'nudge', $app, $link_in_app);

    $email = $toCreditor ? $d['to_email'] : $d['from_email'];
    $ok = $toCreditor ? $d['to_email_ok'] : $d['from_email_ok'];
    $emailed = false;
    if ($email && (int) $ok === 1 && mail_configured()) {
        [$html, $text] = reminder_email(first_name($toCreditor ? $d['to_name'] : $d['from_name']), $lines, $button, $link);
        $emailed = send_mail((string) $email, $subject, $html, $text);
        if (!$emailed) {
            error_log('[setlo] reminder email to user ' . $target . ' failed: ' . mail_last_error());
        }
    }
    return ['sent' => true, 'emailed' => $emailed, 'to' => first_name($toCreditor ? $d['to_name'] : $d['from_name'])];
}

/** Which automatic reminder (if any) is due for this debt right now: 'before_due', 'due_today', 'overdue' or null. */
function reminder_auto_kind(array $d): ?string
{
    $days = reminder_days_to_due($d['due_on']);
    if ($days === null) {
        return null;
    }
    $last = $d['auto_reminded_at'] ? strtotime($d['auto_reminded_at']) : null;
    $dueTs = strtotime($d['due_on']);
    if ($days > 0 && $days <= REMINDER_BEFORE_DAYS) {
        // once, when the window opens (a missed day is caught up the next time the job runs)
        return $last === null || $last < $dueTs - REMINDER_BEFORE_DAYS * 86400 ? 'before_due' : null;
    }
    if ($days === 0) {
        return $last === null || $last < $dueTs ? 'due_today' : null;
    }
    if ($days < 0) {
        return $last === null || $last <= time() - REMINDER_REPEAT_DAYS * 86400 ? 'overdue' : null;
    }
    return null;
}

/**
 * The scheduled job: send every automatic reminder that is due. $dry only reports. $force ignores the quiet hours.
 * Returns the lines to print. Safe to run as often as you like; a debt is only reminded again when its schedule says so.
 */
function run_automatic_reminders(bool $dry = false, bool $force = false): array
{
    $out = [];
    $hour = (int) date('G');
    if (!$force && ($hour >= REMINDER_QUIET_FROM || $hour < REMINDER_QUIET_UNTIL)) {
        return ['Quiet hours (' . REMINDER_QUIET_FROM . ':00–' . REMINDER_QUIET_UNTIL . ':00): nothing sent. Use --force to ignore.'];
    }
    $rows = q(
        "SELECT s.id, s.due_on, s.auto_reminded_at FROM settlements s JOIN users fu ON fu.id = s.from_user_id
         WHERE s.status = 'pending' AND s.due_on IS NOT NULL AND s.amount > s.paid_amount AND fu.role <> 'guest'
         ORDER BY s.due_on, s.id"
    )->fetchAll();
    foreach ($rows as $r) {
        $kind = reminder_auto_kind($r);
        if ($kind === null) {
            continue;
        }
        if ($dry) {
            $out[] = "[dry run] settlement {$r['id']}: would send $kind (due {$r['due_on']})";
            continue;
        }
        $res = send_settlement_reminder((int) $r['id'], $kind);
        if ($res['sent']) {
            q('UPDATE settlements SET auto_reminded_at = NOW() WHERE id = ?', [$r['id']]);
            log_event((int) $r['id'], null, 'nudged', "Automatic reminder ($kind)" . ($res['emailed'] ? ', emailed.' : '.'));
        }
        $out[] = "settlement {$r['id']}: $kind to {$res['to']}" . ($res['emailed'] ? ' (email + app)' : ' (app only)');
    }
    return $out ?: ['Nothing to send.'];
}
