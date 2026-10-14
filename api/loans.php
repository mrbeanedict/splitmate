<?php
// Utang: money lent or borrowed outside any bill. Either side records it; it counts once the other person
// confirms. Change kept from a cash payment is an utang too (bills.change_payment_id), recorded by whoever kept it. It's kept as a two-person bill of kind 'loan' (draft = waiting to be confirmed, settling = being
// paid back, closed = paid back), so paying it back is a normal settlement: in parts, any method, installment
// interest, proof and receipts.
require __DIR__ . '/../includes/api.php';

$me = api_user();

/** One of my utang, as the Utang page shows it. */
function loan_row(array $b, array $me): array
{
    $lender = (int) $b['payer_id'];
    $other = (int) q('SELECT user_id FROM bill_members WHERE bill_id = ? AND user_id <> ? LIMIT 1', [$b['id'], $me['id']])->fetchColumn();
    $people = [];
    foreach (q('SELECT id, full_name, avatar_color, role, payment_method, payment_account, pay_code FROM users WHERE id IN (?, ?)', [$me['id'], $other])->fetchAll() as $u) {
        $people[(int) $u['id']] = public_user($u);
    }
    $s = q('SELECT * FROM settlements WHERE bill_id = ? ORDER BY id LIMIT 1', [$b['id']])->fetch();
    return [
        'id'            => (int) $b['id'],
        'purpose'       => $b['name'],
        'amount'        => (float) $b['loan_amount'],
        'currency'      => $b['currency'],
        'interest_rate' => $b['interest_rate'] === null ? null : (float) $b['interest_rate'],
        'status'        => $b['status'],
        'i_lent'        => $lender === $me['id'],
        'other'         => $people[$other] ?? null,
        'recorded_by_me'=> (int) $b['creator_id'] === $me['id'],
        // Extra cash kept instead of giving change (includes/payments.php record_change_utang): the lender handed it over
        'from_change'   => $b['change_payment_id'] !== null,
        'created_at'    => $b['created_at'],
        'settlement'    => $s ? [
            'id'          => (int) $s['id'],
            'status'      => $s['status'],
            'amount'      => (float) $s['amount'],
            'paid_amount' => (float) $s['paid_amount'],
            'remaining'   => pesos(max(0, cents($s['amount']) - cents($s['paid_amount']))),
        ] : null,
    ];
}

/** A loan I'm part of; fails for anything else. */
function my_loan(array $me): array
{
    $b = q("SELECT * FROM bills WHERE id = ? AND kind = 'loan'", [int_param('bill_id')])->fetch();
    if (!$b || !is_member((int) $b['id'], $me['id'])) {
        fail('Utang not found.', 404);
    }
    foreach (['id', 'creator_id', 'payer_id'] as $k) {
        $b[$k] = (int) $b[$k];
    }
    return $b;
}

if (method() === 'GET') {
    // ?scope=personal: only loans recorded between two people (My Debts & Repayments), not kept-change credits
    $personalOnly = ($_GET['scope'] ?? '') === 'personal' ? ' AND b.change_payment_id IS NULL' : '';
    $rows = q(
        "SELECT b.* FROM bills b JOIN bill_members m ON m.bill_id = b.id WHERE m.user_id = ? AND b.kind = 'loan'$personalOnly
         ORDER BY FIELD(b.status, 'draft', 'settling', 'closed'), b.created_at DESC",
        [$me['id']]
    )->fetchAll();
    $loans = array_map(fn ($b) => loan_row($b, $me), $rows);
    // What's still out each way, in my currency (others at today's market rate: 'estimated')
    $home = user_currency($me['id']);
    // Kept-change credits are counted apart from personal loans, so one debt never lands in two totals
    $totals = ['lent' => 0, 'borrowed' => 0, 'credit_in' => 0, 'credit_out' => 0];
    $estimated = false;
    foreach ($loans as $l) {
        if ($l['status'] !== 'settling' || !$l['settlement']) {
            continue;
        }
        $c = cents($l['settlement']['remaining']);
        if ($l['currency'] !== $home && $c) {
            $estimated = true;
            $c = fx_estimate($c, $l['currency'], $home)['cents'] ?? 0;
        }
        $bucket = $l['from_change'] ? ($l['i_lent'] ? 'credit_in' : 'credit_out') : ($l['i_lent'] ? 'lent' : 'borrowed');
        $totals[$bucket] += $c;
    }
    json_ok(['loans' => $loans, 'totals' => ['lent' => pesos($totals['lent']), 'borrowed' => pesos($totals['borrowed']), 'credit_in' => pesos($totals['credit_in']), 'credit_out' => pesos($totals['credit_out']), 'currency' => $home, 'estimated' => $estimated]]);
}

if (method() !== 'POST') {
    fail('Method not allowed.', 405);
}

