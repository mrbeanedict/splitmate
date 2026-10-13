<?php
// My purchases: one person's own expenses (what they bought, when, how much, where, the receipt's details), kept
// outside any bill. A purchase is paid already, or still to pay and settled later; a receipt can be scanned (the same
// scanner the bills use) to fill it in. Every change leaves a line in the journal. Spending (api/stats.php) adds the
// purchases up next to the bill shares. Amounts are in my home currency.
require __DIR__ . '/../includes/api.php';
require __DIR__ . '/../includes/uploads.php';
require __DIR__ . '/../includes/ocr.php';

const PURCHASE_CATEGORIES = ['Food & drinks', 'Groceries', 'Transport', 'Shopping', 'Bills & utilities', 'Health', 'Entertainment', 'Education', 'Other'];
const PURCHASE_METHODS = ['Cash', 'GCash', 'Maya', 'Card', 'Bank transfer', 'Other'];

$me = api_user();

function purchase_row(array $p): array
{
    return [
        'id'             => (int) $p['id'],
        'title'          => $p['title'],
        'category'       => $p['category'],
        'status'         => $p['status'],
        'amount'         => (float) $p['amount'],
        'date'           => $p['purchased_on'],
        'due'            => $p['due_on'],
        'paid_on'        => $p['status'] === 'paid' ? ($p['paid_on'] ?: $p['purchased_on']) : null,
        'overdue'        => $p['status'] === 'unpaid' && $p['due_on'] !== null && $p['due_on'] < date('Y-m-d'),
        'merchant'       => $p['merchant'],
        'receipt_no'     => $p['receipt_no'],
        'payment_method' => $p['payment_method'],
        'note'           => $p['note'],
        'has_receipt'    => (bool) $p['receipt_image'],
    ];
}

/** A line in the journal. The title and amount are copied in, so the line still reads right after a delete. */
function journal(array $me, string $event, array $p, ?string $detail = null): void
{
    q('INSERT INTO purchase_events (user_id, purchase_id, event, title, amount, detail) VALUES (?, ?, ?, ?, ?, ?)',
        [$me['id'], $p['id'] ?? null, $event, mb_substr($p['title'], 0, 80), $p['amount'], $detail]);
}

/** A valid date (Y-m-d) or null; with $notFuture, a later day than today is refused. */
function valid_day(string $v, bool $notFuture): ?string
{
    $d = DateTime::createFromFormat('Y-m-d', $v);
    if (!$d || $d->format('Y-m-d') !== $v || ($notFuture && $v > date('Y-m-d'))) {
        return null;
    }
    return $v;
}

/** The fields of a purchase from the request, checked (the page checks them first too). */
function purchase_fields(array $me): array
{
    $text = fn (string $k, int $max) => ($v = trim(preg_replace('/\s+/', ' ', str_input($k, $max, false)))) === '' ? null : $v;
    $title = $text('title', 80);
    if ($title === null || mb_strlen($title) < 2 || preg_match('/[<>]/', $title)) {
        fail('Say what you bought (2–80 characters, no < or >).', 422, ['fields' => ['title' => 'Say what you bought (2–80 characters, no < or >).']]);
    }
    $category = (string) input('category', '');
    if (!in_array($category, PURCHASE_CATEGORIES, true)) {
        fail('Pick a category.', 422, ['fields' => ['category' => 'Pick a category.']]);
    }
    $amount = input('amount');
    if (!is_numeric($amount) || (float) $amount <= 0 || (float) $amount > 9999999.99) {
        fail('Enter the amount you paid.', 422, ['fields' => ['amount' => 'Enter the amount (more than 0).']]);
    }
    $date = valid_day((string) input('date', ''), true);
    if (!$date) {
        fail('Pick the date of the purchase (not in the future).', 422, ['fields' => ['date' => 'Pick the date (not in the future).']]);
    }
    $status = input('status', 'paid') === 'unpaid' ? 'unpaid' : 'paid';
    $due = null;
    if ($status === 'unpaid' && (string) input('due', '') !== '') {
        $due = valid_day((string) input('due'), false);
        if (!$due) {
            fail('Pick a valid due date.', 422, ['fields' => ['due' => 'Pick a valid due date.']]);
        }
    }
    $method = $text('payment_method', 30);
    if ($method !== null && !in_array($method, PURCHASE_METHODS, true)) {
        fail('Pick how you paid.', 422);
    }
    $merchant = $text('merchant', 60);
    $receiptNo = $text('receipt_no', 40);
    $note = $text('note', 300);
    foreach ([$note, $merchant, $receiptNo] as $v) {
        if ($v !== null && preg_match('/[<>]/', $v)) {
            fail('No < or > please.', 422);
        }
    }
    return ['title' => $title, 'category' => $category, 'status' => $status, 'amount' => round((float) $amount, 2), 'purchased_on' => $date, 'due_on' => $due,
        'merchant' => $merchant, 'receipt_no' => $receiptNo, 'payment_method' => $method, 'note' => $note];
}

