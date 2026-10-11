<?php
// Currencies and exchange rates.
// Every user has a home currency (users.currency). A bill settles in one agreed currency (bills.currency — the payer's
// when it starts), while its receipts may be in another (bills.receipt_currency, read by the scanner and correctable
// on Review). Receipt amounts are never rewritten: shares are worked out in the receipt's currency, then converted at
// the bill's settlement rate (see bill_fx() and convert_calc() in includes/domain.php).
// Money is still integer hundredths (cents) of whichever currency it is in.

declare(strict_types=1);

// code => [symbol, name, decimals shown]. Zero-decimal currencies settle in whole units.
const CURRENCIES = [
    'PHP' => ['₱', 'Philippine peso', 2],
    'USD' => ['$', 'US dollar', 2],
    'EUR' => ['€', 'Euro', 2],
    'GBP' => ['£', 'British pound', 2],
    'JPY' => ['¥', 'Japanese yen', 0],
    'KRW' => ['₩', 'South Korean won', 0],
    'CNY' => ['CN¥', 'Chinese yuan', 2],
    'HKD' => ['HK$', 'Hong Kong dollar', 2],
    'TWD' => ['NT$', 'New Taiwan dollar', 0],
    'SGD' => ['S$', 'Singapore dollar', 2],
    'MYR' => ['RM', 'Malaysian ringgit', 2],
    'THB' => ['฿', 'Thai baht', 2],
    'IDR' => ['Rp', 'Indonesian rupiah', 0],
    'VND' => ['₫', 'Vietnamese dong', 0],
    'INR' => ['₹', 'Indian rupee', 2],
    'AUD' => ['A$', 'Australian dollar', 2],
    'NZD' => ['NZ$', 'New Zealand dollar', 2],
    'CAD' => ['CA$', 'Canadian dollar', 2],
    'CHF' => ['CHF ', 'Swiss franc', 2],
    'AED' => ['AED ', 'UAE dirham', 2],
    'SAR' => ['SAR ', 'Saudi riyal', 2],
    'QAR' => ['QAR ', 'Qatari riyal', 2],
    'MOP' => ['MOP$', 'Macanese pataca', 2],
];

// How converted shares are rounded (bills.fx_rounding). Whatever rounding adds or takes off stays with the payer.
const FX_ROUNDING = [
    'exact'   => 'Exact (to the cent)',
    'nearest' => 'Nearest whole unit',
    'up'      => 'Round each share up',
];

const FX_TTL = 6 * 3600;       // fetch fresh market rates when the cached ones are older than this
const FX_TIMEOUT = 6;          // seconds per rate request

function is_currency($code): bool
{
    return is_string($code) && isset(CURRENCIES[$code]);
}

/** A currency code from a request, or fail. */
function currency_param(string $field = 'currency'): string
{
    $code = strtoupper(trim((string) input($field, '')));
    if (!is_currency($code)) {
        fail('Choose one of the listed currencies.', 422, ['fields' => [$field => 'Choose a currency.']]);
    }
    return $code;
}

function currency_decimals(string $code): int
{
    return CURRENCIES[$code][2] ?? 2;
}

/** "₱1,234.50", "¥6,000", "HK$12.00". */
function money_str(int $cents, string $code = 'PHP'): string
{
    [$symbol, , $decimals] = CURRENCIES[$code] ?? [$code . ' ', '', 2];
    return ($cents < 0 ? '-' : '') . $symbol . number_format(abs($cents) / 100, $decimals);
}

/** The list for pickers: [{code, symbol, name, decimals}]. */
function currency_list(): array
{
    return array_map(fn ($c, $v) => ['code' => $c, 'symbol' => trim($v[0]), 'name' => $v[1], 'decimals' => $v[2]], array_keys(CURRENCIES), CURRENCIES);
}

/** A user's home currency. */
function user_currency(int $userId): string
{
    static $cache = [];
    if (!isset($cache[$userId])) {
        $c = (string) q('SELECT currency FROM users WHERE id = ?', [$userId])->fetchColumn();
        $cache[$userId] = is_currency($c) ? $c : 'PHP';
    }
    return $cache[$userId];
}

// ---------- Market rates ----------
// fx_rates holds units of each currency per 1 USD, as last fetched; any pair is worked out across USD.

/**
 * Market rate to convert $from into $to: ['rate' => units of $to per 1 $from, 'source', 'at' => when the provider
 * published it, 'fetched_at']. Fetches fresh rates when the cache is old ($fetch), and falls back to the last ones
 * saved (their timestamp says how old). Null when no rate is known at all.
 */
