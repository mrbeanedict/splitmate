<?php
// Bill, split and settlement rules shared by the API endpoints.
// Money is handled in integer centavos to avoid float rounding drift.

declare(strict_types=1);

require_once __DIR__ . '/payments.php'; // apply_credits(), used as new debts are created
require_once __DIR__ . '/achievements.php';
require_once __DIR__ . '/currency.php';

function cents($amount): int
{
    return (int) round(((float) $amount) * 100);
}

function pesos(int $cents): float
{
    return round($cents / 100, 2);
}

function peso_str(int $cents): string
{
    return '₱' . number_format($cents / 100, 2);
}

/** Load a bill the user belongs to; admins may read any bill. */
function bill_for(int $billId, array $user): array
{
    $bill = q('SELECT * FROM bills WHERE id = ?', [$billId])->fetch();
    if (!$bill) {
        fail('Bill not found.', 404);
    }
    if ($user['role'] !== 'admin' && !is_member($billId, $user['id'])) {
        fail('You are not a member of this bill.', 403);
    }
    foreach (['id', 'creator_id', 'payer_id'] as $k) {
        $bill[$k] = (int) $bill[$k];
    }
    $bill['change_holder_id'] = $bill['change_holder_id'] === null ? null : (int) $bill['change_holder_id'];
    return $bill;
}

function is_member(int $billId, int $userId): bool
{
    return (bool) q('SELECT 1 FROM bill_members WHERE bill_id = ? AND user_id = ?', [$billId, $userId])->fetchColumn();
}

function require_creator(array $bill, array $user): void
{
    if ($bill['creator_id'] !== $user['id']) {
        fail('Only the Bill Creator can do this.', 403);
    }
}

function require_editable(array $bill): void
{
    if (($bill['kind'] ?? 'bill') === 'loan') {
        fail('This is an utang, not a bill — manage it on the Utang page.', 409);
    }
    if (in_array($bill['status'], ['settling', 'closed'], true)) {
        fail('This bill is locked because settlement has started.', 409);
    }
}

function public_user(array $u): array
{
    return [
        'id'       => (int) $u['id'],
        'name'     => $u['full_name'],
        'first'    => first_name($u['full_name']),
        'initials' => initials($u['full_name']),
        'color'    => $u['avatar_color'],
        // Profile picture URL, or null for the initials (callers that didn't select users.avatar get it looked up)
        'photo'    => avatar_url(array_key_exists('avatar', $u) ? $u['avatar'] : user_avatar((int) $u['id']), (int) $u['id']),
        'is_guest' => ($u['role'] ?? '') === 'guest',
        'badge'    => featured_badge($u['featured_achievement'] ?? null),
    ];
}

function bill_members(int $billId): array
{
    $rows = q(
        'SELECT u.id, u.full_name, u.avatar_color, u.role, u.featured_achievement, u.payment_method, u.payment_account, u.currency, m.discount_type, m.percent, m.game_weight, m.game_share FROM bill_members m
         JOIN users u ON u.id = m.user_id WHERE m.bill_id = ? ORDER BY m.joined_at, u.id',
        [$billId]
    )->fetchAll();
    return array_map(fn ($u) => public_user($u) + [
        'currency'        => $u['currency'],
        'payment_method'  => $u['payment_method'],
        'payment_account' => $u['payment_account'],
        'discount_type'   => $u['discount_type'],
        'percent'         => $u['percent'] === null ? null : (float) $u['percent'],
        'game_weight'     => $u['game_weight'] === null ? null : (int) $u['game_weight'],
        'game_share'      => $u['game_share'] === null ? null : (int) $u['game_share'],
    ], $rows);
}

function bill_items(int $billId): array
{
    $items = q('SELECT * FROM receipt_items WHERE bill_id = ? ORDER BY position, id', [$billId])->fetchAll();
    $assign = [];
    foreach (q(
        'SELECT a.item_id, a.user_id FROM item_assignments a JOIN receipt_items i ON i.id = a.item_id WHERE i.bill_id = ? ORDER BY a.user_id',
        [$billId]
    ) as $row) {
        $assign[(int) $row['item_id']][] = (int) $row['user_id'];
    }
    return array_map(fn ($i) => [
        'id'            => (int) $i['id'],
        'receipt_id'    => $i['receipt_id'] === null ? null : (int) $i['receipt_id'],
        'name'          => $i['name'],
        'qty'           => (int) $i['qty'],
        'unit_price'    => (float) $i['unit_price'],
        'line_total'    => pesos((int) $i['qty'] * cents($i['unit_price'])),
        'promo'         => (float) ($i['promo'] ?? 0),
        'source'        => $i['source'],
        'ocr_name'      => $i['ocr_name'],
        'printed_name'  => $i['printed_name'],
        'details'       => $i['details'],
        'needs_review'  => (bool) $i['needs_review'],
        'suggestion'    => $i['suggestion'],
        'dup_note'      => $i['dup_note'],
        'was_corrected' => (bool) $i['was_corrected'],
        'who'           => $assign[(int) $i['id']] ?? [],
    ], $items);
}

