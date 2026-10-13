<?php
require __DIR__ . '/../includes/api.php';
require __DIR__ . '/../includes/receipts.php';
require_once __DIR__ . '/../includes/games.php';

$me = api_user();

/** Add a name-only guest (no account) to a bill. The Bill Creator settles on their behalf. */
function add_guest(int $billId, string $name): void
{
    $name = trim($name);
    require_valid(valid_name($name, 'Guest name'), 'name');
    $palette = ['#94a3b8', '#a78bfa', '#fb923c', '#34d399', '#f472b6', '#60a5fa'];
    q(
        "INSERT INTO users (full_name, email, password_hash, role, avatar_color) VALUES (?, NULL, '', 'guest', ?)",
        [$name, $palette[array_rand($palette)]]
    );
    q('INSERT INTO bill_members (bill_id, user_id) VALUES (?, ?)', [$billId, (int) db()->lastInsertId()]);
}

/**
 * The creator may delete a bill, or take back its settlement plan, until money has moved on it. After that it's
 * a record of real payments, so it stays (it can be archived once closed).
 */
function require_undoable(array $bill): void
{
    if ($bill['kind'] === 'loan') {
        fail('This is an utang, not a bill — manage it on the Utang page.', 409);
    }
    if (in_array($bill['status'], ['settling', 'closed'], true) && bill_money_moved($bill['id'])) {
        fail('Someone has already paid on this bill, so it can’t be undone or deleted — it’s kept as a record of those payments. '
            . ($bill['status'] === 'closed' ? 'You can archive it instead.' : 'Once it’s closed you can archive it.'), 409);
    }
}

/**
 * Change the bill's agreed settlement currency. Amounts typed in the old one (actual amount charged, manual rate,
 * fees, several payers' amounts) no longer apply, so they're cleared; today's market rate is fetched for the new pair.
 */
function set_bill_currency(array $bill, string $cur, array $me, string $why): void
{
    if ($bill['currency'] === $cur) {
        return;
    }
    q("UPDATE bills SET currency = ?, fx_mode = 'market', fx_actual_total = NULL, fx_manual_rate = NULL, fx_fees = 0, fx_fees_note = NULL, fx_set_at = NULL WHERE id = ?", [$cur, $bill['id']]);
    q('UPDATE bill_payments SET amount = 0 WHERE bill_id = ?', [$bill['id']]);
    fx_capture_market($bill['id'], $me['id'], "Settlement currency changed from {$bill['currency']} to $cur ($why).");
}

