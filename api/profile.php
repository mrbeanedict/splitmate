<?php
// My profile: picture, name, payment details, personal "Pay me" QR code, page backdrop, password.
require __DIR__ . '/../includes/api.php';
require __DIR__ . '/../includes/uploads.php';

$me = api_user();

const USERNAME_CHANGES = 5;      // changes allowed in a row
const USERNAME_WAIT_DAYS = 7;     // then this long before the next one
const PAYMENT_METHODS = ['GCash', 'Maya', 'Bank Transfer', 'Cash'];

function profile_of(int $userId): array
{
    $u = q('SELECT id, full_name, email, email_reminders, username, username_changes, username_locked_until, payment_method, payment_account, currency, pay_code, avatar_color, avatar, featured_achievement, bg_mode, bg_style, bg_image, google_sub FROM users WHERE id = ?', [$userId])->fetch();
    if ($u['pay_code'] === null) {
        // Safety net: every account has its own Pay-me code.
        $u['pay_code'] = new_pay_code();
        q('UPDATE users SET pay_code = ? WHERE id = ?', [$u['pay_code'], $userId]);
    }
    return public_user($u) + [
        'email'           => $u['email'],
        'username'        => $u['username'],
        // Username changes: 5, then a 7-day wait. "left" counts what remains before the wait; locked_until is set during it.
        'username_locked_until' => $u['username_locked_until'] && strtotime($u['username_locked_until']) > time() ? $u['username_locked_until'] : null,
        'username_changes_left' => $u['username_locked_until'] && strtotime($u['username_locked_until']) <= time() ? USERNAME_CHANGES : max(0, USERNAME_CHANGES - (int) $u['username_changes']),
        'payment_method'  => $u['payment_method'],
        'payment_account' => $u['payment_account'],
        'currency'        => $u['currency'],
        'pay_code'        => $u['pay_code'],
        'email_reminders' => (bool) $u['email_reminders'],
        'google_linked'   => $u['google_sub'] !== null,
        'avatar'          => $u['avatar'], // 'pfp/<file>', 'up/<file>' or null: which picker choice is selected
        'background'      => user_background($u),
    ];
}

/** Replace (or with null, remove) my backdrop photo; the old file is deleted. Without a photo, 'photo' mode falls back to badges. */
function set_background_image(int $userId, ?string $file): void
{
    $old = q('SELECT bg_image FROM users WHERE id = ?', [$userId])->fetchColumn() ?: null;
    q("UPDATE users SET bg_image = ?, bg_mode = IF(? IS NOT NULL, 'photo', IF(bg_mode = 'photo', 'badges', bg_mode)) WHERE id = ?", [$file, $file, $userId]);
    if ($old && $old !== $file) {
        delete_upload('backgrounds', $old);
    }
}

/** Replace my picture; a gallery photo I'm moving away from is deleted. */
function set_avatar(int $userId, ?string $avatar): void
{
    $old = (string) q('SELECT avatar FROM users WHERE id = ?', [$userId])->fetchColumn();
    q('UPDATE users SET avatar = ? WHERE id = ?', [$avatar, $userId]);
    if (str_starts_with($old, 'up/') && $old !== $avatar) {
        delete_upload('avatars', substr($old, 3));
    }
}

/**
 * Money owed to me (by person, unfinished installments flagged) and what I owe. An installment is a debt being paid
 * in parts or on an installment plan with interest, not finished yet.
 */