/** Split $total cents across $userIds as evenly as possible; leftover centavos go to the lowest user ids. */
function split_evenly(int $total, array $userIds): array
{
    $n = count($userIds);
    if ($n === 0) {
        return [];
    }
    sort($userIds);
    $base = intdiv($total, $n);
    $rem = $total - $base * $n;
    $out = [];
    foreach (array_values($userIds) as $i => $uid) {
        $out[$uid] = $base + ($i < $rem ? 1 : 0);
    }
    return $out;
}

/**
 * Split $total cents in proportion to $weights (uid => cents). Floors each part, then hands the leftover
 * centavos one by one to the lowest user ids with a positive weight. Mirrored in assign-items.php.
 */
function split_proportional(int $total, array $weights): array
{
    ksort($weights);
    $sum = array_sum($weights);
    if ($total <= 0 || $sum <= 0) {
        return $total > 0 ? split_evenly($total, array_keys($weights)) : array_fill_keys(array_keys($weights), 0);
    }
    $out = [];
    $given = 0;
    foreach ($weights as $uid => $w) {
        $out[$uid] = intdiv($total * $w, $sum);
        $given += $out[$uid];
    }
    foreach ($weights as $uid => $w) {
        if ($given >= $total) {
            break;
        }
        if ($w > 0) {
            $out[$uid]++;
            $given++;
        }
    }
    return $out;
}

/**
 * Whether the tax is already inside the item prices (VAT-inclusive, the norm on PH receipts) rather than
 * added on top. Decided against the printed receipt total: if adding the tax puts the bill off from the
 * total but leaving it out matches (within ₱1 of per-unit rounding), the tax is only a breakdown line.
 * With no printed total there's nothing to check against, so the tax stays an extra.
 */
function tax_included(array $bill, int $subtotal): bool
{
    $tax = cents($bill['tax']);
    if ($tax <= 0 || $bill['receipt_total'] === null) {
        return false;
    }
    $printed = cents($bill['receipt_total']);
    $withoutTax = $subtotal + cents($bill['service_charge']) - cents($bill['discount'] ?? 0);
    $offWithout = abs($withoutTax - $printed);
    return $offWithout <= 100 && $offWithout < abs($withoutTax + $tax - $printed);
}

/**
 * Per-member share in the bill's settlement currency: worked out on the receipt amounts (compute_native_shares()),
 * then converted at the bill's settlement rate with any recorded fees and the rounding policy (convert_calc()).
 * subtotal / extras / discount / discount_by / unassigned stay in the receipt currency; shares and total are in
 * calc['currency']; native_shares and native_total are the same before conversion.
 */
function compute_shares(array $bill, array $members, array $items): array
{
    return convert_calc($bill, compute_native_shares($bill, $members, $items));
}

/**
 * Receipt-currency shares → settlement currency. The converted total (market or manual rate × receipt total, or the
 * actual amount charged) is shared in proportion to each member's receipt share (largest remainder, so it adds up
 * exactly); anything not yet assigned keeps its part aside. Recorded fees are shared equally, then rounding applies.
 * Mirrored by Setlo.convertShares() in assets/js/ui.js.
 */
function convert_calc(array $bill, array $calc): array
{
    $fx = bill_fx($bill, $calc['total']);
    $calc += ['currency' => $fx['currency'], 'receipt_currency' => $fx['receipt_currency'], 'native_total' => $calc['total'],
              'native_shares' => $calc['shares'], 'fx' => $fx, 'fx_missing' => $fx['missing']];
    if (!$fx['foreign']) {
        $calc['shares'] = round_shares($calc['shares'], 'exact', $fx['currency'], (int) $bill['payer_id']);
        return round_to_whole($bill, $calc);
    }
    $ids = array_keys($calc['shares']);
    // uid 0 holds what isn't shared out yet (unassigned items, percentages short of 100), so it isn't spread on the others
    $weights = $calc['shares'] + [0 => $calc['total'] - array_sum($calc['shares'])];
    $shares = $calc['total'] > 0 ? allocate($fx['converted'], $weights) : array_fill_keys(array_keys($weights), 0);
    unset($shares[0]);
    foreach (split_evenly($fx['fees'], $ids) as $uid => $c) {
        $shares[$uid] += $c;
    }
    $calc['shares'] = round_shares($shares, $fx['rounding'], $fx['currency'], (int) $bill['payer_id']);
    $calc['total'] = $fx['converted'] + $fx['fees'];
    return round_to_whole($bill, $calc);
}

/**
 * "Round to whole pesos" (bills.round_total): the total goes to the nearest whole unit, and once everything is shared
 * out every share becomes whole units too, in proportion and adding up to the rounded total (largest remainder).
 * exact_total keeps what was really charged; round_diff (rounded − exact) is the payer's to keep or cover, see
 * settlement_plan(). Mirrored by Setlo.convertShares() in assets/js/ui.js.
 */
