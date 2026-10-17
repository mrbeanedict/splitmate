<?php
// One-time achievements. Each is earned once (user_achievements) and kept for good. Whoever's request earns one
// sees its pop-up in that reply (json_ok adds them); anyone else sees theirs on the dashboard, after a notification.
// A user can hide badges and feature one shown badge as the title next to their name (users.featured_achievement).
// Most badges take several occurrences (ACHIEVEMENT_TARGETS), counted in achievement_progress once per bill,
// settlement or game. Badges earned under the older, easier rules stay earned.

declare(strict_types=1);

// image: artwork in assets/achievement (transparent PNG, shown on the badge card's colour; the emoji until it exists)
const ACHIEVEMENTS = [
    'itemalizer'        => ['emoji' => '🧾', 'title' => 'Itemalizer',        'image' => 'itemalizer.png',        'description' => 'Split five bills item by item.'],
    'kuripot'           => ['emoji' => '🪙', 'title' => 'Kuripot',           'image' => 'kuripot.png',           'description' => 'Had the smallest share of the group ten times.'],
    'glutton'           => ['emoji' => '🍔', 'title' => 'Glutton',           'image' => 'glutton.png',           'description' => 'Your share of a single bill reached ₱1,000 — twice.'],
    'one_two_three'     => ['emoji' => '🤝', 'title' => '1, 2, 3',           'image' => '1-2-3.png',             'description' => 'Someone else covered your share five times.'],
    'samaritan'         => ['emoji' => '💚', 'title' => 'Samaritan',         'image' => 'samaritan.png',         'description' => 'Paid someone’s whole share as a treat three times.'],
    'split_personality' => ['emoji' => '🎭', 'title' => 'Split Personality', 'image' => 'split-personality.png', 'description' => 'Paid five debts in parts using two or more payment methods.'],
    'debt_collector'    => ['emoji' => '💰', 'title' => 'Debt Collector',    'image' => 'debt-collector.png',    'description' => 'Got paid twice on money owed to you for over a day.'],
    'debt_survivor'     => ['emoji' => '🫠', 'title' => 'Debt Survivor',     'image' => 'debt-survivor.png',     'description' => 'Had a balance still unpaid after 7 days.'],
    'clean_slate'       => ['emoji' => '🧼', 'title' => 'Clean Slate',       'image' => 'clean-slate.png',       'description' => 'Paid everything off to ₱0 owed, three times.'],
    'big_spender'       => ['emoji' => '💸', 'title' => 'Big Spender',       'image' => 'big-spender.png',       'description' => 'Your shares of all your bills add up to ₱5,000.'],
    'human_calculator'  => ['emoji' => '🧮', 'title' => 'Human Calculator',  'image' => 'human-calculator.png',  'description' => 'Won Receipt Race three times.'],
    'bullseye'          => ['emoji' => '🎯', 'title' => 'Bullseye',          'image' => 'bullseye.png',          'description' => 'Got closest to the target without going over.'],
    'main_character'    => ['emoji' => '🌟', 'title' => 'Main Character',    'image' => 'main-character.png',    'description' => 'The roulette picked your wallet to pay the whole bill.'],
    'receipt_from_hell' => ['emoji' => '😈', 'title' => 'Receipt from Hell', 'image' => 'receipt-from-hell.png', 'description' => 'Were on a single bill with 30 or more items.'],
];

// How many occurrences each badge takes (big_spender: centavos in pesos); badges not listed take one.
const ACHIEVEMENT_TARGETS = [
    'itemalizer'        => 5,
    'kuripot'           => 10,
    'glutton'           => 2,
    'one_two_three'     => 5,
    'samaritan'         => 3,
    'split_personality' => 5,
    'debt_collector'    => 2,
    'clean_slate'       => 3,
    'human_calculator'  => 3,
    'big_spender'       => 500000,
];

const GLUTTON_CENTS = 100000;
const RECEIPT_FROM_HELL_ITEMS = 30;
const DEBT_COLLECTOR_HOURS = 24;  // owed at least this long before the payment counts
const DEBT_SURVIVOR_DAYS = 7;

/** Badges earned during this request, by user id. With $userId and $code, records one. */
function achievements_earned(?int $userId = null, ?string $code = null): array
{
    static $earned = [];
    if ($userId !== null) {
        $earned[$userId][] = $code;
    }
    return $earned;
}