function fx_rate(string $from, string $to, bool $fetch = true): ?array
{
    if ($from === $to) {
        return ['rate' => 1.0, 'source' => 'same currency', 'at' => date('Y-m-d H:i:s'), 'fetched_at' => date('Y-m-d H:i:s')];
    }
    $rows = fx_cached([$from, $to]);
    $stale = count($rows) < 2 || min(array_map(fn ($r) => strtotime($r['fetched_at']), $rows)) < time() - FX_TTL;
    if ($stale && $fetch && fx_fetch()) {
        $rows = fx_cached([$from, $to]);
    }
    if (!isset($rows[$from], $rows[$to]) || (float) $rows[$from]['per_usd'] <= 0) {
        return null;
    }
    return [
        'rate'       => (float) $rows[$to]['per_usd'] / (float) $rows[$from]['per_usd'],
        'source'     => $rows[$to]['source'],
        'at'         => min($rows[$from]['rate_time'], $rows[$to]['rate_time']),
        'fetched_at' => min($rows[$from]['fetched_at'], $rows[$to]['fetched_at']),
    ];
}

function fx_cached(array $codes): array
{
    $codes = array_values(array_unique($codes));
    $in = implode(',', array_fill(0, count($codes), '?'));
    $out = [];
    foreach (q("SELECT * FROM fx_rates WHERE quote IN ($in)", $codes)->fetchAll() as $r) {
        $out[$r['quote']] = $r;
    }
    return $out;
}

/** Fetch every rate against USD from the first provider that answers, and cache them. Returns whether it worked. */
function fx_fetch(): bool
{
    static $tried = false; // one attempt per request, even when it fails
    if ($tried || !function_exists('curl_init')) {
        return false;
    }
    $tried = true;
    foreach (['fx_from_er_api', 'fx_from_frankfurter'] as $provider) {
        $got = $provider();
        if (!$got || empty($got['rates'])) {
            continue;
        }
        $now = date('Y-m-d H:i:s');
        foreach ($got['rates'] as $code => $perUsd) {
            if (is_currency($code) && is_numeric($perUsd) && $perUsd > 0) {
                q('INSERT INTO fx_rates (quote, per_usd, source, rate_time, fetched_at) VALUES (?, ?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE per_usd = VALUES(per_usd), source = VALUES(source), rate_time = VALUES(rate_time), fetched_at = VALUES(fetched_at)',
                    [$code, $perUsd, $got['source'], $got['at'], $now]);
            }
        }
        return true;
    }
    return false;
}

function fx_get_json(string $url): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => FX_TIMEOUT, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false || $status !== 200) {
        error_log("[setlo] FX rate request failed ($status): $url");
        return null;
    }
    $data = json_decode((string) $raw, true);
    return is_array($data) ? $data : null;
}

/** ExchangeRate-API's open endpoint (no key, ~160 currencies, updated daily). */
function fx_from_er_api(): ?array
{
    $d = fx_get_json('https://open.er-api.com/v6/latest/USD');
    if (($d['result'] ?? '') !== 'success' || !is_array($d['rates'] ?? null)) {
        return null;
    }
    return ['rates' => $d['rates'], 'source' => 'open.er-api.com', 'at' => date('Y-m-d H:i:s', (int) ($d['time_last_update_unix'] ?? time()))];
}

/** Frankfurter (European Central Bank reference rates, working days). */
function fx_from_frankfurter(): ?array
{
    $d = fx_get_json('https://api.frankfurter.app/latest?from=USD');
    if (!is_array($d['rates'] ?? null)) {
        return null;
    }
    return ['rates' => ['USD' => 1] + $d['rates'], 'source' => 'frankfurter.app (ECB)', 'at' => ($d['date'] ?? date('Y-m-d')) . ' 16:00:00'];
}

/**
 * $cents of $from in $to at today's market rate, for estimates ("≈ ¥2,000"): ['cents', 'rate', 'source', 'at'],
 * or null when no rate is known.
 */
function fx_estimate(int $cents, string $from, string $to): ?array
{
    $r = fx_rate($from, $to);
    return $r ? ['cents' => (int) round($cents * $r['rate']), 'currency' => $to] + $r : null;
}

// ---------- A bill's settlement rate ----------

/**
 * How a bill's receipt amounts become its settlement currency. $nativeTotal: the bill total in the receipt currency.
 *  - market: today's rate when the receipt currency was set (or last refreshed) — an estimate;
 *  - actual: the amount the payer was actually charged (fx_actual_total), so rate = actual ÷ receipt total;
 *  - manual: a rate typed by the creator.
 * Separately recorded fees (fx_fees, e.g. a card's foreign-transaction fee) come on top, shared equally.
 */