function round_to_whole(array $bill, array $calc): array
{
    $calc['exact_total'] = $calc['total'];
    $calc['round_diff'] = 0;
    if (empty($bill['round_total']) || currency_decimals($calc['currency']) === 0) {
        return $calc;
    }
    $rounded = floor_div($calc['total'] + 50, 100) * 100;
    if ($rounded > 0 && array_sum($calc['shares']) === $calc['total']) {
        $calc['shares'] = array_map(fn ($units) => $units * 100, allocate(intdiv($rounded, 100), $calc['shares']));
    }
    $calc['round_diff'] = $rounded - $calc['total'];
    $calc['total'] = $rounded;
    return $calc;
}

/**
 * Per-member share in cents of the receipt currency:
 *  - each item is split equally among the members assigned to it;
 *  - tax + service charge are split equally among all members (tax only when it isn't already
 *    included in the prices — see tax_included());
 *  - the receipt discount goes first to members flagged Senior/PWD (in proportion to what they ordered,
 *    up to their item total); anything left — or a promo discount with nobody flagged — is shared by
 *    everyone in proportion to their remaining (undiscounted) items.
 */
function compute_native_shares(array $bill, array $members, array $items): array
{
    if (($bill['split_mode'] ?? 'items') === 'percent') {
        return compute_percent_shares($bill, $members, $items);
    }
    if (($bill['split_mode'] ?? 'items') === 'game') {
        return compute_game_shares($bill, $members, $items);
    }
    $ids = array_column($members, 'id');
    $shares = array_fill_keys($ids, 0);
    $subtotal = 0;
    $unassigned = 0;
    $unassignedCount = 0;

    foreach ($items as $it) {
        $line = cents($it['line_total']);
        $subtotal += $line;
        $who = array_values(array_intersect($it['who'], $ids));
        if (!$who) {
            $unassigned += $line;
            $unassignedCount++;
            continue;
        }
        foreach (split_evenly($line, $who) as $uid => $c) {
            $shares[$uid] += $c;
        }
    }
    $itemShares = $shares;
    $taxIncluded = tax_included($bill, $subtotal);
    $extras = ($taxIncluded ? 0 : cents($bill['tax'])) + cents($bill['service_charge']);
    foreach (split_evenly($extras, $ids) as $uid => $c) {
        $shares[$uid] += $c;
    }

    $discount = cents($bill['discount'] ?? 0);
    $flagged = array_filter($members, fn ($m) => ($m['discount_type'] ?? 'none') !== 'none');
    $flaggedWeights = array_intersect_key($itemShares, array_flip(array_column($flagged, 'id')));
    $toFlagged = min($discount, array_sum($flaggedWeights));
    $discountBy = array_fill_keys($ids, 0);
    foreach (split_proportional($toFlagged, $flaggedWeights) as $uid => $c) {
        $discountBy[$uid] += $c;
    }
    // Whatever is left is shared in proportion to each member's not-yet-discounted items,
    // so nobody's discount can exceed what they ordered.
    $remaining = [];
    foreach ($itemShares as $uid => $c) {
        $remaining[$uid] = $c - $discountBy[$uid];
    }
    foreach (split_proportional($discount - $toFlagged, $remaining) as $uid => $c) {
        $discountBy[$uid] += $c;
    }
    foreach ($discountBy as $uid => $c) {
        $shares[$uid] -= $c;
    }

    return [
        'shares'           => $shares,
        'subtotal'         => $subtotal,
        'extras'           => $extras,
        'tax_included'     => $taxIncluded,
        'discount'         => $discount,
        'discount_by'      => $discountBy,
        'total'            => $subtotal + $extras - $discount,
        'unassigned'       => $unassigned,
        'unassigned_count' => $unassignedCount,
        'split_mode'       => 'items',
        'percent_total'    => null,
    ];
}

/**
 * Percentage split: the whole bill total (items, tax and service charge as in compute_shares(), less the receipt
 * discount) is shared by each member's bill_members.percent; item assignments don't matter. Until the
 * percentages add up to exactly 100 every share is 0 and percent_total (in hundredths of a percent) says why.
 */
function compute_percent_shares(array $bill, array $members, array $items): array
{
    $ids = array_column($members, 'id');
    $subtotal = array_sum(array_map(fn ($it) => cents($it['line_total']), $items));
    $taxIncluded = tax_included($bill, $subtotal);
    $extras = ($taxIncluded ? 0 : cents($bill['tax'])) + cents($bill['service_charge']);
    $discount = cents($bill['discount'] ?? 0);
    $total = $subtotal + $extras - $discount;
    $weights = [];
    foreach ($members as $m) {
        $weights[$m['id']] = (int) round(((float) ($m['percent'] ?? 0)) * 100);
    }
    $percentTotal = array_sum($weights);
    return [
        'shares'           => $percentTotal === 10000 ? split_proportional($total, $weights) : array_fill_keys($ids, 0),
        'subtotal'         => $subtotal,
        'extras'           => $extras,
        'tax_included'     => $taxIncluded,
        'discount'         => $discount,
        'discount_by'      => array_fill_keys($ids, 0),
        'total'            => $total,
        'unassigned'       => 0,
        'unassigned_count' => 0,
        'split_mode'       => 'percent',
        'percent_total'    => $percentTotal,
    ];
}

