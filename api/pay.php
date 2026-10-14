<?php
// "Pay me" page: whose QR was scanned, how to pay them, and what I still owe them (GET), and paying all of it in one
// PayMongo checkout (POST pay_online, then check_online once PayMongo sends the payer back).
// A checkout covers every debt I can pay online to this person: one settlement_payments row per debt, all with the
// same PayMongo session. It is only counted after the server asks PayMongo and the amount matches (never from the URL).
require __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/paymongo.php';
require_once __DIR__ . '/../includes/payments.php';

const QR_SEND_MAX_CENTS = 5000000;   // most one "Send money" payment can be: ₱50,000
const QR_SEND_PER_HOUR = 10;         // checkouts one person can open in an hour

$me = api_user();

// The money others sent me through my Pay-me QR (Payment Methods page): GET pay.php?received=1
if (method() === 'GET' && isset($_GET['received'])) {
    $rows = q(
        "SELECT qp.id, qp.amount, qp.currency, qp.note, qp.online_via, qp.online_detail, qp.paid_at, u.id, u.full_name, u.avatar_color, u.role
         FROM qr_payments qp JOIN users u ON u.id = qp.from_user_id
         WHERE qp.to_user_id = ? AND qp.status = 'paid' ORDER BY qp.paid_at DESC, qp.id DESC LIMIT 30",
        [$me['id']]
    )->fetchAll(PDO::FETCH_NUM);
    json_ok(['received' => array_map(fn ($r) => [
        'id'       => (int) $r[0],
        'amount'   => (float) $r[1],
        'currency' => $r[2],
        'note'     => $r[3],
        'via'      => online_via_name($r[4], $r[5]),
        'at'       => $r[6],
        'from'     => public_user(['id' => $r[7], 'full_name' => $r[8], 'avatar_color' => $r[9], 'role' => $r[10]]),
    ], $rows)]);
}

