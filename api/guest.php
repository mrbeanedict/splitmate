<?php
// A guest's private view of one bill, opened from the link the Bill Creator copied for them (pages/guest.php). No
// account or sign-in: the link's token is the key, and it shows only that person's own share. The same page lets a
// signed-in user take the guest's records over (POST claim); registering or signing in with ?claim= does it too.
require __DIR__ . '/../includes/api.php';

$token = (string) ($_GET['t'] ?? input('t', ''));

if (method() === 'POST') {
    $me = api_user(); // CSRF-checked
    if (input('action', '') !== 'claim') {
        fail('Unknown action.', 404);
    }
    $billId = claim_guest_account($token, $me['id']);
    json_ok(['redirect' => bill_link(q('SELECT * FROM bills WHERE id = ?', [$billId])->fetch(), $me['id'])]);
}
if (method() !== 'GET') {
    fail('Method not allowed.', 405);
}

[$bill, $guest] = guest_by_token($token);
$g = (int) $guest['id'];
$members = bill_members($bill['id']);
$items = bill_items($bill['id']);
$calc = compute_shares($bill, $members, $items);
$plan = settlement_plan($bill, $members, $calc);
$byId = array_column($members, null, 'id');
$creator = q('SELECT full_name FROM users WHERE id = ?', [$bill['creator_id']])->fetchColumn();
$ids = array_column($members, 'id');

// What this person ordered: each item's cost shared among the people who had it
$mine = [];
if ($calc['split_mode'] === 'items') {
    foreach ($items as $it) {
        if (!in_array($g, $it['who'], true)) {
            continue;
        }
        $who = array_values(array_intersect($it['who'], $ids));
        $mine[] = [
            'name'    => $it['printed_name'] ?: $it['name'],
            'qty'     => $it['qty'],
            'shared'  => count($who) - 1,
            'amount'  => pesos(split_evenly(cents($it['line_total']), $who)[$g] ?? 0),
        ];
    }
}
$extras = $calc['split_mode'] === 'items' ? (split_evenly($calc['extras'], $ids)[$g] ?? 0) : 0;
$my = $byId[$g];

// Their debt: the settlement once it exists, otherwise the plan that will be created
$settlement = null;
foreach (bill_settlements($bill['id']) as $s) {
    if ($s['from']['id'] === $g) {
        $settlement = [
            'amount' => $s['amount'], 'paid' => $s['paid_amount'], 'remaining' => $s['remaining'], 'status' => $s['status'],
            'to' => $s['to']['name'], 'method' => $s['to']['payment_method'], 'account' => $s['to']['payment_account'],
        ];
        break;
    }
}
$preview = null;
if (!$settlement) {
    foreach ($plan['transfers'] as [$from, $to, $amount]) {
        if ($from === $g) {
            $preview = ['amount' => pesos($amount), 'to' => $byId[$to]['name'], 'method' => $byId[$to]['payment_method'], 'account' => $byId[$to]['payment_account']];
            break;
        }
    }
}
$all = bill_settlements($bill['id']);

// Signed in already? Then the page can offer to link this bill to that account instead of asking them to register.
$viewer = current_user();
json_ok([
    'bill' => [
        'name'       => $bill['name'],
        'status'     => $bill['status'],
        'creator'    => $creator,
        'created_at' => $bill['created_at'],
        'currency'   => $calc['currency'],
        'receipt_currency' => $calc['receipt_currency'],
        'split_mode' => $calc['split_mode'],
        'tax_included' => $calc['tax_included'],
        'service_charge' => (float) $bill['service_charge'],
        'tax'        => (float) $bill['tax'],
        'discount'   => (float) $bill['discount'],
    ],
    'me' => [
        'name'          => $my['name'],
        'first'         => $my['first'],
        'initials'      => $my['initials'],
        'color'         => $my['color'],
        'discount_type' => $my['discount_type'],
        'percent'       => $my['percent'],
    ],
    'items'      => $mine,
    'items_total' => pesos(array_sum(array_map(fn ($i) => cents($i['amount']), $mine))),
    'extras'     => pesos($extras),
    'discount_share' => pesos($calc['discount_by'][$g] ?? 0),
    'native_share' => pesos($calc['native_shares'][$g] ?? 0),
    'share'      => pesos($calc['shares'][$g] ?? 0),
    'settlement' => $settlement,
    'preview'    => $preview,
    // Everyone on the bill by name only: never anyone else's amounts
    'people'     => array_map(fn ($m) => ['name' => $m['first'], 'initials' => $m['initials'], 'color' => $m['color'], 'you' => $m['id'] === $g], $members),
    'progress'   => ['total' => count($all), 'settled' => count(array_filter($all, fn ($s) => $s['status'] === 'settled'))],
    'viewer'     => $viewer && $viewer['role'] === 'user' ? ['name' => $viewer['full_name'], 'is_creator' => $viewer['id'] === $bill['creator_id']] : null,
]);