/**
 * Fun Mode split (pages/game.php decided it). The whole bill total, worked out as in compute_percent_shares(), is
 * either set member by member (game_share, from Mystery Card — it already adds up to the total) or shared equally
 * by the members with game_weight 1; weight 0 pays nothing.
 */
function compute_game_shares(array $bill, array $members, array $items): array
{
    $ids = array_column($members, 'id');
    $subtotal = array_sum(array_map(fn ($it) => cents($it['line_total']), $items));
    $taxIncluded = tax_included($bill, $subtotal);
    $extras = ($taxIncluded ? 0 : cents($bill['tax'])) + cents($bill['service_charge']);
    $discount = cents($bill['discount'] ?? 0);
    $total = $subtotal + $extras - $discount;
    if (array_filter($members, fn ($m) => ($m['game_share'] ?? null) !== null)) {
        $shares = array_combine($ids, array_map(fn ($m) => (int) ($m['game_share'] ?? 0), $members));
    } else {
        $weights = [];
        foreach ($members as $m) {
            $weights[$m['id']] = (int) ($m['game_weight'] ?? 1);
        }
        $shares = split_proportional($total, $weights);
    }
    return [
        'shares'           => $shares,
        'subtotal'         => $subtotal,
        'extras'           => $extras,
        'tax_included'     => $taxIncluded,
        'discount'         => $discount,
        'discount_by'      => array_fill_keys($ids, 0),
        'total'            => $total,
        'unassigned'       => 0,
        'unassigned_count' => 0,
        'split_mode'       => 'game',
        'percent_total'    => null,
    ];
}

/**
 * Save a game's outcome as the bill's split. $weights: uid => 1 (shares the bill) or 0 (pays nothing); or
 * $shares: uid => exact centavos (Mystery Card).
 */
function apply_game_result(array $bill, array $weights, ?array $shares = null): void
{
    require_editable($bill);
    q('UPDATE bill_members SET game_weight = NULL, game_share = NULL WHERE bill_id = ?', [$bill['id']]);
    foreach ($shares ?? $weights as $uid => $v) {
        q('UPDATE bill_members SET ' . ($shares === null ? 'game_weight' : 'game_share') . ' = ? WHERE bill_id = ? AND user_id = ?',
            [$shares === null ? ($v ? 1 : 0) : (int) $v, $bill['id'], $uid]);
    }
    q("UPDATE bills SET split_mode = 'game' WHERE id = ?", [$bill['id']]);
}

/** Undo a game's split: back to item-by-item. */
function clear_game_result(array $bill): void
{
    require_editable($bill);
    q("UPDATE bills SET split_mode = 'items' WHERE id = ?", [$bill['id']]);
    q('UPDATE bill_members SET game_weight = NULL, game_share = NULL WHERE bill_id = ?', [$bill['id']]);
}

function bill_settlements(int $billId): array
{
    $rows = q(
        'SELECT s.*, ' . SETTLEMENT_ONLINE_STARTED . ', b.kind AS bill_kind, fu.full_name AS from_name, fu.avatar_color AS from_color, fu.role AS from_role, tu.full_name AS to_name, tu.avatar_color AS to_color, tu.payment_method AS to_method, tu.payment_account AS to_account, tu.pay_code AS to_pay_code
         FROM settlements s JOIN bills b ON b.id = s.bill_id JOIN users fu ON fu.id = s.from_user_id JOIN users tu ON tu.id = s.to_user_id
         WHERE s.bill_id = ? ORDER BY s.id',
        [$billId]
    )->fetchAll();
    return array_map('settlement_row', $rows);
}

// Select column: an online checkout opened for this settlement and not paid yet.
const SETTLEMENT_ONLINE_STARTED = "EXISTS(SELECT 1 FROM settlement_payments sp WHERE sp.settlement_id = s.id AND sp.status = 'started') AS online_started";

function settlement_row(array $s): array
{
    $amount = cents($s['amount']);
    $principal = $s['principal'] === null ? $amount : cents($s['principal']);
    $paid = cents($s['paid_amount'] ?? 0);
    return [
        'id'             => (int) $s['id'],
        'currency'       => $s['currency'] ?? 'PHP',
        // Settled: what it came to in the debtor's own currency, at that day's rate (null when the same currency)
        'local'          => ($s['local_currency'] ?? null) ? [
            'currency' => $s['local_currency'], 'amount' => (float) $s['local_amount'], 'rate' => (float) $s['local_rate'],
            'source'   => $s['local_rate_source'], 'at' => $s['local_rate_at'],
        ] : null,
        'principal'      => pesos($principal),
        'paid_amount'    => pesos($paid),
        'remaining'      => pesos(max(0, $amount - $paid)),
        'interest_rate'  => $s['interest_rate'] === null ? null : (float) $s['interest_rate'],
        'interest_added' => pesos(max(0, $amount - $principal)),
        'bill_id'        => (int) $s['bill_id'],
        'bill_name'      => $s['bill_name'] ?? null,
        'bill_kind'      => $s['bill_kind'] ?? 'bill',
        'bill_closed'    => ($s['bill_status'] ?? null) === 'closed',
        'bill_creator_id'=> (int) ($s['bill_creator_id'] ?? 0),
        'amount'         => (float) $s['amount'],
        'status'         => $s['status'],
        'settle_method'  => $s['settle_method'] ?? null,
        'online_started' => (bool) ($s['online_started'] ?? false),
        'paid_at'        => $s['paid_at'],
        'confirmed_at'   => $s['confirmed_at'],
        'disputed_at'    => $s['disputed_at'],
        'dispute_reason' => $s['dispute_reason'],
        'payment_ref'    => $s['payment_ref'],
        'has_proof'      => (bool) $s['proof_image'],
        'created_at'     => $s['created_at'],
        'from'           => public_user(['id' => $s['from_user_id'], 'full_name' => $s['from_name'], 'avatar_color' => $s['from_color'], 'role' => $s['from_role'] ?? 'user']),
        // The receiver's payment details, so the sender knows where to send the money.
        'to'             => public_user(['id' => $s['to_user_id'], 'full_name' => $s['to_name'], 'avatar_color' => $s['to_color']]) + [
            'payment_method'  => $s['to_method'],
            'payment_account' => $s['to_account'],
            'pay_code'        => $s['to_pay_code'] ?? null,  // receiver's Pay-me QR (guests have none)
        ],
    ];
}

