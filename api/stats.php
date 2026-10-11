<?php
// Personal spending stats: what *my share* of each food bill was, by month, place and item.
require __DIR__ . '/../includes/api.php';

$me = api_user();

$bills = q(
    "SELECT b.* FROM bills b JOIN bill_members m ON m.bill_id = b.id
     WHERE m.user_id = ? AND b.kind = 'bill' AND b.status IN ('active','settling','closed') ORDER BY b.created_at",
    [$me['id']]
)->fetchAll();

/** Restaurant name: first readable line of the OCR text (e.g. "MANG INASAL"), else the bill name. */
function place_of(array $bill): string
{
    foreach (preg_split('/\r?\n/', (string) $bill['ocr_raw']) as $line) {
        $line = trim($line);
        if (preg_match('/^[A-Za-z][A-Za-z &\'.-]{2,40}$/', $line)) {
            return ucwords(strtolower($line));
        }
    }
    return $bill['name'];
}

$months = [];
for ($i = 5; $i >= 0; $i--) {
    $key = date('Y-m', strtotime("first day of -$i month"));
    $months[$key] = 0;
}
$places = $items = [];
$total = $saved = 0;
$count = 0;

// Everything is added up in my currency; bills in others count at today's market rate ('estimated')
$home = user_currency($me['id']);
$estimated = false;
$toHome = function (int $cents, string $cur) use ($home, &$estimated): int {
    static $rates = [];
    if ($cur === $home || $cents === 0) {
        return $cents;
    }
    $estimated = true;
    $rates[$cur] ??= fx_rate($cur, $home)['rate'] ?? 0.0;
    return (int) round($cents * $rates[$cur]);
};

foreach ($bills as $b) {
    foreach (['id', 'creator_id', 'payer_id'] as $k) {
        $b[$k] = (int) $b[$k];
    }
    $members = bill_members($b['id']);
    $billItems = bill_items($b['id']);
    if (!$billItems) {
        continue;
    }
    $calc = compute_shares($b, $members, $billItems);
    $mine = $toHome($calc['shares'][$me['id']] ?? 0, $calc['currency']);
    if ($mine <= 0) {
        continue;
    }
    $count++;
    $total += $mine;
    $saved += $toHome($calc['discount_by'][$me['id']] ?? 0, $calc['receipt_currency']);
    $key = substr($b['created_at'], 0, 7);
    if (isset($months[$key])) {
        $months[$key] += $mine;
    }
    $place = place_of($b);
    $places[$place] = ($places[$place] ?? 0) + $mine;

    foreach ($billItems as $it) {
        if (!in_array($me['id'], $it['who'], true)) {
            continue;
        }
        $part = $toHome(split_evenly(cents($it['line_total']), $it['who'])[$me['id']], $calc['receipt_currency']);
        $name = ucwords(strtolower(trim($it['name'])), " \t(-/");
        $items[$name] = ($items[$name] ?? 0) + $part;
    }
}

arsort($places);
arsort($items);
$top = fn (array $a) => array_map(fn ($k, $v) => ['name' => $k, 'amount' => pesos($v)], array_keys(array_slice($a, 0, 5, true)), array_slice($a, 0, 5, true));

$keys = array_keys($months);
$thisMonth = $months[end($keys)];
$lastMonth = $months[$keys[count($keys) - 2]];
$flow = ['paid_out' => 0, 'received' => 0];
foreach (q(
    "SELECT currency, COALESCE(SUM(CASE WHEN from_user_id = ? THEN paid_amount END), 0) AS paid_out,
            COALESCE(SUM(CASE WHEN to_user_id = ? THEN paid_amount END), 0) AS received
     FROM settlements WHERE paid_amount > 0 AND (from_user_id = ? OR to_user_id = ?) GROUP BY currency",
    [$me['id'], $me['id'], $me['id'], $me['id']]
) as $r) {
    $flow['paid_out'] += $toHome(cents($r['paid_out']), $r['currency']);
    $flow['received'] += $toHome(cents($r['received']), $r['currency']);
}

json_ok([
    'months'     => array_map(fn ($k, $v) => ['month' => $k, 'amount' => pesos($v)], $keys, array_values($months)),
    'this_month' => pesos($thisMonth),
    'last_month' => pesos($lastMonth),
    'total'      => pesos($total),
    'bills'      => $count,
    'average'    => $count ? pesos(intdiv($total, $count)) : 0,
    'saved'      => pesos($saved),
    'paid_out'   => pesos($flow['paid_out']),
    'received'   => pesos($flow['received']),
    'currency'   => $home,
    'estimated'  => $estimated,
    'places'     => $top($places),
    'items'      => $top($items),
]);