function money_stats(int $userId): array
{
    // Debts in other currencies count at today's market rate, in my own currency ('estimated' says when that happened)
    $home = user_currency($userId);
    $estimated = false;
    $inHome = function ($amount, string $cur) use ($home, &$estimated): int {
        if ($cur === $home) {
            return cents($amount);
        }
        $estimated = true;
        return fx_estimate(cents($amount), $cur, $home)['cents'] ?? 0;
    };
    $rows = q(
        "SELECT u.id, u.full_name, u.avatar_color, u.role, s.currency,
                SUM(s.amount - s.paid_amount) AS owed,
                SUM(s.paid_amount > 0 OR s.interest_rate IS NOT NULL) AS installments,
                SUM(CASE WHEN s.paid_amount > 0 OR s.interest_rate IS NOT NULL THEN s.amount - s.paid_amount ELSE 0 END) AS installment_owed
         FROM settlements s JOIN users u ON u.id = s.from_user_id
         WHERE s.to_user_id = ? AND s.status <> 'settled' GROUP BY u.id, s.currency",
        [$userId]
    )->fetchAll();
    $people = [];
    foreach ($rows as $r) {
        $p = &$people[(int) $r['id']];
        $p ??= $r + ['owed_c' => 0, 'inst_c' => 0, 'n' => 0];
        $p['owed_c'] += $inHome($r['owed'], $r['currency']);
        $p['inst_c'] += $inHome($r['installment_owed'], $r['currency']);
        $p['n'] += (int) $r['installments'];
        unset($p);
    }
    usort($people, fn ($a, $b) => $b['owed_c'] <=> $a['owed_c']);
    $iOwe = 0;
    foreach (q("SELECT currency, SUM(amount - paid_amount) AS left_ FROM settlements WHERE from_user_id = ? AND status <> 'settled' GROUP BY currency", [$userId]) as $r) {
        $iOwe += $inHome($r['left_'], $r['currency']);
    }
    return [
        'currency'         => $home,
        'estimated'        => $estimated,
        'owed_to_me'       => pesos(array_sum(array_column($people, 'owed_c'))),
        'installment_owed' => pesos(array_sum(array_column($people, 'inst_c'))),
        'installments'     => (int) array_sum(array_column($people, 'n')),
        'i_owe'            => pesos($iOwe),
        'people'           => array_map(fn ($p) => public_user($p) + [
            'owed'             => pesos($p['owed_c']),
            'installments'     => $p['n'],
            'installment_owed' => pesos($p['inst_c']),
        ], $people),
    ];
}

if (method() === 'GET') {
    if (isset($_GET['avatars'])) {
        // Just what the picture picker needs (first-run page)
        json_ok(['profile' => profile_of($me['id']), 'presets' => avatar_preset_list()]);
    }
    json_ok(['profile' => profile_of($me['id']), 'money' => money_stats($me['id']), 'presets' => avatar_preset_list()]);
}

/** The preset pictures for the picker: [{ key: 'pfp/<file>', url }]. */
function avatar_preset_list(): array
{
    return array_map(fn ($f) => ['key' => 'pfp/' . $f, 'url' => url('assets/pfp/' . rawurlencode($f))], avatar_presets());
}

if (method() !== 'POST') {
    fail('Method not allowed.', 405);
}