/** Give $userId a badge, once. Guests never get one. */
function award(int $userId, string $code, ?int $billId = null): void
{
    $n = q(
        "INSERT IGNORE INTO user_achievements (user_id, code, bill_id) SELECT id, ?, ? FROM users WHERE id = ? AND role <> 'guest'",
        [$code, $billId, $userId]
    )->rowCount();
    if ($n === 0) {
        return;
    }
    achievements_earned($userId, $code);
    $a = ACHIEVEMENTS[$code];
    notify($userId, 'achievement', "Achievement unlocked: {$a['emoji']} {$a['title']} — {$a['description']}", 'dashboard?achievements=1');
}

/**
 * Count one occurrence of $code for $userId — once per $refId (the bill, settlement or game it happened on) — and
 * give the badge once ACHIEVEMENT_TARGETS is reached. $amount: what this occurrence adds (big_spender: centavos).
 */
function progress(int $userId, string $code, int $refId, ?int $billId, int $amount = 1): void
{
    $target = ACHIEVEMENT_TARGETS[$code] ?? 1;
    if ($target > 1) {
        q(
            "INSERT IGNORE INTO achievement_progress (user_id, code, ref_id, amount) SELECT id, ?, ?, ? FROM users WHERE id = ? AND role <> 'guest'",
            [$code, $refId, $amount, $userId]
        );
        $total = (int) q('SELECT COALESCE(SUM(amount), 0) FROM achievement_progress WHERE user_id = ? AND code = ?', [$userId, $code])->fetchColumn();
        if ($total < $target) {
            return;
        }
    }
    award($userId, $code, $billId);
}

/** What the pop-up and the profile need for one badge. $row: its user_achievements row, if loaded. */
function achievement_payload(string $code, ?array $row = null): array
{
    $file = ACHIEVEMENTS[$code]['image'];
    $image = is_file(__DIR__ . '/../assets/achievement/' . $file) ? url('assets/achievement/' . $file) : null;
    return ['code' => $code, 'image' => $image] + ACHIEVEMENTS[$code] + ($row ? [
        'earned_at' => $row['earned_at'],
        'hidden'    => (bool) $row['hidden'],
    ] : []);
}

/** A user's badges in the order earned; without $withHidden, only the ones they show. */
function user_badges(int $userId, bool $withHidden): array
{
    $rows = q('SELECT * FROM user_achievements WHERE user_id = ?' . ($withHidden ? '' : ' AND hidden = 0') . ' ORDER BY earned_at, code', [$userId])->fetchAll();
    $rows = array_filter($rows, fn ($r) => isset(ACHIEVEMENTS[$r['code']])); // badges since retired stay in the table, unshown
    return array_values(array_map(fn ($r) => achievement_payload($r['code'], $r), $rows));
}

/** The featured title shown next to a name, or null. */
function featured_badge(?string $code): ?array
{
    return $code && isset(ACHIEVEMENTS[$code]) ? ['emoji' => ACHIEVEMENTS[$code]['emoji'], 'title' => ACHIEVEMENTS[$code]['title']] : null;
}

/**
 * The badges the signed-in user earned in this request, for the reply (json_ok). They are on their way to that
 * user's screen, so they count as seen.
 */
function achievements_for_reply(): array
{
    $me = current_user();
    $codes = $me ? array_values(array_unique(achievements_earned()[$me['id']] ?? [])) : [];
    if (!$codes) {
        return [];
    }
    $in = implode(',', array_fill(0, count($codes), '?'));
    q("UPDATE user_achievements SET seen_at = NOW() WHERE user_id = ? AND code IN ($in)", array_merge([$me['id']], $codes));
    return array_map('achievement_payload', $codes);
}

