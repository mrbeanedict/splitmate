<?php
// Community rules: no profanity, hate speech or threats in posts and comments. An offensive post, comment or edit is
// refused before it is saved (so it never appears), and the author gets a strike:
//   strikes 1 and 2 → warning only (they stay signed in)
//   strike 3 → can't sign in for 1 day, strike 4 → 5 days, strike 5 → account permanently disabled.
// The offensive text itself is not kept anywhere; community_violations only records that a strike happened.
// The check is a word list (includes/assistant_filter.php), so it doesn't look at photos and can miss some abuse.

declare(strict_types=1);

require_once __DIR__ . '/assistant_filter.php';
require_once __DIR__ . '/mail.php';

const COMMUNITY_MAX_STRIKES = 5;                         // the last strike disables the account for good
const COMMUNITY_WARNINGS = 2;                            // strikes 1..2 are warnings only
const COMMUNITY_RESTRICT_DAYS = [3 => 1, 4 => 5];        // strike number => days the account can't sign in

/**
 * Refuse offensive text from $me: records the strike and throws an ApiError (403, code "violation") that the page
 * shows to the author. Returns quietly when the text is fine, or for admins. $kind: 'post', 'comment' or 'edit'.
 * @throws ApiError
 */
function community_check(array $me, string $kind, string ...$texts): void
{
    if (($me['role'] ?? '') === 'admin') {
        return;
    }
    $hit = null;
    foreach ($texts as $text) {
        if (trim($text) !== '' && ($hit = assistant_moderate($text)) !== null) {
            break;
        }
    }
    if ($hit === null) {
        return;
    }

    $pdo = db();
    $pdo->beginTransaction();
    $strike = (int) q('SELECT violation_count FROM users WHERE id = ? FOR UPDATE', [$me['id']])->fetchColumn() + 1;
    $permanent = $strike >= COMMUNITY_MAX_STRIKES;
    $days = COMMUNITY_RESTRICT_DAYS[$strike] ?? 0;
    if ($permanent) {
        q("UPDATE users SET violation_count = ?, status = 'suspended', restricted_until = NULL WHERE id = ?", [$strike, $me['id']]);
    } elseif ($days > 0) {
        q('UPDATE users SET violation_count = ?, restricted_until = DATE_ADD(NOW(), INTERVAL ? DAY) WHERE id = ?', [$strike, $days, $me['id']]);
    } else {
        q('UPDATE users SET violation_count = ? WHERE id = ?', [$strike, $me['id']]); // a warning: nothing else changes
    }
    $until = $days > 0 ? (string) q('SELECT restricted_until FROM users WHERE id = ?', [$me['id']])->fetchColumn() : null;
    q('INSERT INTO community_violations (user_id, kind, category, strike, restricted_until) VALUES (?, ?, ?, ?, ?)', [$me['id'], $kind, $hit['category'], $strike, $until]);
    $pdo->commit();

    $signedOut = $permanent || $days > 0;
    $message = community_violation_message($kind, $strike, $until);

    notify((int) $me['id'], 'community', mb_substr($message, 0, 295), null);
    if (!empty($me['email'])) {
        $what = $kind === 'comment' ? 'comment' : ($kind === 'edit' ? 'edit' : 'post');
        try {
            send_mail((string) $me['email'], 'Your Setlo ' . $what . ' was removed',
                '<p>' . htmlspecialchars($message, ENT_QUOTES) . '</p>', $message);
        } catch (Throwable $e) {
            error_log('[setlo] community strike email failed: ' . $e->getMessage());
        }
    }
    error_log("[setlo] community strike $strike user {$me['id']} ($kind, {$hit['category']})");

    fail($message, 403, ['code' => 'violation', 'strike' => $strike, 'permanent' => $permanent, 'signed_out' => $signedOut, 'until' => $until]);
}

/** What the author is told after strike $strike. $until: when a restriction ends (strikes 3 and 4). Never repeats their words. */
function community_violation_message(string $kind, int $strike, ?string $until): string
{
    $what = $kind === 'comment' ? 'comment' : ($kind === 'edit' ? 'edit' : 'post');
    $base = "Your $what was removed because it broke the community rules (no profanity, hate speech or threats). ";
    $when = $until ? date('F j, Y \a\t g:i A', strtotime($until)) : '';
    if ($strike === 1) {
        return $base . 'This is warning 1 of ' . COMMUNITY_WARNINGS . '. Nothing else happens to your account this time, but further violations will restrict it.';
    }
    if ($strike === 2) {
        return $base . 'This is your final warning. The next violation restricts your account for ' . COMMUNITY_RESTRICT_DAYS[3] . ' day.';
    }
    if ($strike >= COMMUNITY_MAX_STRIKES) {
        return $base . 'Strike ' . COMMUNITY_MAX_STRIKES . ' of ' . COMMUNITY_MAX_STRIKES . ': your account is now permanently disabled. To keep using Setlo you will need to create a new account with a different email.';
    }
    $next = $strike + 1 >= COMMUNITY_MAX_STRIKES ? 'permanently disabled' : 'restricted for ' . COMMUNITY_RESTRICT_DAYS[$strike + 1] . ' days';
    return $base . "Strike $strike of " . COMMUNITY_MAX_STRIKES . ": your account is restricted until $when and you will be signed out now. The next violation means it is $next.";
}

/** The sign-in message for an account under a community restriction, or null when it has none. $u: users row fields. */
function community_restriction_message(array $u): ?string
{
    if (($u['status'] ?? '') === 'suspended' && (int) ($u['violation_count'] ?? 0) >= COMMUNITY_MAX_STRIKES) {
        return 'This account was permanently disabled for repeated violations of the community rules. You can create a new account with a different email.';
    }
    if (!empty($u['restricted_until']) && strtotime((string) $u['restricted_until']) > 0 && (int) ($u['restricted'] ?? 0) === 1) {
        return 'Your account is restricted until ' . date('F j, Y \a\t g:i A', strtotime((string) $u['restricted_until'])) . ' because of offensive content in the community. You can sign in again after that.';
    }
    return null;
}