function bill_fx(array $bill, int $nativeTotal): array
{
    $to = is_currency($bill['currency'] ?? null) ? $bill['currency'] : 'PHP';
    $from = is_currency($bill['receipt_currency'] ?? null) ? $bill['receipt_currency'] : $to;
    $market = ($bill['fx_market_rate'] ?? null) !== null
        ? ['rate' => (float) $bill['fx_market_rate'], 'source' => $bill['fx_market_source'], 'at' => $bill['fx_market_at']]
        : null;
    $out = [
        'currency'         => $to,
        'receipt_currency' => $from,
        'foreign'          => $from !== $to,
        'mode'             => $bill['fx_mode'] ?? 'market',
        'market'           => $market,
        'actual_total'     => ($bill['fx_actual_total'] ?? null) === null ? null : cents($bill['fx_actual_total']),
        'manual_rate'      => ($bill['fx_manual_rate'] ?? null) === null ? null : (float) $bill['fx_manual_rate'],
        'fees'             => cents($bill['fx_fees'] ?? 0),
        'fees_note'        => $bill['fx_fees_note'] ?? null,
        'rounding'         => $bill['fx_rounding'] ?? 'exact',
        'set_at'           => $bill['fx_set_at'] ?? null,
        'native_total'     => $nativeTotal,
        'rate'             => 1.0,
        'source'           => null,
        'at'               => null,
        'converted'        => $nativeTotal,
        'estimated'        => null,  // the market-rate conversion, shown next to the actual one
        'missing'          => false,
    ];
    if (!$out['foreign']) {
        $out['fees'] = 0;
        return $out;
    }
    // Converted amounts come out in the settlement currency's smallest unit (whole yen, won…)
    $unit = currency_decimals($to) === 0 ? 100 : 1;
    $conv = fn (float $c) => (int) round($c / $unit) * $unit;
    $out['fees'] = $conv($out['fees']);
    $out['estimated'] = $market ? $conv($nativeTotal * $market['rate']) : null;
    if ($out['mode'] === 'actual' && $out['actual_total'] !== null) {
        $out['converted'] = $conv($out['actual_total']);
        $out['rate'] = $nativeTotal > 0 ? $out['actual_total'] / $nativeTotal : ($market['rate'] ?? 0.0);
        $out['source'] = 'Actual amount charged';
        $out['at'] = $out['set_at'];
    } elseif ($out['mode'] === 'manual' && $out['manual_rate']) {
        $out['rate'] = $out['manual_rate'];
        $out['converted'] = $conv($nativeTotal * $out['manual_rate']);
        $out['source'] = 'Rate entered by hand';
        $out['at'] = $out['set_at'];
    } elseif ($market) {
        $out['mode'] = 'market';
        $out['rate'] = $market['rate'];
        $out['converted'] = $out['estimated'];
        $out['source'] = $market['source'];
        $out['at'] = $market['at'];
    } else {
        $out['missing'] = true;
        $out['converted'] = 0;
    }
    return $out;
}

/** bill_fx() for the API: amounts in currency units, timestamps as stored. */
function fx_payload(array $fx): array
{
    $u = fn (?int $c) => $c === null ? null : pesos($c);
    return [
        'currency'         => $fx['currency'],
        'receipt_currency' => $fx['receipt_currency'],
        'foreign'          => $fx['foreign'],
        'mode'             => $fx['mode'],
        'rate'             => round($fx['rate'], 10),
        'source'           => $fx['source'],
        'at'               => $fx['at'],
        'market'           => $fx['market'],
        'native_total'     => $u($fx['native_total']),
        'estimated'        => $u($fx['estimated']),
        'converted'        => $u($fx['converted']),
        'actual_total'     => $u($fx['actual_total']),
        'manual_rate'      => $fx['manual_rate'],
        'fees'             => $u($fx['fees']),
        'fees_note'        => $fx['fees_note'],
        'rounding'         => $fx['rounding'],
        'set_at'           => $fx['set_at'],
        'missing'          => $fx['missing'],
    ];
}

/**
 * Save today's market rate for a bill's receipt → settlement currencies (when either changes, or on "Refresh rate").
 * Returns whether a rate was found. Logged in bill_fx_log.
 */
function fx_capture_market(int $billId, ?int $actorId, string $note): bool
{
    $b = q('SELECT * FROM bills WHERE id = ?', [$billId])->fetch();
    $to = $b['currency'];
    $from = $b['receipt_currency'] ?: $to;
    if ($from === $to) {
        q('UPDATE bills SET fx_market_rate = NULL, fx_market_source = NULL, fx_market_at = NULL WHERE id = ?', [$billId]);
        return true;
    }
    $r = fx_rate($from, $to);
    q('UPDATE bills SET fx_market_rate = ?, fx_market_source = ?, fx_market_at = ? WHERE id = ?',
        [$r ? round($r['rate'], 10) : null, $r['source'] ?? null, $r['at'] ?? null, $billId]);
    fx_log($billId, $actorId, $r ? $note : $note . ' No market rate could be fetched.');
    return $r !== null;
}