/** The bill's members with an account (guests can't be in a saved group). */
function bill_account_ids(int $billId): array
{
    return array_map('intval', q("SELECT u.id FROM bill_members m JOIN users u ON u.id = m.user_id WHERE m.bill_id = ? AND u.role = 'user' ORDER BY u.id", [$billId])->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Whether to offer saving the bill's people as a group (asked on Review after a scan): the creator's own bill, not
 * from a group yet, with at least one other account on it, and no group of mine with exactly these people.
 */
function group_suggestion(array $bill, array $me): bool
{
    if ($bill['creator_id'] !== $me['id'] || $bill['group_id'] || $bill['kind'] !== 'bill' || !in_array($bill['status'], ['draft', 'active'], true)) {
        return false;
    }
    $ids = bill_account_ids($bill['id']);
    if (count($ids) < 2) {
        return false;
    }
    foreach (q('SELECT g.id FROM user_groups g JOIN user_group_members m ON m.group_id = g.id WHERE m.user_id = ?', [$me['id']])->fetchAll(PDO::FETCH_COLUMN) as $gid) {
        $members = array_map('intval', q('SELECT user_id FROM user_group_members WHERE group_id = ? ORDER BY user_id', [$gid])->fetchAll(PDO::FETCH_COLUMN));
        if ($members === $ids) {
            return false;
        }
    }
    return true;
}

/** Invite codes: 12 chars from an unambiguous alphabet (no 0/O, 1/l/I). */
function new_invite_code(): string
{
    $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
    do {
        $code = '';
        for ($i = 0; $i < 12; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
    } while (q('SELECT 1 FROM bills WHERE invite_code = ?', [$code])->fetchColumn());
    return $code;
}

function bill_by_invite(string $code): array
{
    if (!preg_match('/^[A-Za-z0-9]{12}$/', $code)) {
        fail('This invite link is invalid.', 404);
    }
    $bill = q('SELECT b.*, u.full_name AS creator_name FROM bills b JOIN users u ON u.id = b.creator_id WHERE b.invite_code = BINARY ?', [$code])->fetch();
    if (!$bill) {
        fail('This invite link is invalid or has been turned off.', 404);
    }
    $bill['id'] = (int) $bill['id'];
    return $bill;
}

// ---------- Reads ----------
if (method() === 'GET') {
    if (isset($_GET['invite'])) {
        // Preview for the join page.
        $bill = bill_by_invite((string) $_GET['invite']);
        json_ok([
            'bill' => [
                'id'        => $bill['id'],
                'name'      => $bill['name'],
                'creator'   => $bill['creator_name'],
                'status'    => $bill['status'],
                'locked'    => in_array($bill['status'], ['settling', 'closed'], true),
                'created_at'=> $bill['created_at'],
            ],
            'members'   => bill_members($bill['id']),
            'is_member' => is_member($bill['id'], $me['id']),
        ]);
    }
    if (isset($_GET['id'])) {
        $bill = bill_for(int_param('id'), $me);
        $members = bill_members($bill['id']);
        $items = bill_items($bill['id']);
        $calc = compute_shares($bill, $members, $items);
        $plan = settlement_plan($bill, $members, $calc);
        $creator = $bill['creator_id'] === $me['id'];
        $receipts = bill_receipts($bill['id']);

        json_ok([
            'bill' => [
                'id'             => $bill['id'],
                'name'           => $bill['name'],
                'kind'           => $bill['kind'],
                'status'         => $bill['status'],
                'creator_id'     => $bill['creator_id'],
                'group_id'       => $bill['group_id'] === null ? null : (int) $bill['group_id'],
                'payer_id'       => $bill['payer_id'],
                'tax'            => (float) $bill['tax'],
                'tax_included'   => $calc['tax_included'],
                'service_charge' => (float) $bill['service_charge'],
                'discount'       => (float) $bill['discount'],
                'split_mode'     => $calc['split_mode'],
                'percent_total'  => $calc['percent_total'] === null ? null : $calc['percent_total'] / 100,
                'interest_rate'  => $bill['interest_rate'] === null ? null : (float) $bill['interest_rate'],
                'invite_code'    => $creator ? $bill['invite_code'] : null,
                'receipt_total'  => $bill['receipt_total'] === null ? null : (float) $bill['receipt_total'],
                // "Round to whole pesos": total is rounded, exact_total is what was really charged
                'round_total'    => (bool) $bill['round_total'],
                'exact_total'    => pesos($calc['exact_total']),
                'round_diff'     => pesos($calc['round_diff']),
                // Agreed settlement currency, the receipt's own, and how one becomes the other (includes/currency.php)
                'currency'         => $calc['currency'],
                'receipt_currency' => $calc['receipt_currency'],
                'fx'               => fx_payload($calc['fx']) + ['rounding_options' => FX_ROUNDING],
                'has_receipt'    => (bool) $bill['receipt_image'] || $receipts,
                'ocr_status'     => $bill['ocr_status'],
                'created_at'     => $bill['created_at'],
                'settling_at'    => $bill['settling_at'],
                'closed_at'      => $bill['closed_at'],
                'locked'         => in_array($bill['status'], ['settling', 'closed'], true),
                // Settling, but nobody has paid anything yet: the creator can still take it back or delete it.
                'money_moved'    => $bill['status'] !== 'draft' && $bill['status'] !== 'active' && bill_money_moved($bill['id']),
                'archived'       => (bool) q('SELECT archived_at FROM bill_members WHERE bill_id = ? AND user_id = ?', [$bill['id'], $me['id']])->fetchColumn(),
                // Fun Mode: the group's game decides the split (pages/game.php)
                'fun_mode'       => bill_fun_mode($bill),
                // Offer to save these people as a group (with Fun Mode) — Review asks once after a scan
                'group_suggest'  => group_suggestion($bill, $me),
                'game_pending'   => game_pending($bill),
                'game'           => ($g = current_game($bill['id'])) && $g['status'] === 'done' && $g['game'] !== 'skip'
                    ? ['game' => $g['game'], 'title' => GAMES[$g['game']]['emoji'] . ' ' . GAMES[$g['game']]['title'], 'result' => $g['state']['result'] ?? null]
                    : ($g && $g['status'] !== 'done' ? ['game' => $g['game'], 'live' => true] : null),
            ],
            'me'          => ['id' => $me['id'], 'is_creator' => $creator, 'is_payer' => $bill['payer_id'] === $me['id'], 'currency' => user_currency($me['id'])],
            'members'     => $members,
            'items'       => $items,
            'receipts'    => $receipts,
            // shares / total / balances / plan / payments: settlement currency. Items, subtotal, extras, discounts,
            // unassigned and native_*: the receipt's currency.
            'shares'      => array_map('pesos', $calc['shares']),
            'native_shares' => array_map('pesos', $calc['native_shares']),
            'native_total'  => pesos($calc['native_total']),
            'fx_log'      => $calc['fx']['foreign'] || $bill['receipt_currency'] ? fx_log_rows($bill['id']) : [],
            // Each member's share in their own currency when it isn't the bill's: today's estimate, before settling
            'share_estimates' => in_array($bill['status'], ['draft', 'active'], true) ? array_filter(array_map(function ($m) use ($calc) {
                $est = $m['currency'] !== $calc['currency'] ? fx_estimate($calc['shares'][$m['id']] ?? 0, $calc['currency'], $m['currency']) : null;
                return $est ? ['currency' => $m['currency'], 'amount' => pesos($est['cents']), 'at' => $est['at'], 'source' => $est['source']] : null;
            }, array_column($members, null, 'id'))) : (object) [],
            'subtotal'    => pesos($calc['subtotal']),
            'extras'      => pesos($calc['extras']),
            'discount_by' => array_map('pesos', $calc['discount_by']),
            'total'       => pesos($calc['total']),
            'unassigned'  => pesos($calc['unassigned']),
            'unassigned_count' => $calc['unassigned_count'],
            'settlements' => bill_settlements($bill['id']),
            // Who paid the restaurant and the proposed transfers (before settling starts).
            'payments'    => [
                'multi'    => (bool) q('SELECT 1 FROM bill_payments WHERE bill_id = ? LIMIT 1', [$bill['id']])->fetchColumn(),
                'payer_ids' => array_map('intval', q('SELECT user_id FROM bill_payments WHERE bill_id = ? ORDER BY amount DESC, user_id', [$bill['id']])->fetchAll(PDO::FETCH_COLUMN)) ?: [$bill['payer_id']],
                'paid'     => array_map('pesos', $plan['paid']),
                'total'    => pesos($plan['paid_total']),
                'mismatch' => pesos($plan['mismatch']),
                'change'   => pesos($plan['change']),
                // Who physically got the change: named by the creator, or null until they are (a guess is not shown)
                'change_holder' => $plan['holder_named'] ? $plan['holder'] : null,
                // Per member: paid − share, the change due back to them (+) or what they still have to put in (−)
                'expected' => array_map('pesos', $plan['expected']),
            ],
            'balances'    => array_map('pesos', $plan['balances']),
            'plan'        => array_map(fn ($t) => ['from' => $t[0], 'to' => $t[1], 'amount' => pesos($t[2])], $plan['transfers']),
        ]);
    }

    // all / history: what you haven't archived · archived: only what you have
    $scope = $_GET['scope'] ?? 'all';
    $where = match ($scope) {
        'history'  => "AND b.status = 'closed' AND m.archived_at IS NULL",
        'archived' => 'AND m.archived_at IS NOT NULL',
        default    => 'AND m.archived_at IS NULL',
    };
    $rows = q(
        "SELECT b.* FROM bills b JOIN bill_members m ON m.bill_id = b.id
         WHERE m.user_id = ? AND b.kind = 'bill' $where
         ORDER BY FIELD(b.status, 'active', 'draft', 'settling', 'closed'), COALESCE(b.closed_at, b.created_at) DESC",
        [$me['id']]
    )->fetchAll();
    $archived = (int) q("SELECT COUNT(*) FROM bill_members m JOIN bills b ON b.id = m.bill_id WHERE m.user_id = ? AND b.kind = 'bill' AND m.archived_at IS NOT NULL", [$me['id']])->fetchColumn();

    json_ok(['bills' => array_map(fn ($b) => bill_card($b) + ['link' => bill_link($b, $me['id'])], $rows), 'archived_count' => $archived]);
}

// ---------- Writes ----------
if (method() !== 'POST') {
    fail('Method not allowed.', 405);
}

$action = input('action', '');

if ($action === 'create') {
    $name = trim(str_input('name', 120));
    require_valid(valid_bill_name($name), 'name');
    $memberIds = array_unique(array_map('intval', (array) input('member_ids', [])));
    $memberIds = array_values(array_diff($memberIds, [$me['id']]));
    // Who paid the restaurant: payer_ids (one or several); the older single payer_id still works.
    $payerIds = array_map('intval', (array) input('payer_ids', [input('payer_id', $me['id'])]));

    if ($memberIds) {
        $in = implode(',', array_fill(0, count($memberIds), '?'));
        $valid = q("SELECT id FROM users WHERE role = 'user' AND status = 'active' AND id IN ($in)", $memberIds)->fetchAll(PDO::FETCH_COLUMN);
        $memberIds = array_map('intval', $valid);
    }
    $guestNames = array_values(array_filter(array_map(fn ($g) => trim((string) $g), array_slice((array) input('guest_names', []), 0, 20)), 'strlen'));
    if (!$memberIds && !$guestNames) {
        fail('Add at least one member or guest to split with.', 422, ['fields' => ['people' => 'Add at least one member or guest.']]);
    }
    $all = array_merge([$me['id']], $memberIds);
    // Real members only (guests can't confirm payments), in the order they were chosen; nobody valid = the creator.
    $payerIds = array_values(array_unique(array_filter($payerIds, fn ($id) => in_array($id, $all, true))));
    if (!$payerIds) {
        $payerIds = [$me['id']];
    }
    // With several payers each may state how much they put in (0 = fill in later). One payer needs no amount.
    $contrib = [];
    if (count($payerIds) > 1) {
        $raw = input('payments', []);
        foreach ($payerIds as $uid) {
            $amount = is_array($raw) && is_numeric($raw[$uid] ?? null) ? round((float) $raw[$uid], 2) : 0.0;
            $contrib[$uid] = $amount > 0 && $amount <= 1000000 ? $amount : 0.0;
        }
    }
    $payerId = in_array($me['id'], $payerIds, true) ? $me['id'] : $payerIds[0];
    if ($contrib && max($contrib) > 0) {
        // The biggest contributor is the bill's main payer (same rule as "set_payments").
        arsort($contrib);
        $payerId = array_key_first($contrib);
    }

    $pdo = db();
    $pdo->beginTransaction();
    $groupId = (int) input('group_id', 0);
    if ($groupId && !q('SELECT 1 FROM user_group_members WHERE group_id = ? AND user_id = ?', [$groupId, $me['id']])->fetchColumn()) {
        $groupId = 0;
    }
    // Settles in the payer's currency unless the group agrees on another (Bill Detail → Currency)
    q('INSERT INTO bills (name, creator_id, payer_id, group_id, currency) VALUES (?, ?, ?, ?, ?)', [$name, $me['id'], $payerId, $groupId ?: null, user_currency($payerId)]);
    $billId = (int) $pdo->lastInsertId();
    if (count($payerIds) > 1) {
        // Several payers: record who paid and what they put in (blank = still to fill in). The bill can't start
        // settling until these add up to the receipt total.
        foreach ($payerIds as $uid) {
            q('INSERT INTO bill_payments (bill_id, user_id, amount) VALUES (?, ?, ?)', [$billId, $uid, $contrib[$uid]]);
        }
    }
    foreach ($all as $uid) {
        q('INSERT INTO bill_members (bill_id, user_id) VALUES (?, ?)', [$billId, $uid]);
        if ($uid !== $me['id']) {
            notify($uid, 'added', first_name($me['full_name']) . " added you to $name.", 'bill-items?bill=' . $billId);
        }
    }
    foreach ($guestNames as $guest) {
        add_guest($billId, $guest);
    }
    $pdo->commit();
    json_ok(['id' => $billId, 'redirect' => 'scan-receipt?bill=' . $billId]);
}

if ($action === 'join') {
    $bill = bill_by_invite(str_input('code', 20));
    if (is_member($bill['id'], $me['id'])) {
        json_ok(['redirect' => 'bill-items?bill=' . $bill['id']]);
    }
    if (in_array($bill['status'], ['settling', 'closed'], true)) {
        fail('This bill is already settling, so new members can’t join.', 409);
    }
    q('INSERT INTO bill_members (bill_id, user_id) VALUES (?, ?)', [$bill['id'], $me['id']]);
    notify((int) $bill['creator_id'], 'added', first_name($me['full_name']) . " joined {$bill['name']} using your invite link.", 'bill-detail?bill=' . $bill['id']);
    json_ok(['redirect' => 'bill-items?bill=' . $bill['id']]);
}

$bill = bill_for(int_param('bill_id'), $me);

switch ($action) {
    case 'invite':
        // enable: create a link if none; rotate: replace it (old link stops working); disable: turn it off.
        require_creator($bill, $me);
        require_editable($bill);
        $mode = input('mode', 'enable');
        $code = match ($mode) {
            'enable'  => $bill['invite_code'] ?: new_invite_code(),
            'rotate'  => new_invite_code(),
            'disable' => null,
            default   => fail('Unknown invite mode.', 422),
        };
        q('UPDATE bills SET invite_code = ? WHERE id = ?', [$code, $bill['id']]);
        json_ok(['invite_code' => $code]);

    case 'guest_link':
        // A private link for a guest member: opens their share of this bill without an account (pages/guest.php).
        // Works at any stage of the bill, so they can follow it through settlement.
        require_creator($bill, $me);
        $uid = int_param('user_id');
        $row = q("SELECT m.view_token FROM bill_members m JOIN users u ON u.id = m.user_id WHERE m.bill_id = ? AND m.user_id = ? AND u.role = 'guest'", [$bill['id'], $uid])->fetch();
        if (!$row) {
            fail('Only guests added by name get a link.', 422);
        }
        $token = $row['view_token'] ?: new_guest_token();
        if (!$row['view_token']) {
            q('UPDATE bill_members SET view_token = ? WHERE bill_id = ? AND user_id = ?', [$token, $bill['id'], $uid]);
        }
        json_ok(['token' => $token, 'link' => url('pages/guest?t=' . $token)]);

    case 'guest_link_revoke':
        require_creator($bill, $me);
        q('UPDATE bill_members SET view_token = NULL WHERE bill_id = ? AND user_id = ?', [$bill['id'], int_param('user_id')]);
        json_ok();

    case 'set_discount_type':
        require_creator($bill, $me);
        require_editable($bill);
        $uid = int_param('user_id');
        $type = input('type', 'none');
        if (!in_array($type, ['none', 'senior', 'pwd'], true)) {
            fail('Unknown discount type.', 422);
        }
        if (!is_member($bill['id'], $uid)) {
            fail('Not a member of this bill.', 422);
        }
        q('UPDATE bill_members SET discount_type = ? WHERE bill_id = ? AND user_id = ?', [$type, $bill['id'], $uid]);
        json_ok(['members' => bill_members($bill['id'])]);
    case 'add_member':
        require_creator($bill, $me);
        require_editable($bill);
        $uid = int_param('user_id');
        $ok = q("SELECT 1 FROM users WHERE id = ? AND role = 'user' AND status = 'active'", [$uid])->fetchColumn();
        if (!$ok) {
            fail('User not found.', 404);
        }
        q('INSERT IGNORE INTO bill_members (bill_id, user_id) VALUES (?, ?)', [$bill['id'], $uid]);
        notify($uid, 'added', first_name($me['full_name']) . " added you to {$bill['name']}.", 'bill-items?bill=' . $bill['id']);
        json_ok(['members' => bill_members($bill['id'])]);

    case 'add_guest':
        require_creator($bill, $me);
        require_editable($bill);
        add_guest($bill['id'], (string) input('name', ''));
        json_ok(['members' => bill_members($bill['id'])]);

    case 'remove_member':
        require_creator($bill, $me);
        require_editable($bill);
        $uid = int_param('user_id');
        if ($uid === $bill['creator_id'] || $uid === $bill['payer_id']
            || q('SELECT 1 FROM bill_payments WHERE bill_id = ? AND user_id = ?', [$bill['id'], $uid])->fetchColumn()) {
            fail("The creator and anyone who paid the restaurant can't be removed.", 422);
        }
        q('DELETE a FROM item_assignments a JOIN receipt_items i ON i.id = a.item_id WHERE i.bill_id = ? AND a.user_id = ?', [$bill['id'], $uid]);
        q('DELETE FROM bill_members WHERE bill_id = ? AND user_id = ?', [$bill['id'], $uid]);
        // A guest only exists for this bill, so drop the placeholder account too.
        q("DELETE FROM users WHERE id = ? AND role = 'guest' AND NOT EXISTS (SELECT 1 FROM bill_members WHERE user_id = ?)", [$uid, $uid]);
        json_ok(['members' => bill_members($bill['id'])]);

    case 'set_payments':
        // payments: { user_id: amount }. One entry (or none) = a single payer who covered everything.
        require_creator($bill, $me);
        require_editable($bill);
        $raw = input('payments', []);
        if (!is_array($raw)) {
            fail('Invalid payments.', 422);
        }
        $payments = [];
        foreach ($raw as $uid => $amount) {
            $uid = (int) $uid;
            $amount = round((float) $amount, 2);
            if ($amount <= 0) {
                continue;
            }
            if (!is_member($bill['id'], $uid)) {
                fail('Payers must be members of the bill.', 422);
            }
            if (q('SELECT role FROM users WHERE id = ?', [$uid])->fetchColumn() === 'guest') {
                fail('A guest can’t be a payer — they have no account to confirm payments.', 422);
            }
            $payments[$uid] = $amount;
        }
        if (!$payments) {
            fail('Enter how much at least one person paid.', 422);
        }
        arsort($payments);
        // change_holder_id (optional): who physically received the change, when these come to more than the bill
        $holder = input('change_holder_id');
        if ($holder !== null && $holder !== '' && (int) $holder && !is_member($bill['id'], (int) $holder)) {
            fail('Pick someone on this bill as the one who got the change.', 422);
        }
        $pdo = db();
        $pdo->beginTransaction();
        q('DELETE FROM bill_payments WHERE bill_id = ?', [$bill['id']]);
        q('UPDATE bills SET payer_id = ? WHERE id = ?', [array_key_first($payments), $bill['id']]);
        if ($holder !== null) {
            q('UPDATE bills SET change_holder_id = ? WHERE id = ?', [(int) $holder ?: null, $bill['id']]);
        }
        // These amounts were typed in the bill's currency, so it stays put even if the main payer changed.
        if (count($payments) > 1) {
            foreach ($payments as $uid => $amount) {
                q('INSERT INTO bill_payments (bill_id, user_id, amount) VALUES (?, ?, ?)', [$bill['id'], $uid, $amount]);
            }
        }
        $pdo->commit();
        json_ok();

    case 'set_change_holder':
        // Who physically received the change (any member), or none (user_id 0).
        require_creator($bill, $me);
        require_editable($bill);
        $uid = (int) input('user_id', 0);
        if ($uid && !is_member($bill['id'], $uid)) {
            fail('Pick someone on this bill.', 422);
        }
        q('UPDATE bills SET change_holder_id = ? WHERE id = ?', [$uid ?: null, $bill['id']]);
        json_ok();

    case 'set_round_total':
        // Round the total (and every share) to whole pesos, or back to the exact amount.
        require_creator($bill, $me);
        require_editable($bill);
        q('UPDATE bills SET round_total = ? WHERE id = ?', [in_array(input('on'), [true, 1, '1'], true) ? 1 : 0, $bill['id']]);
        json_ok();

    case 'set_payer':
        require_creator($bill, $me);
        require_editable($bill);
        $uid = int_param('user_id');
        if (!is_member($bill['id'], $uid)) {
            fail('The payer must be a member of the bill.', 422);
        }
        if (q("SELECT role FROM users WHERE id = ?", [$uid])->fetchColumn() === 'guest') {
            fail('A guest can’t be the payer — they have no account to confirm payments.', 422);
        }
        $pdo = db();
        $pdo->beginTransaction();
        q('DELETE FROM bill_payments WHERE bill_id = ?', [$bill['id']]);
        // One payer covered exactly the total: there's no change to hold
        q('UPDATE bills SET payer_id = ?, change_holder_id = NULL WHERE id = ?', [$uid, $bill['id']]);
        // The agreed currency follows the person who paid, unless the creator sends keep_currency
        if ($uid !== $bill['payer_id'] && !input('keep_currency')) {
            set_bill_currency($bill, user_currency($uid), $me, 'the payer’s currency');
        }
        $pdo->commit();
        json_ok();

    case 'set_currency':
        // The group agrees to settle in another currency than the payer's.
        require_creator($bill, $me);
        require_editable($bill);
        $pdo = db();
        $pdo->beginTransaction();
        set_bill_currency($bill, currency_param(), $me, 'agreed by the group');
        $pdo->commit();
        json_ok();

    case 'set_fx':
        // How the receipt's currency becomes the settlement currency: market rate (estimate), the actual amount
        // charged, or a typed rate; plus fees recorded separately and the rounding policy. Receipts stay untouched.
        require_creator($bill, $me);
        require_editable($bill);
        $mode = input('mode', 'market');
        if (!in_array($mode, ['market', 'actual', 'manual'], true)) {
            fail('Unknown rate type.', 422);
        }
        $num = function (string $k, float $max) {
            $v = input($k);
            return $v === null || $v === '' ? null : (is_numeric($v) && (float) $v >= 0 && (float) $v <= $max ? (float) $v : fail('Check the ' . str_replace('_', ' ', $k) . '.', 422, ['fields' => [$k => 'Enter a valid amount.']]));
        };
        $actual = $num('actual_total', 99999999);
        $manual = $num('manual_rate', 1000000);
        $fees = $num('fees', 9999999) ?? 0.0;
        $rounding = input('rounding', 'exact');
        if (!isset(FX_ROUNDING[$rounding])) {
            fail('Unknown rounding.', 422);
        }
        if ($mode === 'actual' && !$actual) {
            fail('Enter the amount that was actually charged.', 422, ['fields' => ['actual_total' => 'Enter the amount charged.']]);
        }
        if ($mode === 'manual' && !$manual) {
            fail('Enter the exchange rate.', 422, ['fields' => ['manual_rate' => 'Enter a rate above 0.']]);
        }
        $feesNote = trim(preg_replace('/\s+/', ' ', str_input('fees_note', 80, false)));
        if (preg_match('/[<>]/', $feesNote)) {
            fail('The fee note can’t contain < or >.', 422);
        }
        $pdo = db();
        $pdo->beginTransaction();
        q('UPDATE bills SET fx_mode = ?, fx_actual_total = ?, fx_manual_rate = ?, fx_fees = ?, fx_fees_note = ?, fx_rounding = ?, fx_set_at = NOW() WHERE id = ?',
            [$mode, $actual === null ? null : round($actual, 2), $manual === null ? null : round($manual, 10), round($fees, 2), $feesNote ?: null, $rounding, $bill['id']]);
        $words = ['market' => 'the market rate', 'actual' => 'the actual amount charged', 'manual' => 'a rate entered by hand'][$mode];
        fx_log($bill['id'], $me['id'], first_name($me['full_name']) . " set the settlement rate to $words.");
        $pdo->commit();
        json_ok();

    case 'refresh_rate':
        require_creator($bill, $me);
        require_editable($bill);
        $ok = fx_capture_market($bill['id'], $me['id'], first_name($me['full_name']) . ' refreshed the market rate.');
        if (!$ok) {
            fail('Couldn’t get a market rate right now. Enter what was actually charged, or a rate, instead.', 502);
        }
        json_ok();

    case 'update_details':
        // Step 1 again (Details, from Scan / Review / Assign): the Create Bill form, edited. Same fields as
        // 'create'; people left out are taken off the bill (with their item assignments), new ones are added.
        require_creator($bill, $me);
        require_editable($bill);
        $name = trim(str_input('name', 120));
        require_valid(valid_bill_name($name), 'name');
        $wanted = array_values(array_diff(array_unique(array_map('intval', (array) input('member_ids', []))), [$me['id']]));
        if ($wanted) {
            $in = implode(',', array_fill(0, count($wanted), '?'));
            $wanted = array_map('intval', q("SELECT id FROM users WHERE role = 'user' AND status = 'active' AND id IN ($in)", $wanted)->fetchAll(PDO::FETCH_COLUMN));
        }
        $guestCount = count(array_filter(array_map(fn ($g) => trim((string) $g), (array) input('guest_names', [])), 'strlen'));
        if (!$wanted && !$guestCount) {
            fail('Add at least one member or guest to split with.', 422, ['fields' => ['people' => 'Add at least one member or guest.']]);
        }
        $accounts = array_merge([$me['id']], $wanted);
        $payerIds = array_values(array_unique(array_filter(array_map('intval', (array) input('payer_ids', [$me['id']])), fn ($id) => in_array($id, $accounts, true)))) ?: [$me['id']];
        $contrib = [];
        if (count($payerIds) > 1) {
            // The amounts are entered on Review now: keep what's there unless this request sends new ones
            $raw = input('payments') ?? q('SELECT user_id, amount FROM bill_payments WHERE bill_id = ?', [$bill['id']])->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ($payerIds as $uid) {
                $amount = is_array($raw) && is_numeric($raw[$uid] ?? null) ? round((float) $raw[$uid], 2) : 0.0;
                $contrib[$uid] = $amount > 0 && $amount <= 1000000 ? $amount : 0.0;
            }
        }
        $payerId = in_array($bill['payer_id'], $payerIds, true) ? $bill['payer_id'] : (in_array($me['id'], $payerIds, true) ? $me['id'] : $payerIds[0]);
        if ($contrib && max($contrib) > 0) {
            arsort($contrib);
            $payerId = array_key_first($contrib); // the biggest contributor is the main payer, as on create
        }
        if ($payerId !== $bill['payer_id'] && user_currency($payerId) !== $bill['currency'] && input('payments') === null) {
            $contrib = array_map(fn () => 0.0, $contrib); // kept amounts were in the old currency: enter them again on Review
        }
        $groupId = (int) input('group_id', 0);
        if ($groupId && !q('SELECT 1 FROM user_group_members WHERE group_id = ? AND user_id = ?', [$groupId, $me['id']])->fetchColumn()) {
            $groupId = 0;
        }

        $pdo = db();
        $pdo->beginTransaction();
        $current = q('SELECT u.id, u.role, u.full_name FROM bill_members m JOIN users u ON u.id = m.user_id WHERE m.bill_id = ?', [$bill['id']])->fetchAll();
        $drop = function (int $uid) use ($bill) {
            q('DELETE a FROM item_assignments a JOIN receipt_items i ON i.id = a.item_id WHERE i.bill_id = ? AND a.user_id = ?', [$bill['id'], $uid]);
            q('DELETE FROM bill_members WHERE bill_id = ? AND user_id = ?', [$bill['id'], $uid]);
            q("DELETE FROM users WHERE id = ? AND role = 'guest' AND NOT EXISTS (SELECT 1 FROM bill_members WHERE user_id = ?)", [$uid, $uid]);
        };
        // Accounts: take off who was left out, add who's new
        $have = [];
        foreach ($current as $m) {
            if ($m['role'] === 'guest') {
                continue;
            }
            $have[] = (int) $m['id'];
            if (!in_array((int) $m['id'], $accounts, true)) {
                $drop((int) $m['id']);
            }
        }
        foreach (array_diff($accounts, $have) as $uid) {
            q('INSERT INTO bill_members (bill_id, user_id) VALUES (?, ?)', [$bill['id'], $uid]);
            notify($uid, 'added', first_name($me['full_name']) . " added you to $name.", 'bill-items?bill=' . $bill['id']);
        }
        // Guests (matched by name): keep the ones still listed, remove the rest, add new names
        $names = array_map(fn ($g) => trim((string) $g), array_slice((array) input('guest_names', []), 0, 20));
        foreach ($current as $m) {
            if ($m['role'] !== 'guest') {
                continue;
            }
            $i = array_search($m['full_name'], $names, true);
            if ($i === false) {
                $drop((int) $m['id']);
            } else {
                array_splice($names, $i, 1);
            }
        }
        foreach ($names as $g) {
            add_guest($bill['id'], $g);
        }
        // Who paid. A new main payer brings their currency first (that clears amounts typed in the old one), then
        // the amounts from this form are saved.
        q('UPDATE bills SET name = ?, payer_id = ?, group_id = ? WHERE id = ?', [$name, $payerId, $groupId ?: null, $bill['id']]);
        if ($payerId !== $bill['payer_id']) {
            set_bill_currency($bill, user_currency($payerId), $me, 'the payer’s currency');
        }
        q('DELETE FROM bill_payments WHERE bill_id = ?', [$bill['id']]);
        if (count($payerIds) > 1) {
            foreach ($payerIds as $uid) {
                q('INSERT INTO bill_payments (bill_id, user_id, amount) VALUES (?, ?, ?)', [$bill['id'], $uid, $contrib[$uid]]);
            }
        }
        $pdo->commit();
        $scanned = (bool) q('SELECT 1 FROM receipts WHERE bill_id = ? UNION SELECT 1 FROM receipt_items WHERE bill_id = ? LIMIT 1', [$bill['id'], $bill['id']])->fetchColumn();
        json_ok(['redirect' => ($scanned ? 'review-items?bill=' : 'scan-receipt?bill=') . $bill['id']]);

    case 'save_as_group':
        // Make the bill's people (accounts only) a saved group, start this bill from it, and optionally turn on
        // Fun Mode so this and later bills offer a game after the review.
        require_creator($bill, $me);
        require_editable($bill);
        if ($bill['group_id']) {
            fail('This bill is already part of a group.', 409);
        }
        $name = trim(preg_replace('/\s+/', ' ', str_input('name', 60)));
        if (mb_strlen($name) < 2 || preg_match('/[<>]/', $name)) {
            fail('Give the group a name (2–60 characters, no < or >).', 422, ['fields' => ['name' => 'Name it in 2–60 characters.']]);
        }
        $ids = bill_account_ids($bill['id']);
        if (count($ids) < 2) {
            fail('Add at least one other person with a Setlo account first.', 422);
        }
        $fun = input('fun_mode') ? 1 : 0;
        $pdo = db();
        $pdo->beginTransaction();
        q('INSERT INTO user_groups (name, owner_id, fun_mode) VALUES (?, ?, ?)', [$name, $me['id'], $fun]);
        $groupId = (int) $pdo->lastInsertId();
        foreach ($ids as $uid) {
            q('INSERT INTO user_group_members (group_id, user_id) VALUES (?, ?)', [$groupId, $uid]);
            if ($uid !== $me['id']) {
                notify($uid, 'added', first_name($me['full_name']) . " made the group “{$name}” from {$bill['name']}" . ($fun ? ' — with Fun Mode on 🎉' : '') . '.', 'group?id=' . $groupId);
            }
        }
        q('UPDATE bills SET group_id = ? WHERE id = ?', [$groupId, $bill['id']]);
        $pdo->commit();
        json_ok(['group_id' => $groupId, 'fun_mode' => (bool) $fun]);

    case 'set_split_mode':
        // items: each item split among who shared it · percent: each member pays a set % of the whole bill
        require_creator($bill, $me);
        require_editable($bill);
        $mode = input('mode', 'items');
        if (!in_array($mode, ['items', 'percent'], true)) {
            fail('Unknown split mode.', 422);
        }
        if ($bill['split_mode'] === 'game') {
            clear_game_result($bill); // "Split normally instead" after a Fun Mode game
        }
        q('UPDATE bills SET split_mode = ? WHERE id = ?', [$mode, $bill['id']]);
        if ($mode === 'percent' && !q('SELECT 1 FROM bill_members WHERE bill_id = ? AND percent IS NOT NULL LIMIT 1', [$bill['id']])->fetchColumn()) {
            // First switch: start from an even split, leftover hundredths to the first members.
            $ids = array_column(bill_members($bill['id']), 'id');
            foreach (split_evenly(10000, $ids) as $uid => $bp) {
                q('UPDATE bill_members SET percent = ? WHERE bill_id = ? AND user_id = ?', [$bp / 100, $bill['id'], $uid]);
            }
        }
        json_ok(['members' => bill_members($bill['id'])]);

    case 'set_percents':
        // percents: { user_id: percent }. Saved as typed (they may not add up to 100 yet); settling checks the total.
        require_creator($bill, $me);
        require_editable($bill);
        $raw = input('percents', []);
        if (!is_array($raw)) {
            fail('Invalid percentages.', 422);
        }
        $memberIds = array_column(bill_members($bill['id']), 'id');
        $pdo = db();
        $pdo->beginTransaction();
        foreach ($raw as $uid => $pct) {
            $uid = (int) $uid;
            if (!in_array($uid, $memberIds, true)) {
                fail('Not a member of this bill.', 422);
            }
            $pct = $pct === null || $pct === '' ? null : round((float) $pct, 2);
            if ($pct !== null && ($pct < 0 || $pct > 100)) {
                fail('Each percentage must be between 0 and 100.', 422);
            }
            q('UPDATE bill_members SET percent = ? WHERE bill_id = ? AND user_id = ?', [$pct, $bill['id'], $uid]);
        }
        $pdo->commit();
        json_ok(['members' => bill_members($bill['id'])]);

    case 'start_settling':
        require_creator($bill, $me);
        require_editable($bill);
        // Installments with interest (optional): one of the fixed INSTALLMENT_RATES, added after each partial payment.
        start_settling($bill, $me, installment_rate(input('interest_rate')));
        json_ok(['redirect' => 'bill-detail?bill=' . $bill['id']]);

    case 'reopen':
        // Take back "Start settling" to fix a mistake (wrong split, missed item, wrong payer): the plan is removed
        // and the bill can be edited again. Only while nobody has paid anything on it.
        require_creator($bill, $me);
        require_undoable($bill);
        if (!in_array($bill['status'], ['settling', 'closed'], true)) {
            fail('This bill hasn’t started settling.', 409);
        }
        $others = array_values(array_diff(array_column(bill_members($bill['id']), 'id'), [$me['id']]));
        $pdo = db();
        $pdo->beginTransaction();
        q('DELETE FROM settlements WHERE bill_id = ?', [$bill['id']]);
        q("UPDATE bills SET status = 'active', settling_at = NULL, closed_at = NULL, interest_rate = NULL WHERE id = ?", [$bill['id']]);
        q('UPDATE bill_members SET archived_at = NULL WHERE bill_id = ?', [$bill['id']]);
        foreach ($others as $uid) {
            notify($uid, 'settling', first_name($me['full_name']) . " took back the settlement plan for {$bill['name']} to fix it — nothing to pay for now.", 'bill-items?bill=' . $bill['id']);
        }
        $pdo->commit();
        json_ok(['redirect' => 'assign-items?bill=' . $bill['id']]);

    case 'archive':
    case 'unarchive':
        // Just for me: a closed bill leaves my bill list and past settlements. Its records stay for everyone.
        if ($action === 'archive' && $bill['status'] !== 'closed') {
            fail('Only a closed bill can be archived — it closes once every payment is settled.', 409);
        }
        q('UPDATE bill_members SET archived_at = ' . ($action === 'archive' ? 'NOW()' : 'NULL') . ' WHERE bill_id = ? AND user_id = ?', [$bill['id'], $me['id']]);
        json_ok(['archived' => $action === 'archive']);

    case 'delete':
        require_creator($bill, $me);
        require_undoable($bill);
        if ($bill['status'] === 'settling' || $bill['status'] === 'closed') {
            foreach (array_diff(array_column(bill_members($bill['id']), 'id'), [$me['id']]) as $uid) {
                notify($uid, 'closed', first_name($me['full_name']) . " deleted {$bill['name']} — nothing to pay for it.", 'my-settlements');
            }
        }
        delete_upload('receipts', $bill['receipt_image']);
        $photos = q('SELECT p.image FROM receipt_photos p JOIN receipts r ON r.id = p.receipt_id WHERE r.bill_id = ?', [$bill['id']])->fetchAll(PDO::FETCH_COLUMN);
        foreach (q('SELECT proof_image FROM settlements WHERE bill_id = ?', [$bill['id']])->fetchAll(PDO::FETCH_COLUMN) as $proof) {
            delete_upload('proofs', $proof);
        }
        // Photos attached to payments (rows go with the bill, the files need removing here)
        foreach (q(
            'SELECT p.proof_image FROM settlement_payments p JOIN settlements s ON s.id = p.settlement_id WHERE s.bill_id = ? AND p.proof_image IS NOT NULL
             UNION ALL SELECT x.image FROM settlement_payment_proofs x JOIN settlement_payments p ON p.id = x.payment_id JOIN settlements s ON s.id = p.settlement_id WHERE s.bill_id = ?',
            [$bill['id'], $bill['id']]
        )->fetchAll(PDO::FETCH_COLUMN) as $proof) {
            delete_upload('proofs', $proof);
        }
        $guests = q("SELECT u.id FROM bill_members m JOIN users u ON u.id = m.user_id WHERE m.bill_id = ? AND u.role = 'guest'", [$bill['id']])->fetchAll(PDO::FETCH_COLUMN);
        q('DELETE FROM bills WHERE id = ?', [$bill['id']]);
        delete_unused_receipt_files($photos);
        foreach ($guests as $gid) {
            q("DELETE FROM users WHERE id = ? AND role = 'guest'", [$gid]);
        }
        json_ok(['redirect' => 'my-bills']);
}

fail('Unknown action.', 404);