/** Compact bill info for lists (dashboard, my bills, history, admin). */
function bill_card(array $bill): array
{
    $id = (int) $bill['id'];
    $members = (int) q('SELECT COUNT(*) FROM bill_members WHERE bill_id = ?', [$id])->fetchColumn();
    $sub = (int) q('SELECT COALESCE(SUM(ROUND(qty * unit_price * 100)), 0) FROM receipt_items WHERE bill_id = ?', [$id])->fetchColumn();
    $tax = tax_included($bill, $sub) ? 0 : cents($bill['tax']);
    $native = $sub + $tax + cents($bill['service_charge']) - cents($bill['discount'] ?? 0);
    $fx = bill_fx($bill, $native);
    $total = ($bill['kind'] ?? 'bill') === 'loan' ? cents($bill['loan_amount'] ?? 0) : $fx['converted'] + $fx['fees'];
    if (($bill['kind'] ?? 'bill') !== 'loan' && !empty($bill['round_total']) && currency_decimals($fx['currency']) > 0) {
        $total = floor_div($total + 50, 100) * 100; // "round to whole pesos", as round_to_whole()
    }

    switch ($bill['status']) {
        case 'closed':
            $progress = 100;
            break;
        case 'settling':
            $row = q("SELECT COUNT(*) AS n, SUM(status = 'settled') AS done FROM settlements WHERE bill_id = ?", [$id])->fetch();
            $progress = $row['n'] ? (int) round(100 * $row['done'] / $row['n']) : 100;
            break;
        case 'active':
            $row = q(
                'SELECT COUNT(*) AS n, SUM(EXISTS(SELECT 1 FROM item_assignments a WHERE a.item_id = i.id)) AS done FROM receipt_items i WHERE i.bill_id = ?',
                [$id]
            )->fetch();
            $progress = $row['n'] ? (int) round(100 * $row['done'] / $row['n']) : 0;
            break;
        default:
            $progress = 0;
    }

    return [
        'id'         => $id,
        'name'       => $bill['name'],
        'initials'   => initials($bill['name']),
        'status'     => $bill['status'],
        'members'    => $members,
        'total'      => pesos($total),
        'currency'   => $fx['currency'],
        // The receipt's own currency and total, when it differs (e.g. ¥6,000 shown under ₱2,460)
        'receipt'    => $fx['foreign'] ? ['currency' => $fx['receipt_currency'], 'total' => pesos($native)] : null,
        'progress'   => $progress,
        'created_at' => $bill['created_at'],
        'closed_at'  => $bill['closed_at'] ?? null,
        'creator'    => $bill['creator_name'] ?? null,
    ];
}

/** Where a bill card should take the user, based on its stage. */
function bill_link(array $bill, int $userId): string
{
    if (($bill['kind'] ?? 'bill') === 'loan') {
        return 'utang';
    }
    if ($bill['status'] === 'closed') {
        return 'bill-breakdown?bill=' . $bill['id'];
    }
    if ($bill['status'] === 'settling') {
        return 'bill-detail?bill=' . $bill['id'];
    }
    if ((int) $bill['creator_id'] !== $userId) {
        return 'bill-items?bill=' . $bill['id'];
    }
    return $bill['status'] === 'draft' ? 'scan-receipt?bill=' . $bill['id'] : 'assign-items?bill=' . $bill['id'];
}