switch (input('action', '')) {
    case 'update':
        $name = str_input('full_name', 100);
        $method = str_input('payment_method', 30);
        $account = str_input('payment_account', 60, false);
        $cur = input('currency') ? currency_param() : user_currency($me['id']);
        $errors = array_filter([
            'full_name'       => valid_name($name, 'Full name'),
            'payment_method'  => in_array($method, PAYMENT_METHODS, true) ? '' : 'Choose a payment method.',
            'payment_account' => match ($method) {
                'Cash'  => '',
                'GCash' => valid_gcash($account),
                default => valid_account($account),
            },
        ]);
        if ($errors) {
            fail(reset($errors), 422, ['fields' => $errors]);
        }
        q('UPDATE users SET full_name = ?, payment_method = ?, payment_account = ?, currency = ? WHERE id = ?',
            [trim($name), $method, $method === 'Cash' ? null : (trim($account) ?: null), $cur, $me['id']]);
        json_ok(['profile' => profile_of($me['id'])]);

    case 'username':
        // Change my username (what friends can sign in or search with). Lowercase, 3–20 characters, unique.
        // 5 changes in a row, then a 7-day wait; once the wait is over the count starts again.
        $row = q('SELECT username, username_changes, username_locked_until FROM users WHERE id = ?', [$me['id']])->fetch();
        $changes = (int) $row['username_changes'];
        if ($row['username_locked_until']) {
            if (strtotime($row['username_locked_until']) > time()) {
                $when = date('M j, g:i A', strtotime($row['username_locked_until']));
                fail("You've used all " . USERNAME_CHANGES . " username changes. You can change it again on $when.", 429, ['fields' => ['username' => "You can change your username again on $when."]]);
            }
            $changes = 0; // the wait is over
        }
        $username = strtolower(trim(str_input('username', 20)));
        $err = valid_username($username);
        if (!$err && strcasecmp($username, (string) q('SELECT username FROM users WHERE id = ?', [$me['id']])->fetchColumn()) !== 0 && username_taken($username)) {
            $err = 'That username is taken.';
        }
        if ($err) {
            fail($err, 422, ['fields' => ['username' => $err], 'suggestions' => str_contains($err, 'taken') ? suggest_usernames($username) : []]);
        }
        if ($username === (string) $row['username']) {
            fail('That is already your username.', 422, ['fields' => ['username' => 'That is already your username.']]);
        }
        $changes++;
        q('UPDATE users SET username = ?, username_changes = ?, username_locked_until = ' . ($changes >= USERNAME_CHANGES ? 'NOW() + INTERVAL ' . USERNAME_WAIT_DAYS . ' DAY' : 'NULL') . ' WHERE id = ?', [$username, $changes, $me['id']]);
        json_ok(['profile' => profile_of($me['id'])]);

    case 'email_reminders':
        // Payment reminder emails on or off (the in-app reminders always arrive)
        q('UPDATE users SET email_reminders = ? WHERE id = ?', [input('on') ? 1 : 0, $me['id']]);
        json_ok(['profile' => profile_of($me['id'])]);

    case 'set_currency':
        // My home currency (first-run page and Profile). Bills I pay from now on settle in it; existing bills keep theirs.
        q('UPDATE users SET currency = ? WHERE id = ?', [currency_param(), $me['id']]);
        json_ok(['profile' => profile_of($me['id'])]);

    case 'set_avatar':
        // key: a preset ('pfp/<file>'), or '' to go back to initials
        $key = (string) input('key', '');
        if ($key !== '' && !(str_starts_with($key, 'pfp/') && in_array(substr($key, 4), avatar_presets(), true))) {
            fail('Pick one of the pictures shown.', 422);
        }
        set_avatar($me['id'], $key ?: null);
        json_ok(['profile' => profile_of($me['id'])]);

    case 'upload_avatar':
        // A photo from the phone's gallery, already cropped square and shrunk by the page (assets/js/ui.js)
        $file = save_uploaded_image('photo', 'avatars', 'u' . $me['id']);
        set_avatar($me['id'], 'up/' . $file);
        json_ok(['profile' => profile_of($me['id'])]);

    case 'set_background':
        // mode: 'calm' (soft backdrops; optional style, see CALM_STYLES), 'badges' (floating badges), 'plain', or 'photo' (needs a photo uploaded first)
        $mode = (string) input('mode', '');
        if (!in_array($mode, ['calm', 'badges', 'plain', 'photo'], true)) {
            fail('Pick a background.', 422);
        }
        if ($mode === 'photo' && !q('SELECT bg_image FROM users WHERE id = ?', [$me['id']])->fetchColumn()) {
            fail('Upload a photo first.', 422);
        }
        $style = input('style', null);
        if ($style !== null && !in_array($style, CALM_STYLES, true)) {
            fail('Pick a style.', 422);
        }
        q('UPDATE users SET bg_mode = ?, bg_style = COALESCE(?, bg_style) WHERE id = ?', [$mode, $style, $me['id']]);
        json_ok(['profile' => profile_of($me['id'])]);

    case 'upload_background':
        // A photo from the gallery, already shrunk by the page (assets/js/ui.js fitPhoto); it becomes the backdrop.
        set_background_image($me['id'], save_uploaded_image('photo', 'backgrounds', 'b' . $me['id']));
        json_ok(['profile' => profile_of($me['id'])]);

    case 'remove_background':
        set_background_image($me['id'], null);
        json_ok(['profile' => profile_of($me['id'])]);

    case 'rotate_pay_code':
        // New QR; anyone holding the old one can no longer open your pay page.
        q('UPDATE users SET pay_code = ? WHERE id = ?', [new_pay_code(), $me['id']]);
        json_ok(['profile' => profile_of($me['id'])]);

    case 'onboarded':
        // Finished or skipped the dashboard tour (assets/js/tour.js): don't show it again on its own.
        q('UPDATE users SET onboarded_at = COALESCE(onboarded_at, NOW()) WHERE id = ?', [$me['id']]);
        json_ok();

    case 'password':
        $hash = (string) q('SELECT password_hash FROM users WHERE id = ?', [$me['id']])->fetchColumn();
        if (!password_verify((string) input('current', ''), $hash)) {
            fail('Your current password is incorrect.', 422, ['fields' => ['current' => 'Incorrect password.']]);
        }
        $new = (string) input('new', '');
        if ($e = valid_password($new)) {
            fail($e, 422, ['fields' => ['new' => $e]]);
        }
        if ($new !== (string) input('confirm', '')) {
            fail("Passwords don't match.", 422, ['fields' => ['confirm' => "Passwords don't match."]]);
        }
        if (password_verify($new, $hash)) {
            fail('Choose a password different from your current one.', 422, ['fields' => ['new' => 'Choose a different password.']]);
        }
        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $me['id']]);
        json_ok();
}

fail('Unknown action.', 404);