$code = (string) (method() === 'GET' ? ($_GET['u'] ?? '') : input('u', ''));
if (!preg_match('/^[A-Za-z0-9]{12}$/', $code)) {
    fail('This Pay-me QR is not valid.', 404);
}
$u = q("SELECT id, full_name, avatar_color, payment_method, payment_account, status FROM users
        WHERE pay_code = BINARY ? AND role = 'user'", [$code])->fetch();
if (!$u || $u['status'] !== 'active') {
    fail('This Pay-me QR is no longer valid. Ask them for their new one.', 404);
}
$isMe = (int) $u['id'] === $me['id'];

/** My open debts to this person, with what can be paid online right now. */
function debts_to(int $meId, int $toId): array
{
    $rows = q(
        "SELECT s.id, s.currency, s.amount, s.paid_amount, s.status, b.name AS bill_name, b.id AS bill_id FROM settlements s JOIN bills b ON b.id = s.bill_id
         WHERE s.from_user_id = ? AND s.to_user_id = ? AND s.status <> 'settled' ORDER BY s.created_at, s.id",
        [$meId, $toId]
    )->fetchAll();
    foreach ($rows as &$r) {
        $r['left'] = max(0, cents($r['amount']) - cents($r['paid_amount']));
        $r['open'] = open_cents($r);                      // minus parts already waiting for the receiver to confirm
        $r['online'] = $r['status'] === 'pending' && $r['currency'] === 'PHP' && $r['open'] > 0;
    }
    return $rows;
}

if (method() === 'POST') {
    if ($isMe) {
        fail('That’s your own pay page.', 409);
    }
    $first = first_name($u['full_name']);
    $pdo = db();
    switch (input('action', '')) {
        case 'pay_online':
            if (!paymongo_available()) {
                fail('Online payment isn’t set up on this server.', 503);
            }
            // An earlier checkout I never finished is replaced; one that was paid must be counted first.
            $stale = q(
                "SELECT sp.* FROM settlement_payments sp JOIN settlements s ON s.id = sp.settlement_id
                 WHERE sp.status = 'started' AND sp.paid_by = ? AND s.from_user_id = ? AND s.to_user_id = ?",
                [$me['id'], $me['id'], $u['id']]
            )->fetchAll();
            foreach (array_unique(array_filter(array_column($stale, 'paymongo_session'))) as $session) {
                try {
                    $paidOld = paymongo_paid_payment($session);
                } catch (PayMongoException $e) {
                    fail($e->getMessage(), 502);
                }
                if ($paidOld) {
                    fail('An earlier online payment went through — tap “Check payment status” to count it first.', 409);
                }
                q("DELETE FROM settlement_payments WHERE paymongo_session = ? AND status = 'started'", [$session]);
            }

            // Everything I owe this person that can be paid online, worked out here from the database (never from the client)
            $pdo->beginTransaction();
            $parts = [];
            foreach (array_column(array_filter(debts_to($me['id'], (int) $u['id']), fn ($d) => $d['online']), 'id') as $sid) {
                $locked = lock_settlement((int) $sid);   // in id order, so two requests can't deadlock
                $open = $locked['status'] === 'pending' && ($locked['currency'] ?? 'PHP') === 'PHP' ? open_cents($locked) : 0;
                if ($open > 0) {
                    $parts[] = [$locked, $open];
                }
            }
            $pdo->commit();
            if (!$parts) {
                fail('There’s nothing to pay online right now.', 409);
            }
            $total = array_sum(array_column($parts, 1));
            if ($total < PAYMONGO_MIN_CENTS) {
                fail('Online payment needs at least ' . money_str(PAYMONGO_MIN_CENTS) . '. Pay this one another way.', 422);
            }
            $bills = array_values(array_unique(array_map(fn ($p) => $p[0]['bill_name'], $parts)));
            $what = implode(', ', array_slice($bills, 0, 3)) . (count($bills) > 3 ? ' and ' . (count($bills) - 3) . ' more' : '');
            try {
                $checkout = paymongo_create_checkout(
                    $total,
                    mb_substr("Setlo: payment to $first", 0, 100),
                    mb_substr(first_name($me['full_name']) . " pays $first for $what", 0, 250),
                    absolute_url('pages/pay?u=' . $code . '&online=done'),
                    absolute_url('pages/pay?u=' . $code . '&online=cancelled')
                );
            } catch (PayMongoException $e) {
                fail($e->getMessage(), 502);
            }
            foreach ($parts as [$locked, $open]) {
                add_payment($locked, (int) $me['id'], $open, 'online', 'started', ['session' => $checkout['id']]);
            }
            json_ok(['checkout_url' => $checkout['url']]);

        case 'check_online':
            // Asks PayMongo whether the checkout was paid; never trusts the browser's return to the success page.
            $rows = q(
                "SELECT sp.* FROM settlement_payments sp JOIN settlements s ON s.id = sp.settlement_id
                 WHERE sp.status = 'started' AND sp.paid_by = ? AND s.from_user_id = ? AND s.to_user_id = ? ORDER BY sp.id",
                [$me['id'], $me['id'], $u['id']]
            )->fetchAll();
            if (!$rows) {
                fail('There’s no online payment waiting to be checked. If you already paid, it is counted — see My Settlements.', 409);
            }
            $bySession = [];
            foreach ($rows as $r) {
                $bySession[(string) $r['paymongo_session']][] = $r;
            }
            $debts = [];
            $total = 0;
            $via = '';
            foreach ($bySession as $session => $group) {
                try {
                    $paid = $session === '' ? null : paymongo_paid_payment($session);
                } catch (PayMongoException $e) {
                    fail($e->getMessage(), 502);
                }
                if (!$paid) {
                    continue;
                }
                if ($paid['amount'] !== online_session_cents($session)) {
                    error_log("[setlo] PayMongo amount mismatch on session $session: paid {$paid['amount']}, expected " . online_session_cents($session));
                    fail('The amount paid online doesn’t match this payment. Contact the app manager.', 409);
                }
                foreach ($group as $p) {
                    $done = count_online_payment($p, $paid);
                    if ($done) {
                        $debts[] = ['bill' => $done['bill'], 'amount' => pesos($done['cents']), 'payment_id' => $done['payment_id'], 'left' => pesos($done['left'])];
                        $total += $done['cents'];
                        $via = $done['via'];
                    }
                }
            }
            if (!$debts) {
                fail('PayMongo hasn’t received this payment yet. If you just paid, wait a moment and check again.', 409);
            }
            json_ok(['done' => true, 'total' => pesos($total), 'via' => $via, 'debts' => $debts]);

        case 'send_start':
            // Send any amount to this person with no debt behind it (PayMongo checkout). Counted by send_check.
            if (!paymongo_available()) {
                fail('Online payment isn’t set up on this server.', 503);
            }
            $raw = input('amount');
            if (!is_numeric($raw)) {
                fail('Enter how much to send.', 422, ['fields' => ['amount' => 'Enter an amount.']]);
            }
            $cents = cents($raw);
            if ($cents < PAYMONGO_MIN_CENTS || $cents > QR_SEND_MAX_CENTS) {
                $msg = 'Send between ' . money_str(PAYMONGO_MIN_CENTS) . ' and ' . money_str(QR_SEND_MAX_CENTS) . '.';
                fail($msg, 422, ['fields' => ['amount' => $msg]]);
            }
            $note = trim((string) preg_replace('/\s+/', ' ', (string) input('note', '')));
            if (preg_match('/[<>]/', $note) || mb_strlen($note) > 100) {
                fail('The note can’t have < or > and is at most 100 characters.', 422, ['fields' => ['note' => 'Up to 100 characters, no < or >.']]);
            }
            if ((int) q('SELECT COUNT(*) FROM qr_payments WHERE from_user_id = ? AND created_at > NOW() - INTERVAL 1 HOUR', [$me['id']])->fetchColumn() >= QR_SEND_PER_HOUR) {
                fail('That’s a lot of payments in a short time. Try again in a bit.', 429);
            }
            // An earlier checkout to this person that was never finished is replaced; one that was paid must be counted first.
            foreach (q("SELECT * FROM qr_payments WHERE from_user_id = ? AND to_user_id = ? AND status = 'started'", [$me['id'], $u['id']])->fetchAll() as $old) {
                try {
                    $paidOld = $old['paymongo_session'] ? paymongo_paid_payment($old['paymongo_session']) : null;
                } catch (PayMongoException $e) {
                    fail($e->getMessage(), 502);
                }
                if ($paidOld) {
                    fail('An earlier payment to ' . $first . ' went through — tap “Check payment status” to count it first.', 409);
                }
                q("UPDATE qr_payments SET status = 'cancelled' WHERE id = ? AND status = 'started'", [$old['id']]);   // kept: it still counts towards the hourly limit
            }
            try {
                $checkout = paymongo_create_checkout(
                    $cents,
                    mb_substr("Setlo: send money to $first", 0, 100),
                    mb_substr(first_name($me['full_name']) . " sends $first " . money_str($cents) . ($note !== '' ? " — $note" : ''), 0, 250),
                    absolute_url('pages/pay?u=' . $code . '&sent=done'),
                    absolute_url('pages/pay?u=' . $code . '&sent=cancelled')
                );
            } catch (PayMongoException $e) {
                fail($e->getMessage(), 502);
            }
            q('INSERT INTO qr_payments (from_user_id, to_user_id, amount, note, paymongo_session) VALUES (?, ?, ?, ?, ?)', [$me['id'], $u['id'], pesos($cents), $note === '' ? null : $note, $checkout['id']]);
            json_ok(['checkout_url' => $checkout['url']]);

        case 'send_check':
            // Asks PayMongo whether the checkout was paid; never trusts the browser's return to the success page.
            $row = q("SELECT * FROM qr_payments WHERE from_user_id = ? AND to_user_id = ? AND status = 'started' ORDER BY id DESC LIMIT 1", [$me['id'], $u['id']])->fetch();
            if (!$row) {
                fail('There’s no payment waiting to be checked. If you already paid, it is counted.', 409);
            }
            try {
                $paid = paymongo_paid_payment((string) $row['paymongo_session']);
            } catch (PayMongoException $e) {
                fail($e->getMessage(), 502);
            }
            if (!$paid) {
                fail('PayMongo hasn’t received this payment yet. If you just paid, wait a moment and check again.', 409);
            }
            if ($paid['amount'] !== cents($row['amount'])) {
                error_log("[setlo] PayMongo amount mismatch on qr payment {$row['id']}: paid {$paid['amount']}, expected " . cents($row['amount']));
                fail('The amount paid online doesn’t match this payment. Contact the app manager.', 409);
            }
            $via = online_via_name($paid['via'], $paid['detail']);
            $counted = q(
                "UPDATE qr_payments SET status = 'paid', paid_at = NOW(), payment_ref = ?, online_via = ?, online_detail = ? WHERE id = ? AND status = 'started'",
                [$paid['id'], $paid['via'], $paid['detail'], $row['id']]
            )->rowCount();
            if ($counted === 1) {
                notify((int) $u['id'], 'paid', first_name($me['full_name']) . ' sent you ' . money_str(cents($row['amount']), $row['currency']) . " via $via through your Pay-me QR" . ($row['note'] ? " — “{$row['note']}”" : '') . '.', 'payment-methods');
            }
            json_ok(['done' => true, 'amount' => (float) $row['amount'], 'via' => $via, 'note' => $row['note'], 'id' => (int) $row['id']]);
    }
    fail('Unknown action.', 404);
}

if (method() !== 'GET') {
    fail('Method not allowed.', 405);
}

$owe = $isMe ? [] : debts_to($me['id'], (int) $u['id']);
// One total per currency (debts to the same person can be in several)
$totals = [];
$online = 0;
foreach ($owe as $s) {
    $totals[$s['currency']] = ($totals[$s['currency']] ?? 0) + $s['left'];
    $online += $s['online'] ? $s['open'] : 0;
}
$started = !$isMe && (bool) q(
    "SELECT 1 FROM settlement_payments sp JOIN settlements s ON s.id = sp.settlement_id
     WHERE sp.status = 'started' AND sp.paid_by = ? AND s.from_user_id = ? AND s.to_user_id = ? LIMIT 1",
    [$me['id'], $me['id'], $u['id']]
)->fetchColumn();

json_ok([
    'person' => public_user($u) + [
        'payment_method'  => $u['payment_method'],
        'payment_account' => $u['payment_account'],
    ],
    'is_me'  => $isMe,
    'owe'    => array_map(fn ($s) => [
        'id'        => (int) $s['id'],
        'amount'    => pesos($s['left']),
        'currency'  => $s['currency'],
        'status'    => $s['status'],
        'bill_name' => $s['bill_name'],
        'bill_id'   => (int) $s['bill_id'],
        'online'    => $s['online'],          // can go in the PayMongo checkout
    ], $owe),
    'owe_totals' => array_map('pesos', $totals),
    // What the PayMongo checkout would charge, and whether it is available at all
    'online' => [
        'enabled' => paymongo_available(),
        'test'    => paymongo_test_mode(),
        'min'     => PAYMONGO_MIN_CENTS / 100,
        'total'   => pesos($online),
        'started' => $started,
        // "Send money" (any amount, no debt): limits, and whether one of mine is waiting to be checked
        'send'    => ['min' => PAYMONGO_MIN_CENTS / 100, 'max' => QR_SEND_MAX_CENTS / 100, 'started' => !$isMe && (bool) q("SELECT 1 FROM qr_payments WHERE from_user_id = ? AND to_user_id = ? AND status = 'started' LIMIT 1", [$me['id'], $u['id']])->fetchColumn()],
    ],
]);