/** A new unique 12-character code for a user's "Pay me" QR (unambiguous characters only). */
function new_pay_code(): string
{
    $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
    do {
        $code = '';
        for ($i = 0; $i < 12; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
    } while (q('SELECT 1 FROM users WHERE pay_code = ?', [$code])->fetchColumn());
    return $code;
}

function notify(int $userId, string $type, string $message, ?string $link = null): void
{
    // Guests have no account, so there is nobody to notify. Long bill names can't overflow the column.
    $message = mb_strlen($message) > 300 ? mb_substr($message, 0, 299) . '…' : $message;
    q("INSERT INTO notifications (user_id, type, message, link) SELECT id, ?, ?, ? FROM users WHERE id = ? AND role <> 'guest'", [$type, $message, $link, $userId]);
}

function log_event(int $settlementId, ?int $actorId, string $event, ?string $note = null): void
{
    q('INSERT INTO settlement_events (settlement_id, actor_id, event, note) VALUES (?, ?, ?, ?)', [$settlementId, $actorId, $event, $note]);
}

/**
 * What each member paid the restaurant, in cents. With no bill_payments rows,
 * the single payer is taken to have paid the whole bill total.
 */
function bill_payments(array $bill, int $totalCents): array
{
    $rows = q('SELECT user_id, amount FROM bill_payments WHERE bill_id = ? ORDER BY user_id', [$bill['id']])->fetchAll();
    if (!$rows) {
        return [(int) $bill['payer_id'] => $totalCents];
    }
    $out = [];
    foreach ($rows as $r) {
        $out[(int) $r['user_id']] = cents($r['amount']);
    }
    return $out;
}

/**
 * Who received the change, when the amounts handed to the cashier came to more than the bill: the person the creator
 * named (bills.change_holder_id, any member), or — until someone is named — the main payer if they handed over enough
 * to have got it, else whoever handed over the most. Returns [uid, named?].
 */
function change_holder(array $bill, array $members, array $paid, int $change): array
{
    $named = $bill['change_holder_id'] ?? null;
    if ($named !== null && in_array((int) $named, array_column($members, 'id'), true)) {
        return [(int) $named, true];
    }
    $payer = (int) $bill['payer_id'];
    return [($paid[$payer] ?? 0) >= $change ? $payer : (int) array_search(max($paid), $paid, true), false];
}

/**
 * Who pays whom, with as few transfers as possible (greedy: the biggest debtor pays the biggest creditor).
 * The amounts paid are what each person handed to the cashier, so together they may be more than the bill: the
 * difference is the change, and whoever physically got it (change_holder()) holds everyone's part of it. That can
 * leave them owing others even if they handed over little or nothing themselves.
 * With the total rounded (round_to_whole()), the main payer keeps (or covers) the difference from what was charged.
 * Returns ['transfers' => [[from, to, cents], ...], 'balances' => uid => what they're owed (+) or owe (−),
 *          'expected' => uid => paid − share: the change due back to each (+) or what they still have to put in (−),
 *          'paid' => uid => cents, 'paid_total', 'change', 'holder' => uid|null, 'holder_named' => bool,
 *          'mismatch' => cents the payments are short of what was charged].
 */
function settlement_plan(array $bill, array $members, array $calc): array
{
    $charged = $calc['exact_total'] ?? $calc['total'];
    $roundDiff = $calc['round_diff'] ?? 0;
    $paid = bill_payments($bill, $charged);
    $paidTotal = array_sum($paid);
    $change = max(0, $paidTotal - $charged);
    [$holder, $named] = $change > 0 ? change_holder($bill, $members, $paid, $change) : [null, false];

    $expected = [];
    $balances = [];
    foreach ($members as $m) {
        $uid = $m['id'];
        $expected[$uid] = ($paid[$uid] ?? 0) - ($calc['shares'][$uid] ?? 0) + ($uid === (int) $bill['payer_id'] ? $roundDiff : 0);
        $balances[$uid] = $expected[$uid] - ($uid === $holder ? $change : 0);
    }
    // Only a shortfall is a problem: the payments must cover what was charged.
    $mismatch = min(0, $paidTotal - $charged);

    $transfers = [];
    if ($mismatch === 0 && $calc['unassigned_count'] === 0 && empty($calc['fx_missing']) && in_array($calc['percent_total'] ?? null, [null, 10000], true)) {
        $bal = $balances;
        while (true) {
            $debtor = $creditor = null;
            foreach ($bal as $uid => $b) {
                if ($b < 0 && ($debtor === null || $b < $bal[$debtor])) {
                    $debtor = $uid;
                }
                if ($b > 0 && ($creditor === null || $b > $bal[$creditor])) {
                    $creditor = $uid;
                }
            }
            if ($debtor === null || $creditor === null) {
                break;
            }
            $amount = min(-$bal[$debtor], $bal[$creditor]);
            $transfers[] = [$debtor, $creditor, $amount];
            $bal[$debtor] += $amount;
            $bal[$creditor] -= $amount;
        }
    }
    return ['transfers' => $transfers, 'balances' => $balances, 'expected' => $expected, 'paid' => $paid, 'paid_total' => $paidTotal,
            'change' => $change, 'holder' => $holder, 'holder_named' => $named, 'mismatch' => $mismatch];
}

/**
 * Lock the bill and create the settlements from the plan. $interestRate (% of what's left, added after each
 * partial payment) turns on installments with interest for every settlement of the bill; null or 0 = no interest.
 */
function start_settling(array $bill, array $actor, ?float $interestRate = null): void
{
    // Only the fixed INSTALLMENT_RATES (api/bills.php checks the request; this guards any other caller)
    $interestRate = $interestRate > 0 && in_array((float) $interestRate, array_map('floatval', array_column(INSTALLMENT_RATES, 'rate')), true) ? (float) $interestRate : null;
    $members = bill_members($bill['id']);
    $items = bill_items($bill['id']);
    if (!$items) {
        fail('Add the receipt items before settling.', 422);
    }
    $calc = compute_shares($bill, $members, $items);
    $cur = $calc['currency'];
    if ($calc['unassigned_count'] > 0) {
        fail('Assign every item first — ' . money_str($calc['unassigned'], $calc['receipt_currency']) . ' is still unassigned.', 422);
    }
    if ($calc['split_mode'] === 'percent' && $calc['percent_total'] !== 10000) {
        fail('The percentages add up to ' . rtrim(rtrim(number_format($calc['percent_total'] / 100, 2), '0'), '.') . '% — make them 100% first.', 422);
    }
    if ($calc['fx_missing']) {
        fail("The receipt is in {$calc['receipt_currency']} but there's no exchange rate to $cur yet. Refresh the rate, or enter what was actually charged, on Bill Detail.", 422);
    }
    $plan = settlement_plan($bill, $members, $calc);
    if ($plan['mismatch'] !== 0) {
        fail('The payments entered (' . money_str($plan['paid_total'], $cur) . ') don’t cover the bill total ('
            . money_str($calc['exact_total'], $cur) . '). Fix “Who paid the restaurant” first.', 422);
    }
    if ($plan['change'] > 0 && !$plan['holder_named']) {
        fail('There’s ' . money_str($plan['change'], $cur) . ' change — choose who received it first.', 422, ['need' => 'change_holder']);
    }
    $names = array_column($members, 'first', 'id');

    $pdo = db();
    $pdo->beginTransaction();
    try {
        q("UPDATE bills SET status = 'settling', settling_at = NOW(), interest_rate = ? WHERE id = ? AND status IN ('draft','active')", [$interestRate, $bill['id']]);
        if ($calc['fx']['foreign']) {
            $fx = $calc['fx'];
            fx_log($bill['id'], (int) $actor['id'], 'Settling started: ' . money_str($fx['native_total'], $fx['receipt_currency']) . ' → ' . money_str($fx['converted'], $cur)
                . ' at ' . rtrim(rtrim(number_format($fx['rate'], 6), '0'), '.') . " ($fx[source])" . ($fx['fees'] ? ' + ' . money_str($fx['fees'], $cur) . ' fees' : '') . '.');
        }
        foreach ($plan['transfers'] as [$from, $to, $c]) {
            q('INSERT INTO settlements (bill_id, from_user_id, to_user_id, currency, principal, amount, interest_rate) VALUES (?, ?, ?, ?, ?, ?, ?)', [$bill['id'], $from, $to, $cur, pesos($c), pesos($c), $interestRate]);
            $sid = (int) $pdo->lastInsertId();
            log_event($sid, $actor['id'], 'created', 'Generated from ' . $bill['name'] . ' balances'
                . ($plan['holder'] !== null ? ", with {$names[$plan['holder']]} holding the " . money_str($plan['change'], $cur) . ' change' : '')
                . ($calc['round_diff'] ? ', total rounded to ' . money_str($calc['total'], $cur) : '') . '.');
            apply_credits($from, $to, (int) $actor['id'], $cur);
            notify($from, 'settling', "You owe {$names[$to]} " . money_str($c, $cur) . " for {$bill['name']}."
                . ($interestRate ? ' Paying in parts adds ' . (0 + $interestRate) . "% of what's left each time." : ''), 'my-settlements');
        }
        foreach (array_unique(array_column($plan['transfers'], 1)) as $to) {
            notify($to, 'settling', "{$bill['name']} is now settling — you'll be asked to confirm each payment.", 'bill-detail?bill=' . $bill['id']);
        }
        achievements_after_settling($bill, $calc);
        maybe_close_bill($bill['id']);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Whether any money has moved on a bill's settlements: a part sent, received, or paid online (even one still at
 * PayMongo's checkout). Until then the creator may still take the settlement plan back or delete the bill.
 */
function bill_money_moved(int $billId): bool
{
    return (bool) q(
        "SELECT 1 FROM settlement_payments p JOIN settlements s ON s.id = p.settlement_id
         WHERE s.bill_id = ? AND p.status IN ('started','awaiting','confirmed') LIMIT 1",
        [$billId]
    )->fetchColumn();
}

function maybe_close_bill(int $billId): void
{
    $open = (int) q("SELECT COUNT(*) FROM settlements WHERE bill_id = ? AND status <> 'settled'", [$billId])->fetchColumn();
    if ($open === 0) {
        $changed = q("UPDATE bills SET status = 'closed', closed_at = NOW() WHERE id = ? AND status = 'settling'", [$billId])->rowCount();
        if ($changed) {
            $name = (string) q('SELECT name FROM bills WHERE id = ?', [$billId])->fetchColumn();
            foreach (bill_members($billId) as $m) {
                notify($m['id'], 'closed', "$name is fully settled and closed.", 'bill-breakdown?bill=' . $billId);
            }
        }
    }
}

// ---------- Guest access: a name-only member's private link, and turning a guest into an account ----------

/** Guest-link tokens: 24 characters from the same unambiguous alphabet as invite codes. */
function new_guest_token(): string
{
    $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
    do {
        $token = '';
        for ($i = 0; $i < 24; $i++) {
            $token .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
    } while (q('SELECT 1 FROM bill_members WHERE view_token = ?', [$token])->fetchColumn());
    return $token;
}

const GUEST_LINK_MAX_MISSES = 20;    // wrong links per IP per window
const GUEST_LINK_WINDOW_MINUTES = 15;

/**
 * The guest a private link belongs to: [bill, guest user row], or a 404. Wrong tokens are counted per IP (the same
 * login_attempts table the sign-in throttle uses) so a link can't be guessed.
 */
function guest_by_token(string $token): array
{
    $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $miss = function (string $msg) use ($ip): never {
        q('INSERT INTO login_attempts (email, ip) VALUES (?, ?)', ['guest-link', $ip]);
        fail($msg, 404);
    };
    $recent = (int) q(
        "SELECT COUNT(*) FROM login_attempts WHERE email = 'guest-link' AND ip = ? AND attempted_at > NOW() - INTERVAL " . GUEST_LINK_WINDOW_MINUTES . ' MINUTE',
        [$ip]
    )->fetchColumn();
    if ($recent >= GUEST_LINK_MAX_MISSES) {
        fail('Too many tries. Please wait a few minutes and open your link again.', 429);
    }
    if (!preg_match('/^[A-Za-z0-9]{24}$/', $token)) {
        $miss('This link isn’t valid. Ask the person who sent it for a new one.');
    }
    $row = q(
        "SELECT m.bill_id, u.id AS guest_id FROM bill_members m JOIN users u ON u.id = m.user_id
         WHERE m.view_token = BINARY ? AND u.role = 'guest'",
        [$token]
    )->fetch();
    if (!$row) {
        $miss('This link isn’t valid any more. If you already made a Setlo account, sign in to see your bill.');
    }
    $bill = q('SELECT * FROM bills WHERE id = ?', [$row['bill_id']])->fetch();
    foreach (['id', 'creator_id', 'payer_id'] as $k) {
        $bill[$k] = (int) $bill[$k];
    }
    $bill['change_holder_id'] = $bill['change_holder_id'] === null ? null : (int) $bill['change_holder_id'];
    $guest = q('SELECT id, full_name, avatar_color, role, avatar, featured_achievement FROM users WHERE id = ?', [$row['guest_id']])->fetch();
    return [$bill, $guest];
}

/**
 * Move a guest's bill and payment records into a real account, then remove the guest. Used when someone opens their
 * guest link and registers or signs in. One transaction; where the account is already on the same bill the rows are
 * merged instead of duplicated. Returns the bill's id.
 */
function claim_guest_account(string $token, int $userId): int
{
    [$bill, $guest] = guest_by_token($token);
    $g = (int) $guest['id'];
    $real = q("SELECT id FROM users WHERE id = ? AND role = 'user' AND status = 'active'", [$userId])->fetchColumn();
    if (!$real) {
        fail('Only a Setlo account can take over a guest bill.', 403);
    }
    if ($bill['creator_id'] === $userId) {
        fail('This is your own bill, so there is nothing to claim.', 409);
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Tables keyed by (something, user): move the row, or drop the guest's when the account already has one
        foreach (['bill_members', 'item_assignments', 'bill_payments', 'game_players'] as $table) {
            q("UPDATE IGNORE $table SET user_id = ? WHERE user_id = ?", [$userId, $g]);
            q("DELETE FROM $table WHERE user_id = ?", [$g]);
        }
        foreach ([['bills', 'creator_id'], ['bills', 'payer_id'], ['bills', 'change_holder_id'], ['settlements', 'from_user_id'], ['settlements', 'to_user_id'],
                  ['settlement_events', 'actor_id'], ['settlement_payments', 'paid_by'], ['credits', 'owner_id'], ['credits', 'holder_id'],
                  ['game_sessions', 'host_id'], ['game_players', 'target_id']] as [$table, $col]) {
            q("UPDATE $table SET $col = ? WHERE $col = ?", [$userId, $g]);
        }
        // If the account was both sides of a debt (they paid the bill the guest owed), the debt cancels itself out
        q('DELETE FROM settlements WHERE from_user_id = to_user_id AND from_user_id = ?', [$userId]);
        q('UPDATE bill_members SET view_token = NULL WHERE bill_id = ? AND user_id = ?', [$bill['id'], $userId]); // the link is spent
        q("DELETE FROM users WHERE id = ? AND role = 'guest'", [$g]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    $who = q('SELECT full_name FROM users WHERE id = ?', [$userId])->fetchColumn();
    notify($bill['creator_id'], 'added', first_name((string) $who) . " now has a Setlo account — their share of {$bill['name']} is linked to it.", 'bill-detail?bill=' . $bill['id']);
    return $bill['id'];
}
