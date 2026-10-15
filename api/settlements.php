<?php
// Settling a debt, in one go or in parts, each part one of three ways:
//  - online:   payer pays through PayMongo Checkout; the part counts once PayMongo reports it paid.
//  - transfer: payer marks a part Paid (ref no. / screenshot), receiver Confirms or Rejects it.
//  - cash:     receiver taps "Cash received" for the amount handed over; only the receiver can, so nobody settles their own debt.
// Any member of the bill may also pay a part of someone else's debt, optionally to be paid back (a new debt to them).
require __DIR__ . '/../includes/api.php';
require __DIR__ . '/../includes/uploads.php';
require_once __DIR__ . '/../includes/paymongo.php';
require_once __DIR__ . '/../includes/payments.php';
require_once __DIR__ . '/../includes/reminders.php';

const NUDGE_COOLDOWN_HOURS = 12;

$me = api_user();

const SETTLEMENT_SELECT = 'SELECT s.*, ' . SETTLEMENT_ONLINE_STARTED . ', b.name AS bill_name, b.kind AS bill_kind, b.status AS bill_status, b.creator_id AS bill_creator_id, fu.full_name AS from_name, fu.avatar_color AS from_color, fu.role AS from_role,
    tu.full_name AS to_name, tu.avatar_color AS to_color, tu.payment_method AS to_method, tu.payment_account AS to_account, tu.pay_code AS to_pay_code
    FROM settlements s JOIN bills b ON b.id = s.bill_id
    JOIN users fu ON fu.id = s.from_user_id JOIN users tu ON tu.id = s.to_user_id';

function load_settlement(int $id, array $me): array
{
    $s = q(SETTLEMENT_SELECT . ' WHERE s.id = ?', [$id])->fetch();
    if (!$s) {
        fail('Settlement not found.', 404);
    }
    if ($me['role'] !== 'admin' && !is_member((int) $s['bill_id'], $me['id'])) {
        fail('You are not part of this settlement.', 403);
    }
    return $s;
}

/** The settlement as the UI shows it: row, payment parts, and what's open to pay now. */
function settlement_payload(int $id, array $me): array
{
    return settlement_rows_with_parts([load_settlement($id, $me)])[0];
}