$myName = first_name($me['full_name']);
switch (input('action', '')) {
    case 'create':
        // direction: lent (I gave them money) or borrowed (they gave me money).
        $otherId = int_param('person_id');
        $direction = input('direction', 'lent');
        if (!in_array($direction, ['lent', 'borrowed'], true)) {
            fail('Choose whether you lent or borrowed.', 422);
        }
        $other = q("SELECT id, full_name FROM users WHERE id = ? AND role = 'user' AND status = 'active'", [$otherId])->fetch();
        if (!$other || $otherId === $me['id']) {
            fail('Choose someone with a Setlo account.', 422, ['fields' => ['person' => 'Choose someone with a Setlo account.']]);
        }
        $purpose = trim(str_input('purpose', 120));
        require_valid(valid_bill_name($purpose), 'purpose');
        $cents = cents(input('amount', 0));
        // The money's currency: as sent, else the lender's own
        $lender = $direction === 'lent' ? $me['id'] : $otherId;
        $cur = input('currency') ? currency_param() : user_currency($lender);
        if ($cents < 100 || $cents > 100000000) {
            fail('Enter an amount from ' . money_str(100, $cur) . '.', 422, ['fields' => ['amount' => 'Enter an amount from ' . money_str(100, $cur) . '.']]);
        }
        // Installment interest is the lender's call, from the fixed INSTALLMENT_RATES.
        $rate = $direction === 'lent' ? installment_rate(input('interest_rate')) : null;
        $pdo = db();
        $pdo->beginTransaction();
        q("INSERT INTO bills (name, kind, loan_amount, creator_id, payer_id, status, interest_rate, currency) VALUES (?, 'loan', ?, ?, ?, 'draft', ?, ?)",
            [$purpose, pesos($cents), $me['id'], $lender, $rate, $cur]);
        $billId = (int) $pdo->lastInsertId();
        q('INSERT INTO bill_members (bill_id, user_id) VALUES (?, ?), (?, ?)', [$billId, $me['id'], $billId, $otherId]);
        notify($otherId, 'added', $direction === 'lent'
            ? "$myName says they lent you " . money_str($cents, $cur) . " ($purpose). Confirm it on the Utang page."
            : "$myName says they borrowed " . money_str($cents, $cur) . " from you ($purpose). Confirm it on the Utang page.", 'utang');
        $pdo->commit();
        json_ok(['loan' => loan_row(q('SELECT * FROM bills WHERE id = ?', [$billId])->fetch(), $me)]);

    case 'confirm':
        // The other side agrees it happened: the borrower now owes the lender.
        $b = my_loan($me);
        if ($b['creator_id'] === $me['id']) {
            fail('The other person confirms this one.', 403);
        }
        $pdo = db();
        $pdo->beginTransaction();
        if (q("UPDATE bills SET status = 'settling', settling_at = NOW() WHERE id = ? AND status = 'draft'", [$b['id']])->rowCount() === 0) {
            fail('This utang changed in the meantime. Refresh and try again.', 409);
        }
        $lender = $b['payer_id'];
        $borrower = (int) q('SELECT user_id FROM bill_members WHERE bill_id = ? AND user_id <> ?', [$b['id'], $lender])->fetchColumn();
        $names = first_names([$lender, $borrower]);
        q('INSERT INTO settlements (bill_id, from_user_id, to_user_id, currency, principal, amount, interest_rate) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$b['id'], $borrower, $lender, $b['currency'], $b['loan_amount'], $b['loan_amount'], $b['interest_rate']]);
        $sid = (int) $pdo->lastInsertId();
        log_event($sid, $me['id'], 'created', "{$names[$lender]} lent {$names[$borrower]} " . money_str(cents($b['loan_amount']), $b['currency']) . " ({$b['name']}). Confirmed by $myName.");
        apply_credits($borrower, $lender, $me['id'], $b['currency']);
        maybe_close_bill($b['id']);
        notify($b['creator_id'], 'confirmed', "$myName confirmed the " . money_str(cents($b['loan_amount']), $b['currency']) . " utang ({$b['name']}).", 'utang');
        $pdo->commit();
        break;

    case 'decline':
        // The other side says it didn't happen (or not like that): it's removed, and whoever recorded it is told why.
        $b = my_loan($me);
        if ($b['creator_id'] === $me['id'] || $b['status'] !== 'draft') {
            fail('Only an unconfirmed utang recorded by the other person can be declined.', 409);
        }
        $reason = trim(str_input('reason', 200, false));
        q("DELETE FROM bills WHERE id = ? AND status = 'draft'", [$b['id']]);
        notify($b['creator_id'], 'disputed', "$myName declined the " . money_str(cents($b['loan_amount']), $b['currency']) . " utang ({$b['name']})" . ($reason !== '' ? ": “{$reason}”" : '.'), 'utang');
        break;

    case 'cancel':
        // Recorded by mistake, before the other person confirmed.
        $b = my_loan($me);
        if ($b['creator_id'] !== $me['id'] || $b['status'] !== 'draft') {
            fail('Only an unconfirmed utang you recorded can be cancelled.', 409);
        }
        q("DELETE FROM bills WHERE id = ? AND status = 'draft'", [$b['id']]);
        break;

    default:
        fail('Unknown action.', 404);
}

json_ok();