/** Badges for the shares of a bill that just started settling ($calc from compute_shares). */
function achievements_after_settling(array $bill, array $calc): void
{
    if (($bill['kind'] ?? 'bill') !== 'bill') {
        return;
    }
    $billId = (int) $bill['id'];

    if ($calc['split_mode'] === 'items') {
        progress((int) $bill['creator_id'], 'itemalizer', $billId, $billId);
    }

    // Receipt from Hell: everyone on a bill of 30+ item lines, whatever their share (award() skips guests).
    $items = (int) q('SELECT COUNT(*) FROM receipt_items WHERE bill_id = ?', [$billId])->fetchColumn();
    if ($items >= RECEIPT_FROM_HELL_ITEMS) {
        foreach (q('SELECT user_id FROM bill_members WHERE bill_id = ?', [$billId])->fetchAll(PDO::FETCH_COLUMN) as $uid) {
            award((int) $uid, 'receipt_from_hell', $billId);
        }
    }

    $shares = array_filter($calc['shares'], fn ($c) => $c > 0);
    // Shares in pesos, for the ₱1,000 bar and the ₱5,000 running total (no rate known: this bill doesn't count)
    $toPeso = ($calc['currency'] ?? 'PHP') === 'PHP' ? 1.0 : (fx_rate($calc['currency'], 'PHP')['rate'] ?? 0.0);
    if ($toPeso > 0) {
        foreach ($shares as $uid => $c) {
            $peso = (int) round($c * $toPeso);
            if ($peso >= GLUTTON_CENTS) {
                progress((int) $uid, 'glutton', $billId, $billId);
            }
            progress((int) $uid, 'big_spender', $billId, $billId, $peso);
        }
    }
    // Kuripot: only when exactly one person has the smallest share.
    if (count($shares) >= 2) {
        $min = min($shares);
        $smallest = array_keys($shares, $min, true);
        if (count($smallest) === 1) {
            progress((int) $smallest[0], 'kuripot', $billId, $billId);
        }
    }
}

/** Debt Survivor: $userId has owed on a settlement for DEBT_SURVIVOR_DAYS. Checked on each visit and payment. */
function check_debt_survivor(int $userId): void
{
    $billId = q(
        "SELECT bill_id FROM settlements WHERE from_user_id = ? AND status <> 'settled' AND created_at <= NOW() - INTERVAL ? DAY LIMIT 1",
        [$userId, DEBT_SURVIVOR_DAYS]
    )->fetchColumn();
    if ($billId !== false) {
        award($userId, 'debt_survivor', (int) $billId);
    }
}

/** Badges for a payment part the receiver just got. $s: the settlement (before this part), $p: the part. */
function achievements_after_payment(array $s, array $p): void
{
    $sid = (int) $s['id'];
    $billId = (int) $s['bill_id'];
    $debtor = (int) $s['from_user_id'];
    $payer = (int) $p['paid_by'];
    $now = q(
        'SELECT status, created_at <= NOW() - INTERVAL ? HOUR AS overdue, created_at <= NOW() - INTERVAL ? DAY AS survived FROM settlements WHERE id = ?',
        [DEBT_COLLECTOR_HOURS, DEBT_SURVIVOR_DAYS, $sid]
    )->fetch();
    $status = (string) $now['status'];

    // Debt Collector: paid on money that had been owed for over a day.
    if ($now['overdue']) {
        progress((int) $s['to_user_id'], 'debt_collector', $sid, $billId);
    }
    if ($now['survived']) {
        award($debtor, 'debt_survivor', $billId);
    }

    if ($payer !== $debtor) {
        progress($debtor, 'one_two_three', $sid, $billId);
        // Samaritan: the whole debt paid by this one person, as a treat.
        $others = (int) q(
            "SELECT COUNT(*) FROM settlement_payments WHERE settlement_id = ? AND status = 'confirmed' AND (paid_by <> ? OR pay_back = 1)",
            [$sid, $payer]
        )->fetchColumn();
        if ($status === 'settled' && $others === 0) {
            progress($payer, 'samaritan', $sid, $billId);
        }
    }

    // Different methods: cash, transfer, and each online channel (GCash, Maya, card…) on its own.
    $methods = (int) q(
        "SELECT COUNT(DISTINCT IF(method = 'online', COALESCE(online_via, 'online'), method)) FROM settlement_payments
         WHERE settlement_id = ? AND status = 'confirmed' AND paid_by = ? AND method NOT IN ('credit','wallet')",
        [$sid, $debtor]
    )->fetchColumn();
    if ($methods >= 2) {
        progress($debtor, 'split_personality', $sid, $billId);
    }

    if ($status === 'settled') {
        $open = (int) q("SELECT COUNT(*) FROM settlements WHERE from_user_id = ? AND status <> 'settled'", [$debtor])->fetchColumn();
        if ($open === 0) {
            progress($debtor, 'clean_slate', $sid, $billId);
        }
    }
}