if (method() === 'GET') {
    if (isset($_GET['proof']) || isset($_GET['payment_proof'])) {
        // Proof screenshots are private to the payer, the two parties (and the app manager).
        $p = isset($_GET['payment_proof'])
            ? q('SELECT * FROM settlement_payments WHERE id = ?', [int_param('payment_proof')])->fetch()
            : null;
        $s = load_settlement($p ? (int) $p['settlement_id'] : int_param('proof'), $me);
        $actsForGuest = $s['from_role'] === 'guest' && (int) $s['bill_creator_id'] === $me['id'];
        $allowed = [(int) $s['from_user_id'], (int) $s['to_user_id'], $p ? (int) $p['paid_by'] : 0];
        if ($me['role'] !== 'admin' && !$actsForGuest && !in_array($me['id'], $allowed, true)) {
            fail('Only the sender and receiver can view this proof.', 403);
        }
        $file = $p ? $p['proof_image'] : $s['proof_image'];
        $n = max(0, (int) ($_GET['n'] ?? 0));
        if ($p && $n > 0) {
            // Photos after the first are in settlement_payment_proofs, in the order they were attached
            $file = q('SELECT image FROM settlement_payment_proofs WHERE payment_id = ? ORDER BY position, id LIMIT 1 OFFSET ' . ($n - 1), [$p['id']])->fetchColumn();
            if (!$file) {
                fail('Photo not found.', 404);
            }
        }
        serve_upload('proofs', $file);
    }

    if (isset($_GET['receipt'])) {
        // The receipt of one received payment, for anyone on the bill (as its settlements already are; the group
        // trail lists them). Proof screenshots stay private to the payer and the two sides.
        $p = q("SELECT * FROM settlement_payments WHERE id = ? AND status = 'confirmed'", [int_param('receipt')])->fetch();
        if (!$p) {
            fail('There’s no receipt for this payment yet — it hasn’t been received.', 404);
        }
        $s = load_settlement((int) $p['settlement_id'], $me); // members of the bill (and the app manager) only
        json_ok(['receipt' => payment_receipt($p, $s)]);
    }

    if (isset($_GET['id'])) {
        $s = load_settlement(int_param('id'), $me);
        $events = q(
            'SELECT e.event, e.note, e.created_at, u.full_name AS actor FROM settlement_events e
             LEFT JOIN users u ON u.id = e.actor_id WHERE e.settlement_id = ? ORDER BY e.created_at, e.id',
            [$s['id']]
        )->fetchAll();
        $others = array_values(array_filter(bill_settlements((int) $s['bill_id']), fn ($o) => $o['id'] !== (int) $s['id']));
        // Who else is on the bill: the people who could have handed over cash for the debtor
        $members = array_map(fn ($u) => ['id' => (int) $u['id'], 'name' => $u['full_name'], 'first' => first_name($u['full_name']), 'is_guest' => $u['role'] === 'guest'],
            q('SELECT u.id, u.full_name, u.role FROM bill_members m JOIN users u ON u.id = m.user_id WHERE m.bill_id = ? AND u.id NOT IN (?, ?) ORDER BY u.full_name', [$s['bill_id'], $s['from_user_id'], $s['to_user_id']])->fetchAll());
        json_ok(['settlement' => settlement_rows_with_parts([$s])[0], 'events' => $events, 'others' => $others, 'members' => $members]);
    }

    // Includes settlements of guests on bills I created — I settle on their behalf. Leaves out bills I archived.
    $rows = q(SETTLEMENT_SELECT . " WHERE (s.from_user_id = ? OR s.to_user_id = ? OR (fu.role = 'guest' AND b.creator_id = ?))
        AND NOT EXISTS (SELECT 1 FROM bill_members am WHERE am.bill_id = s.bill_id AND am.user_id = ? AND am.archived_at IS NOT NULL)
        ORDER BY FIELD(s.status, 'awaiting', 'disputed', 'pending', 'settled'), s.created_at DESC", [$me['id'], $me['id'], $me['id'], $me['id']])->fetchAll();
    // Other members' open debts on my bills, which I could pay for them.
    $others = q(SETTLEMENT_SELECT . " WHERE s.status IN ('pending','awaiting') AND s.from_user_id <> ? AND s.to_user_id <> ?
        AND NOT (fu.role = 'guest' AND b.creator_id = ?)
        AND EXISTS (SELECT 1 FROM bill_members m WHERE m.bill_id = s.bill_id AND m.user_id = ?)
        ORDER BY s.created_at DESC", [$me['id'], $me['id'], $me['id'], $me['id']])->fetchAll();
    // When I last sent a reminder on each settlement, so the UI can show the cooldown.
    $nudged = q(
        "SELECT settlement_id, MAX(created_at) FROM settlement_events WHERE actor_id = ? AND event = 'nudged' GROUP BY settlement_id",
        [$me['id']]
    )->fetchAll(PDO::FETCH_KEY_PAIR);
    $owe = $owed = [];
    $home = user_currency($me['id']);
    foreach (settlement_rows_with_parts($rows) as $row) {
        $row['my_last_nudge'] = $nudged[$row['id']] ?? null;
        // A debt in another currency: roughly what's left in mine, at today's rate (settled ones keep their snapshot)
        $est = $row['currency'] !== $home && $row['status'] !== 'settled' ? fx_estimate(cents($row['remaining']), $row['currency'], $home) : null;
        $row['estimate'] = $est ? ['currency' => $home, 'amount' => pesos($est['cents']), 'rate' => $est['rate'], 'source' => $est['source'], 'at' => $est['at']] : null;
        if ($row['to']['id'] === $me['id']) {
            $owed[] = $row;
        } else {
            $owe[] = $row; // mine, or a guest's I pay on behalf of
        }
    }
    $cover = array_values(array_filter(settlement_rows_with_parts($others), fn ($r) => $r['open'] > 0 || $r['online_started']));
    json_ok(['owe' => $owe, 'owed' => $owed, 'others_open' => $cover, 'currency' => $home]);
}

if (method() !== 'POST') {
    fail('Method not allowed.', 405);
}

$s = load_settlement(int_param('id'), $me);
$id = (int) $s['id'];
$fromGuest = $s['from_role'] === 'guest';
// The Bill Creator acts for guests, who have no account.
$isSender = (int) $s['from_user_id'] === $me['id'] || ($fromGuest && (int) $s['bill_creator_id'] === $me['id']);
$onBehalf = $fromGuest ? ' on behalf of ' . first_name($s['from_name']) : '';
$isReceiver = (int) $s['to_user_id'] === $me['id'];
$myName = first_name($me['full_name']);
$debtorName = first_name($s['from_name']);
$receiverName = first_name($s['to_name']);
// Paying for someone else: any member of the bill who is neither side of this debt.
$covering = !$isSender && !$isReceiver && in_array(input('for_other'), ['1', 1, true], true) && is_member((int) $s['bill_id'], $me['id']);

/** The amount for this part, in cents: what was sent, or everything still open; between ₱1 (or $min) and what's open. */
function part_cents(array $s, int $min = 100): int
{
    $open = open_cents($s);
    if ($open <= 0) {
        fail('Nothing is left to pay right now — a payment is already waiting for confirmation.', 409);
    }
    $raw = input('amount');
    $cents = $raw === null || $raw === '' ? $open : cents($raw);
    if ($cents > $open) {
        fail('That’s more than the ' . s_money($s, $open) . ' still to pay.', 422, ['fields' => ['amount' => 'At most ' . s_money($s, $open) . '.']]);
    }
    if ($cents < min($min, $open)) {
        fail('Pay at least ' . s_money($s, min($min, $open)) . '.', 422, ['fields' => ['amount' => 'At least ' . s_money($s, min($min, $open)) . '.']]);
    }
    return $cents;
}

$pdo = db();
switch (input('action', '')) {
    case 'mark_paid':
        if (!$isSender && !$covering) {
            fail('Only the person who owes can mark this as paid.', 403);
        }
        $ref = trim(str_input('payment_ref', 60, false));
        require_valid(valid_ref($ref), 'payment_ref');
        // Up to 5 photos (proofs[]); the single "proof" field still works for older clients
        $proofs = save_uploaded_images('proofs', 'proofs', 'settlement' . $id, MAX_PROOF_PHOTOS);
        if (!$proofs && ($single = save_uploaded_image('proof', 'proofs', 'settlement' . $id, false))) {
            $proofs = [$single];
        }
        $proof = $proofs[0] ?? null;
        $pdo->beginTransaction();
        try {
            $locked = lock_settlement($id);
            if (!in_array($locked['status'], ['pending', 'awaiting'], true)) {
                fail($locked['status'] === 'disputed' ? 'Review the dispute and resend first.' : 'This settlement isn’t waiting for payment.', 409);
            }
            $cents = part_cents($locked);
            $paidBy = $covering ? $me['id'] : (int) $s['from_user_id'];
            $paymentId = add_payment($locked, $paidBy, $cents, 'transfer', 'awaiting', ['ref' => $ref ?: null, 'proof' => $proof, 'pay_back' => $covering && input('pay_back')]);
            foreach (array_slice($proofs, 1) as $i => $moreProof) { // not $extra: the reply below uses that name for its own data
                q('INSERT INTO settlement_payment_proofs (payment_id, position, image) VALUES (?, ?, ?)', [$paymentId, $i + 1, $moreProof]);
            }
        } catch (Throwable $e) {
            $pdo->rollBack();
            foreach ($proofs as $file) {
                delete_upload('proofs', $file);
            }
            throw $e;
        }
        $details = array_filter([$ref ? "ref no. $ref" : null, $proofs ? (count($proofs) > 1 ? count($proofs) . ' proof photos attached' : 'proof attached') : null]);
        $who = $covering ? "$myName (for $debtorName)" : "$myName$onBehalf";
        log_event($id, $me['id'], 'marked_paid', "$who marked " . part_words($cents, $locked) . " as sent to $receiverName" . ($details ? ' (' . implode(', ', $details) . ')' : '') . '.');
        if ($covering) {
            log_event($id, $me['id'], 'covered', "$myName is paying " . s_money($s, $cents) . " of {$debtorName}’s debt" . (input('pay_back') ? "; $debtorName will pay $myName back." : ' as a treat.'));
        }
        refresh_settlement_status($id);
        notify((int) $s['to_user_id'], 'paid', "$who marked " . s_money($s, $cents) . " as Paid for {$s['bill_name']}. Confirm you received it.", 'my-settlements?tab=owed');
        if ($covering) {
            notify((int) $s['from_user_id'], 'paid', "$myName is paying " . s_money($s, $cents) . " of your debt to $receiverName for {$s['bill_name']}" . (input('pay_back') ? ". You'll owe $myName that instead once it's confirmed (in Utang)." : ' as a treat.'), 'my-settlements');
        }
        $pdo->commit();
        break;

    case 'add_proof':
        // More proof photos on a payment that's already been made (also an online one, or one that's settled)
        $part = q('SELECT * FROM settlement_payments WHERE id = ? AND settlement_id = ?', [int_param('payment_id'), $id])->fetch();
        if (!$part || !in_array($part['status'], ['awaiting', 'confirmed'], true)) {
            fail('That payment can’t take proof photos.', 409);
        }
        if ((int) $part['paid_by'] !== $me['id'] && !$isSender) {
            fail('Only the person who paid can add proof.', 403);
        }
        $have = ($part['proof_image'] ? 1 : 0) + (int) q('SELECT COUNT(*) FROM settlement_payment_proofs WHERE payment_id = ?', [$part['id']])->fetchColumn();
        $room = MAX_PROOF_PHOTOS - $have;
        if ($room < 1) {
            fail('This payment already has the maximum of ' . MAX_PROOF_PHOTOS . ' proof photos.', 409);
        }
        $files = save_uploaded_images('proofs', 'proofs', 'settlement' . $id, $room);
        if (!$files) {
            fail('Choose at least one photo.', 422);
        }
        try {
            store_payment_proofs((int) $part['id'], $files);
        } catch (Throwable $e) {
            foreach ($files as $file) {
                delete_upload('proofs', $file);
            }
            throw $e;
        }
        log_event($id, $me['id'], 'proof_added', "$myName added " . (count($files) === 1 ? 'a proof photo' : count($files) . ' proof photos') . ' to the ' . part_words(cents($part['amount']), $s) . ' payment.');
        if (!$isReceiver) {
            notify((int) $s['to_user_id'], 'paid', "$myName added proof of payment for {$s['bill_name']}.", 'my-settlements?tab=owed');
        }
        break;

    case 'confirm':
        if (!$isReceiver) {
            fail('Only the receiver can confirm this payment.', 403);
        }
        $pdo->beginTransaction();
        $locked = lock_settlement($id);
        $p = load_payment($id, input('payment_id') ? (int) input('payment_id') : null, 'awaiting');
        confirm_payment($locked, $p, $me['id']);
        $left = settlement_payload($id, $me)['remaining'];
        log_event($id, $me['id'], 'confirmed', "$myName confirmed receiving " . s_money($s, cents($p['amount'])) . '.' . ($left > 0 ? ' ' . s_money($s, cents($left)) . ' still to pay.' : ' Settlement closed.'));
        foreach (array_unique([(int) $s['from_user_id'], (int) $p['paid_by']]) as $uid) {
            notify($uid, 'confirmed', "$myName confirmed your " . s_money($s, cents($p['amount'])) . " payment for {$s['bill_name']}.", 'settlement-audit?id=' . $id);
        }
        $pdo->commit();
        break;

    case 'reject':
        if (!$isReceiver) {
            fail('Only the receiver can reject this payment.', 403);
        }
        $reason = str_input('reason', 500);
        if (strlen($reason) < 5) {
            fail('Please explain in at least 5 characters.', 422, ['fields' => ['reason' => 'Please explain in at least 5 characters.']]);
        }
        $pdo->beginTransaction();
        $locked = lock_settlement($id);
        $p = load_payment($id, input('payment_id') ? (int) input('payment_id') : null, 'awaiting');
        reject_payment($locked, $p, $reason);
        log_event($id, $me['id'], 'disputed', $reason);
        foreach (array_unique([(int) $s['from_user_id'], (int) $p['paid_by']]) as $uid) {
            notify($uid, 'disputed', "$myName disputed the " . s_money($s, cents($p['amount'])) . " payment for {$s['bill_name']}.", 'my-settlements');
        }
        $pdo->commit();
        break;

    case 'resend':
        if (!$isSender) {
            fail('Only the sender can resend this payment.', 403);
        }
        $pdo->beginTransaction();
        if (q("UPDATE settlements SET status = 'pending' WHERE id = ? AND status = 'disputed'", [$id])->rowCount() === 0) {
            fail('This settlement changed in the meantime. Refresh and try again.', 409);
        }
        refresh_settlement_status($id);
        log_event($id, $me['id'], 'resent', "$myName reviewed the dispute and reopened the payment.");
        notify((int) $s['to_user_id'], 'resent', "$myName reopened the disputed payment for {$s['bill_name']}.", 'my-settlements?tab=owed');
        $pdo->commit();
        break;

    case 'settle_cash':
    case 'record': // older name, used when only guests' cash could be recorded
        // Paid in person, in full or in part. No proof is possible, so only the receiver can record it.
        if (!$isReceiver) {
            fail('Only the person who received the cash can settle this.', 403);
        }
        $pdo->beginTransaction();
        $locked = lock_settlement($id);
        if ($locked['status'] === 'settled') {
            fail('This settlement is already settled.', 409);
        }
        // amount = cash handed over. Beyond what's open, the extra was given back as change, or kept: then I owe it
        // back as an utang (record_change_utang()), used up first against what they owe me.
        $open = open_cents($locked);
        if ($open <= 0) {
            fail('Nothing is left to pay right now — a payment is already waiting for confirmation.', 409);
        }
        $raw = input('amount');
        $tendered = $raw === null || $raw === '' ? $open : cents($raw);
        if ($tendered < min(100, $open)) {
            fail('Enter at least ' . s_money($s, min(100, $open)) . '.', 422, ['fields' => ['amount' => 'At least ' . s_money($s, min(100, $open)) . '.']]);
        }
        $cents = min($tendered, $open);
        $extra = $tendered - $cents;
        $keep = in_array(input('extra', 'change'), ['keep', 'credit'], true); // 'credit': older pages
        // Cash handed over by someone else on the bill for the debtor (paid_by): they're recorded as the payer, and the
        // debtor owes them back through Utang unless it was a treat. Change is always given back in that case.
        $payerId = (int) $s['from_user_id'];
        $otherPayer = (int) input('paid_by', 0);
        if ($otherPayer && $otherPayer !== $payerId) {
            if ($otherPayer === $me['id'] || !is_member((int) $s['bill_id'], $otherPayer)) {
                fail('Choose someone on this bill who handed over the cash.', 422);
            }
            $payerId = $otherPayer;
            $keep = false;
        }
        $payBack = $payerId !== (int) $s['from_user_id'] && in_array(input('pay_back', '1'), ['1', 1, true], true);
        if ($extra > 0 && $keep && $fromGuest) {
            fail('A guest has no account to keep change for — give it back as change.', 422);
        }
        $over = $extra > 0 ? ['tendered' => $tendered] + ($keep ? ['credit' => $extra] : ['change' => $extra]) : [];
        $pid = add_payment($locked, $payerId, $cents, 'cash', 'awaiting', $over + ['pay_back' => $payBack]);
        $payer = $payerId === (int) $s['from_user_id'] ? $debtorName . ($fromGuest ? ' (guest)' : '') : first_names([$payerId])[$payerId] . " (for $debtorName)";
        log_event($id, $me['id'], 'marked_paid', "$myName received " . part_words($cents, $locked) . " in cash from $payer"
            . ($extra > 0 ? ' (handed over ' . s_money($s, $tendered) . '; ' . s_money($s, $extra) . ($keep ? ' kept — owed back as utang' : ' given back as change') . ')' : '') . '.');
        confirm_payment($locked, ['id' => $pid, 'amount' => pesos($cents), 'paid_by' => $payerId, 'pay_back' => (int) $payBack], $me['id']);
        if ($extra > 0 && $keep) {
            record_change_utang((int) $s['from_user_id'], $me['id'], $extra, $pid, $s['bill_name'], $me['id'], $s['currency']);
            $used = apply_credits((int) $s['from_user_id'], $me['id'], $me['id'], $s['currency']);
            $still = $extra - $used;
            notify((int) $s['from_user_id'], 'confirmed', "$myName kept " . s_money($s, $extra) . " change from your cash"
                . ($used ? ' — ' . s_money($s, $used) . ' already paid your other debts to them' : '')
                . ($still > 0 ? '. They owe you ' . s_money($s, $still) . ' (in Utang); it pays your next debts to them, or they pay it back.' : '.'), 'utang');
        }
        // Receiving cash ends a dispute about an earlier part.
        if ($locked['status'] === 'disputed') {
            q("UPDATE settlements SET status = 'pending' WHERE id = ? AND status = 'disputed'", [$id]);
            refresh_settlement_status($id);
        }
        $left = settlement_payload($id, $me)['remaining'];
        log_event($id, $me['id'], 'confirmed', "$myName confirmed receiving the cash." . ($left > 0 ? ' ' . s_money($s, cents($left)) . ' still to pay.' : ' Settlement closed.'));
        if ($payerId === (int) $s['from_user_id']) {
            notify($payerId, 'confirmed', "$myName confirmed receiving your " . s_money($s, $cents) . " cash for {$s['bill_name']}.", 'settlement-audit?id=' . $id);
        } else {
            // Cash from someone else: both are told who paid (a guest debtor has no account, so only the payer hears)
            $payerFirst = first_names([$payerId])[$payerId];
            notify($payerId, 'confirmed', "$myName confirmed receiving your " . s_money($s, $cents) . " cash for $debtorName’s part of {$s['bill_name']}.", 'settlement-audit?id=' . $id);
            if (!$payBack) {
                notify((int) $s['from_user_id'], 'confirmed', "$payerFirst paid " . s_money($s, $cents) . " cash of your debt to $myName for {$s['bill_name']} as a treat.", 'settlement-audit?id=' . $id);
            }
        }
        $pdo->commit();
        break;

    case 'pay_online':
        // Opens a PayMongo checkout page for this part; it counts only after PayMongo confirms payment (check_online).
        if (!$isSender && !$covering) {
            fail('Only the person who owes can pay this.', 403);
        }
        if (($s['currency'] ?? 'PHP') !== 'PHP') {
            fail("Online payment (PayMongo) only takes pesos, and this debt is in {$s['currency']}. Send it another way and mark it paid.", 422);
        }
        $paidBy = $covering ? $me['id'] : (int) $s['from_user_id'];
        $pdo->beginTransaction();
        $locked = lock_settlement($id);
        if (!in_array($locked['status'], ['pending', 'awaiting'], true)) {
            fail('This settlement isn’t waiting for payment.', 409);
        }
        // An earlier checkout of mine that was never paid is replaced (one that was paid is checked first, below).
        $stale = q("SELECT * FROM settlement_payments WHERE settlement_id = ? AND paid_by = ? AND status = 'started'", [$id, $paidBy])->fetchAll();
        $pdo->commit();
        foreach ($stale as $old) {
            try {
                $paidOld = paymongo_paid_payment($old['paymongo_session']);
            } catch (PayMongoException $e) {
                fail($e->getMessage(), 502);
            }
            if ($paidOld) {
                fail('An earlier online payment for this went through — tap “Check payment status” to count it first.', 409);
            }
            // The whole checkout goes, also the other debts it covered (a combined one from the Pay page), and any photos attached to it
            $gone = q("SELECT id FROM settlement_payments WHERE status = 'started' AND (id = ? OR (paymongo_session IS NOT NULL AND paymongo_session = ?))", [$old['id'], $old['paymongo_session']])->fetchAll(PDO::FETCH_COLUMN);
            delete_payment_proof_files(array_map('intval', $gone));
            q("DELETE FROM settlement_payments WHERE status = 'started' AND (id = ? OR (paymongo_session IS NOT NULL AND paymongo_session = ?))", [$old['id'], $old['paymongo_session']]);
        }
        $cents = part_cents($locked, PAYMONGO_MIN_CENTS);
        if ($cents < PAYMONGO_MIN_CENTS) {
            fail('Online payment needs at least ' . s_money($s, PAYMONGO_MIN_CENTS) . '. Pay this one another way.', 422);
        }
        // Photos attached in the pay window ride along: they're saved with this payment and stay once PayMongo confirms it
        $proofs = save_uploaded_images('proofs', 'proofs', 'settlement' . $id, MAX_PROOF_PHOTOS);
        try {
            $checkout = paymongo_create_checkout(
                $cents,
                "Setlo: {$s['bill_name']}",
                ($covering ? "$myName pays for $debtorName" : $debtorName . ' pays') . ' ' . $s['to_name'] . " for {$s['bill_name']}",
                // No settlement ID in the return URL: the page re-checks every online payment in progress.
                absolute_url('pages/my-settlements?online=done'),
                absolute_url('pages/my-settlements?online=cancelled')
            );
        } catch (PayMongoException $e) {
            foreach ($proofs as $file) {
                delete_upload('proofs', $file);
            }
            fail($e->getMessage(), 502);
        }
        try {
            $paymentId = add_payment($locked, $paidBy, $cents, 'online', 'started', ['session' => $checkout['id'], 'pay_back' => $covering && input('pay_back')]);
            store_payment_proofs($paymentId, $proofs);
        } catch (Throwable $e) {
            foreach ($proofs as $file) {
                delete_upload('proofs', $file);
            }
            throw $e;
        }
        json_ok(['checkout_url' => $checkout['url']]);

    case 'check_online':
        // Asks PayMongo whether the checkouts were paid; never trusts the browser's return to the success page.
        $started = q("SELECT * FROM settlement_payments WHERE settlement_id = ? AND status = 'started' ORDER BY id", [$id])->fetchAll();
        if (!$isSender && !$isReceiver && !in_array($me['id'], array_map(fn ($p) => (int) $p['paid_by'], $started), true)) {
            fail('You are not part of this settlement.', 403);
        }
        if (!$started) {
            if ($s['status'] === 'settled') {
                break; // already done (e.g. both sides checked at once)
            }
            fail('There’s no online payment to check for this settlement.', 409);
        }
        $counted = 0;
        foreach ($started as $p) {
            try {
                $paid = paymongo_paid_payment($p['paymongo_session']);
            } catch (PayMongoException $e) {
                fail($e->getMessage(), 502);
            }
            if (!$paid) {
                continue;
            }
            // One checkout can cover several debts (the Pay page): PayMongo reports the total, so compare with all of them
            if ($paid['amount'] !== online_session_cents($p['paymongo_session'])) {
                error_log("[setlo] PayMongo amount mismatch on settlement payment {$p['id']}: paid {$paid['amount']}, expected " . online_session_cents($p['paymongo_session']));
                fail('The amount paid online doesn’t match this payment. Contact the app manager.', 409);
            }
            if (count_online_payment($p, $paid)) {
                $counted++;
            }
        }
        if (!$counted) {
            fail('PayMongo hasn’t received this payment yet. If you just paid, wait a moment and check again.', 409);
        }
        break;

    case 'nudge':
        // Receiver reminds the sender to pay; sender reminds the receiver to confirm.
        if ($fromGuest) {
            fail('Guests don’t have an account, so they can’t get reminders.', 409);
        }
        if ($isReceiver && $s['status'] === 'pending') {
            $kind = 'manual';
        } elseif ($isSender && $s['status'] === 'awaiting') {
            $kind = 'confirm';
        } else {
            fail('Nothing to remind about right now.', 409);
        }
        $recent = q(
            "SELECT 1 FROM settlement_events WHERE settlement_id = ? AND actor_id = ? AND event = 'nudged' AND created_at > NOW() - INTERVAL " . NUDGE_COOLDOWN_HOURS . ' HOUR',
            [$id, $me['id']]
        )->fetchColumn();
        if ($recent) {
            fail('You already sent a reminder recently. Try again later.', 429);
        }
        log_event($id, $me['id'], 'nudged', "$myName sent a reminder.");
        // In-app and, when SMTP is set up and they haven't opted out, an email (includes/reminders.php)
        $sent = send_settlement_reminder($id, $kind, $myName);
        $extra = ['emailed' => $sent['emailed']];
        break;

    case 'set_due':
        // The person owed sets (or clears) when it should be paid by. Reminders follow it (tools/send-reminders.php).
        if (!$isReceiver || !in_array($s['status'], ['pending', 'awaiting'], true)) {
            fail('Only the person owed can set a due date, while it is still open.', 403);
        }
        $due = trim((string) input('due_on', ''));
        if ($due !== '') {
            $t = strtotime($due);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) || !$t || date('Y-m-d', $t) !== $due) {
                fail('Pick a valid date.', 422);
            }
            if ($due < date('Y-m-d')) {
                fail('The due date can’t be in the past.', 422);
            }
            if ($t > strtotime('+2 years')) {
                fail('Pick a date within the next 2 years.', 422);
            }
        }
        q('UPDATE settlements SET due_on = ?, auto_reminded_at = NULL WHERE id = ?', [$due === '' ? null : $due, $id]);
        log_event($id, $me['id'], 'due_set', $due === '' ? "$myName cleared the due date." : "$myName set the due date to " . date('M j, Y', strtotime($due)) . '.');
        $emailed = false;
        if ($due !== '' && !$fromGuest) {
            $emailed = send_settlement_reminder($id, 'due_set', $myName)['emailed'];
        }
        $extra = ['emailed' => $emailed];
        break;

    default:
        fail('Unknown action.', 404);
}

json_ok(['settlement' => settlement_payload($id, $me)] + ($extra ?? []));