/** A scanned receipt photo of mine, by name (it must be one this person's scan stored). */
function my_receipt_image(array $me, string $name): ?string
{
    if ($name === '') {
        return null;
    }
    if (!preg_match('/^solo' . $me['id'] . '_[a-f0-9]{16}\.(jpg|png|webp)$/', $name) || !is_file(upload_dir('receipts') . '/' . $name)) {
        fail('That receipt photo is no longer available. Scan it again.', 422);
    }
    return $name;
}

function my_purchase(array $me): array
{
    $p = q('SELECT * FROM purchases WHERE id = ? AND user_id = ?', [int_param('id'), $me['id']])->fetch();
    if (!$p) {
        fail('Purchase not found.', 404);
    }
    return $p;
}

if (method() === 'GET') {
    if (isset($_GET['image'])) {
        serve_upload('receipts', my_purchase($me)['receipt_image']);
    }

    if (($_GET['view'] ?? '') === 'journal') {
        // Newest first, 40 at a time; `before` is the id of the last line already shown
        $before = (int) ($_GET['before'] ?? 0);
        $rows = q('SELECT * FROM purchase_events WHERE user_id = ?' . ($before ? ' AND id < ?' : '') . ' ORDER BY id DESC LIMIT 41',
            $before ? [$me['id'], $before] : [$me['id']])->fetchAll();
        $more = count($rows) > 40;
        json_ok([
            'currency' => user_currency($me['id']),
            'more'     => $more,
            'events'   => array_map(fn ($e) => [
                'id'          => (int) $e['id'],
                'purchase_id' => $e['purchase_id'] === null ? null : (int) $e['purchase_id'],
                'event'       => $e['event'],
                'title'       => $e['title'],
                'amount'      => (float) $e['amount'],
                'detail'      => $e['detail'],
                'at'          => $e['created_at'],
            ], array_slice($rows, 0, 40)),
        ]);
    }

    $month = (string) ($_GET['month'] ?? date('Y-m'));
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
        $month = date('Y-m');
    }
    $from = "$month-01";
    $to = date('Y-m-d', strtotime("$from +1 month"));
    $rows = q('SELECT * FROM purchases WHERE user_id = ? AND purchased_on >= ? AND purchased_on < ? ORDER BY purchased_on DESC, id DESC', [$me['id'], $from, $to])->fetchAll();
    $byCat = [];
    $total = 0;
    foreach ($rows as $p) {
        $byCat[$p['category']] = ($byCat[$p['category']] ?? 0) + cents($p['amount']);
        $total += cents($p['amount']);
    }
    arsort($byCat);
    $prev = (int) q('SELECT COALESCE(SUM(ROUND(amount * 100)), 0) FROM purchases WHERE user_id = ? AND purchased_on >= ? AND purchased_on < ?', [$me['id'], date('Y-m-d', strtotime("$from -1 month")), $from])->fetchColumn();
    // Still to pay, whatever the month: the earliest due first
    $unpaid = q("SELECT * FROM purchases WHERE user_id = ? AND status = 'unpaid' ORDER BY due_on IS NULL, due_on, purchased_on, id", [$me['id']])->fetchAll();
    json_ok([
        'month'       => $month,
        'currency'    => user_currency($me['id']),
        'total'       => pesos($total),
        'last_month'  => pesos($prev),
        'count'       => count($rows),
        'by_category' => array_map(fn ($k, $v) => ['category' => $k, 'amount' => pesos($v)], array_keys($byCat), array_values($byCat)),
        'purchases'   => array_map('purchase_row', $rows),
        'unpaid'      => ['total' => pesos(array_sum(array_map(fn ($p) => cents($p['amount']), $unpaid))), 'rows' => array_map('purchase_row', $unpaid)],
        'categories'  => PURCHASE_CATEGORIES,
        'methods'     => PURCHASE_METHODS,
        'scan'        => gemini_available(),
    ]);
}

if (method() !== 'POST') {
    fail('Method not allowed.', 405);
}