/** Append the bill's current conversion settings to its audit log (bill_fx_log). Nothing in it is ever changed. */
function fx_log(int $billId, ?int $actorId, string $note): void
{
    $b = q('SELECT * FROM bills WHERE id = ?', [$billId])->fetch();
    $fx = bill_fx($b, 0);
    q(
        'INSERT INTO bill_fx_log (bill_id, actor_id, receipt_currency, currency, mode, market_rate, market_source, market_at, actual_total, manual_rate, fees, fees_note, rounding, note)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$billId, $actorId, $fx['receipt_currency'], $fx['currency'], $b['fx_mode'], $b['fx_market_rate'], $b['fx_market_source'], $b['fx_market_at'],
         $b['fx_actual_total'], $b['fx_manual_rate'], $b['fx_fees'], $b['fx_fees_note'], $b['fx_rounding'], mb_substr($note, 0, 255)]
    );
}

function fx_log_rows(int $billId): array
{
    return array_map(fn ($r) => [
        'at'               => $r['created_at'],
        'by'               => $r['actor_name'] ? first_name($r['actor_name']) : null,
        'receipt_currency' => $r['receipt_currency'],
        'currency'         => $r['currency'],
        'mode'             => $r['mode'],
        'market_rate'      => $r['market_rate'] === null ? null : (float) $r['market_rate'],
        'market_source'    => $r['market_source'],
        'market_at'        => $r['market_at'],
        'actual_total'     => $r['actual_total'] === null ? null : (float) $r['actual_total'],
        'manual_rate'      => $r['manual_rate'] === null ? null : (float) $r['manual_rate'],
        'fees'             => (float) $r['fees'],
        'rounding'         => $r['rounding'],
        'note'             => $r['note'],
    ], q('SELECT l.*, u.full_name AS actor_name FROM bill_fx_log l LEFT JOIN users u ON u.id = l.actor_id WHERE l.bill_id = ? ORDER BY l.id DESC LIMIT 30', [$billId])->fetchAll());
}

// ---------- Converting shares ----------

/** floor($a / $b) for integers, $b > 0. */
function floor_div(int $a, int $b): int
{
    $q = intdiv($a, $b);
    return ($a % $b !== 0 && $a < 0) ? $q - 1 : $q;
}

/**
 * Share $total in proportion to $weights (uid => cents, may be negative), largest remainder: each part is floored,
 * then the leftover cents go to the largest remainders (lower uid first on ties). Sums to $total exactly.
 * Mirrored by Setlo.allocate() in assets/js/ui.js.
 */
function allocate(int $total, array $weights): array
{
    ksort($weights);
    $sum = array_sum($weights);
    if ($sum === 0) {
        return $total === 0 ? array_map(fn () => 0, $weights) : split_evenly($total, array_keys($weights));
    }
    $sign = $sum < 0 ? -1 : 1; // keep the divisor positive
    $out = [];
    $rem = [];
    foreach ($weights as $uid => $w) {
        $num = $w * $total * $sign;
        $out[$uid] = floor_div($num, $sum * $sign);
        $rem[$uid] = $num - $out[$uid] * $sum * $sign;
    }
    $left = $total - array_sum($out);
    uksort($rem, fn ($a, $b) => $rem[$b] <=> $rem[$a] ?: $a <=> $b);
    foreach (array_keys($rem) as $uid) {
        if ($left <= 0) {
            break;
        }
        $out[$uid]++;
        $left--;
    }
    return $out;
}

/**
 * Apply the rounding policy to shares in $currency: every share but the payer's is rounded to a whole unit (nearest,
 * or up); the payer's takes the difference, so the total doesn't change. Zero-decimal currencies always round.
 */
function round_shares(array $shares, string $policy, string $currency, int $payerId): array
{
    if ($policy === 'exact' && currency_decimals($currency) > 0) {
        return $shares;
    }
    if (!array_key_exists($payerId, $shares)) {
        $payerId = (int) array_key_first($shares);
    }
    $diff = 0;
    foreach ($shares as $uid => $c) {
        if ($uid === $payerId) {
            continue;
        }
        $r = $policy === 'up' ? -floor_div(-$c, 100) * 100 : floor_div($c + 50, 100) * 100;
        $diff += $r - $c;
        $shares[$uid] = $r;
    }
    $shares[$payerId] -= $diff;
    return $shares;
}