switch (input('action', '')) {
    case 'scan':
        // One photo of a receipt, read by the scanner the bills use; the page gets the details to confirm as a purchase.
        $name = save_uploaded_image('image', 'receipts', 'solo' . $me['id']);
        set_time_limit(200);
        try {
            $found = scan_receipt([upload_dir('receipts') . "/$name"]);
        } catch (GeminiException $e) {
            delete_upload('receipts', $name);
            fail($e->getMessage(), 503);
        }
        $r = $found[0] ?? null;
        // The items' own sum (price paid per unit × quantity, in whole cents) stands in when the receipt prints no total
        $items = $r ? pesos(array_sum(array_map(fn ($i) => cents($i['unit_price'] * $i['qty']), $r['items']))) : 0;
        $total = $r ? ($r['total'] ?? ($items ?: ($r['subtotal'] ?? null))) : null;
        if (!$r || !$total) {
            delete_upload('receipts', $name);
            json_ok(['not_receipt' => true]);
        }
        $when = strtotime((string) $r['txn_date']);
        $date = $when ? min(date('Y-m-d', $when), date('Y-m-d')) : date('Y-m-d');
        $cur = strtoupper((string) ($r['currency'] ?? ''));
        json_ok([
            'receipt_image' => $name,
            'title'         => mb_substr((string) ($r['title'] ?: $r['store_name']), 0, 80),
            'merchant'      => mb_substr((string) $r['store_name'], 0, 60),
            'receipt_no'    => mb_substr((string) $r['receipt_no'], 0, 40),
            'amount'        => round((float) $total, 2),
            'date'          => $date,
            'items'         => count($r['items']),
            'other_currency' => ($cur !== '' && $cur !== user_currency($me['id'])) ? $cur : null,
        ]);

    case 'create':
        $f = purchase_fields($me);
        $image = my_receipt_image($me, trim((string) input('receipt_image', '')));
        $paidOn = $f['status'] === 'paid' ? $f['purchased_on'] : null;
        q('INSERT INTO purchases (user_id, title, category, status, amount, purchased_on, due_on, paid_on, merchant, receipt_no, payment_method, note, receipt_image) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$me['id'], $f['title'], $f['category'], $f['status'], $f['amount'], $f['purchased_on'], $f['due_on'], $paidOn, $f['merchant'], $f['receipt_no'], $f['payment_method'], $f['note'], $image]);
        $id = (int) db()->lastInsertId();
        journal($me, $image ? 'scanned' : 'added', ['id' => $id] + $f, $f['status'] === 'unpaid' ? 'To pay' . ($f['due_on'] ? ' by ' . $f['due_on'] : '') : $f['category']);
        json_ok(['id' => $id]);

    case 'update':
        $p = my_purchase($me);
        $f = purchase_fields($me);
        // Switching to "to pay" reopens it; switching to "paid" settles it on the purchase date unless it already was
        $paidOn = $f['status'] === 'paid' ? ($p['status'] === 'paid' && $p['paid_on'] ? $p['paid_on'] : $f['purchased_on']) : null;
        q('UPDATE purchases SET title = ?, category = ?, status = ?, amount = ?, purchased_on = ?, due_on = ?, paid_on = ?, merchant = ?, receipt_no = ?, payment_method = ?, note = ? WHERE id = ?',
            [$f['title'], $f['category'], $f['status'], $f['amount'], $f['purchased_on'], $f['due_on'], $paidOn, $f['merchant'], $f['receipt_no'], $f['payment_method'], $f['note'], $p['id']]);
        journal($me, 'edited', ['id' => (int) $p['id']] + $f, $f['category']);
        json_ok();

    case 'settle':
        // A purchase that was still to pay is paid now (today unless a day is given), by the chosen method
        $p = my_purchase($me);
        if ($p['status'] !== 'unpaid') {
            fail('That one is already paid.', 422);
        }
        $day = valid_day((string) input('date', date('Y-m-d')), true);
        if (!$day) {
            fail('Pick a valid date (not in the future).', 422);
        }
        $method = trim((string) input('payment_method', ''));
        if ($method !== '' && !in_array($method, PURCHASE_METHODS, true)) {
            fail('Pick how you paid.', 422);
        }
        q("UPDATE purchases SET status = 'paid', paid_on = ?, payment_method = COALESCE(NULLIF(?, ''), payment_method) WHERE id = ?", [$day, $method, $p['id']]);
        journal($me, 'settled', ['id' => (int) $p['id'], 'title' => $p['title'], 'amount' => $p['amount']], 'Paid' . ($method !== '' ? " with $method" : '') . " on $day");
        json_ok();

    case 'reopen':
        $p = my_purchase($me);
        if ($p['status'] !== 'paid') {
            fail('That one is still to pay.', 422);
        }
        q("UPDATE purchases SET status = 'unpaid', paid_on = NULL WHERE id = ?", [$p['id']]);
        journal($me, 'reopened', ['id' => (int) $p['id'], 'title' => $p['title'], 'amount' => $p['amount']], 'Back to pay');
        json_ok();

    case 'delete':
        $p = my_purchase($me);
        q('DELETE FROM purchases WHERE id = ?', [$p['id']]);
        delete_upload('receipts', $p['receipt_image']);
        journal($me, 'deleted', ['id' => (int) $p['id'], 'title' => $p['title'], 'amount' => $p['amount']]);
        json_ok();

    default:
        fail('Unknown action.', 404);
}
