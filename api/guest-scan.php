<?php
// Guest mode's free receipt scans (pages/try.php): a visitor with no account can scan GUEST_SCAN_LIMIT receipts as a
// teaser. Nothing is saved: the photo is deleted as soon as it's read, and the reply only carries what the page needs to
// fill its split (items with their prices, the detected total and its check, tax / service / discount).
// The limit is per browser (a cookie) AND per network (IP address), so clearing cookies doesn't hand out more scans,
// and a daily cap across all guests protects the scanner's quota.
require __DIR__ . '/../includes/api.php';
require __DIR__ . '/../includes/ocr.php';
require __DIR__ . '/../includes/uploads.php';

const GUEST_SCAN_LIMIT = 5;          // free scans per guest
const GUEST_SCAN_WINDOW_DAYS = 30;   // how long a scan counts against them
const GUEST_SCAN_DAILY_CAP = 300;    // all guests together, per day
const GUEST_SCAN_MAX_PHOTOS = 3;     // photos of one long receipt, sent as one scan
const GUEST_COOKIE = 'setlo_guest';

/** This browser's anonymous id, set on first use. */
function guest_cookie_id(): string
{
    $id = (string) ($_COOKIE[GUEST_COOKIE] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
        $id = bin2hex(random_bytes(16));
        setcookie(GUEST_COOKIE, $id, ['expires' => time() + 365 * 86400, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => is_https()]);
    }
    return $id;
}

$cookieId = guest_cookie_id();
$ipHash = hash('sha256', 'guest-scan|' . ($_SERVER['REMOTE_ADDR'] ?? ''));
$window = 'created_at > NOW() - INTERVAL ' . GUEST_SCAN_WINDOW_DAYS . ' DAY';

/** Scans left for this visitor: the worse of what their browser and their network have used. */
function scans_left(string $cookieId, string $ipHash, string $window): int
{
    $used = (int) q("SELECT COUNT(*) FROM guest_scans WHERE $window AND (cookie_id = ? OR ip_hash = ?)", [$cookieId, $ipHash])->fetchColumn();
    return max(0, GUEST_SCAN_LIMIT - $used);
}

/** "3 free scans left" / "Your last free scan" / "No free scans left". */
function left_words(int $n): string
{
    return $n === 0 ? 'No free scans left' : ($n === 1 ? 'Your last free scan' : "$n free scans left");
}

if (method() === 'GET') {
    $left = scans_left($cookieId, $ipHash, $window);
    json_ok(['limit' => GUEST_SCAN_LIMIT, 'left' => $left, 'available' => gemini_available(), 'message' => left_words($left)]);
}
if (method() !== 'POST') {
    fail('Method not allowed.', 405);
}
verify_csrf();

if (!gemini_available()) {
    fail('Receipt scanning isn’t switched on for this demo right now. You can still type the items in by hand.', 503);
}
if (scans_left($cookieId, $ipHash, $window) <= 0) {
    fail('You’ve used all ' . GUEST_SCAN_LIMIT . ' free scans. Create a free account for unlimited scanning. It takes a minute.', 429, ['code' => 'limit', 'left' => 0]);
}
if ((int) q('SELECT COUNT(*) FROM guest_scans WHERE created_at > NOW() - INTERVAL 1 DAY')->fetchColumn() >= GUEST_SCAN_DAILY_CAP) {
    fail('Lots of people are trying the scanner today, so it needs a rest. Please try again tomorrow, or type the items in by hand.', 429, ['code' => 'busy']);
}

$names = save_uploaded_images('images', 'guest-scans', 'guest', GUEST_SCAN_MAX_PHOTOS) ?: [save_uploaded_image('image', 'guest-scans', 'guest')];
// Reserve the scan before reading, so several quick requests can't slip past the limit; given back if the scanner fails.
q('INSERT INTO guest_scans (cookie_id, ip_hash) VALUES (?, ?)', [$cookieId, $ipHash]);
$scanId = (int) db()->lastInsertId();

$receipts = [];
$problem = null;
try {
    set_time_limit(200);
    $receipts = scan_receipt(array_map(fn ($n) => upload_dir('guest-scans') . "/$n", $names), count($names) > 1);
} catch (GeminiException $e) {
    $problem = $e->getMessage();
} finally {
    foreach ($names as $n) {
        delete_upload('guest-scans', $n); // nothing about the photo is kept
    }
}
if ($problem !== null) {
    q('DELETE FROM guest_scans WHERE id = ?', [$scanId]); // it wasn't their fault: no scan used
    fail($problem . ' That one didn’t count against your free scans.', 502);
}

$r = $receipts[0] ?? null;
$left = scans_left($cookieId, $ipHash, $window);
if (!$r || !$r['items']) {
    json_ok(['found' => 0, 'left' => $left, 'message' => 'That doesn’t look like a receipt, or the prices were too blurry to read. Try a flat, well-lit photo with the whole receipt in view. ' . left_words($left) . '.']);
}

// Line by line: what each line cost (qty × price each, after any item promo)
$lines = [];
$sum = 0;
foreach ($r['items'] as $it) {
    $line = $it['qty'] * cents($it['unit_price']);
    $sum += $line;
    $lines[] = [
        'name' => $it['name'], 'printed_name' => $it['printed_name'], 'qty' => $it['qty'], 'price' => $it['unit_price'],
        'line' => pesos($line), 'promo' => $it['promo'], 'check' => (bool) $it['needs_review'], 'details' => $it['details'],
    ];
}

// The detected total, and whether the lines add up to it: as is, or with tax / service charge / discount around them
$total = $r['total'] === null ? null : cents($r['total']);
$serviceC = cents($r['service_charge']);
$discountC = cents($r['discount']);
$taxC = cents($r['tax']);
$taxIncluded = false;
$status = 'missing';
if ($total !== null) {
    $status = 'mismatch';
    if (abs($sum - $discountC - $total) <= 1 || abs($sum + $serviceC - $discountC - $total) <= 1) {
        $status = 'match';
        $taxIncluded = $taxC > 0; // the prices already had the tax in them
    } elseif (abs($sum + $serviceC + $taxC - $discountC - $total) <= 1) {
        $status = 'match';
    }
}
$money = fn (int $c) => number_format($c / 100, 2);
$note = [
    'match'    => 'The prices add up to the receipt’s total of ' . ($total === null ? '' : $money($total)) . '.',
    'mismatch' => 'The receipt says ' . ($total === null ? '' : $money($total)) . ' but the lines add up to ' . $money($sum) . '. A line may be missing or misread, so please check the prices.',
    'missing'  => 'We couldn’t find a total on the receipt. The lines add up to ' . $money($sum) . '.',
][$status];

json_ok([
    'found'        => count($lines),
    'items'        => $lines,
    'store'        => $r['store_name'],
    'currency'     => $r['currency'],
    'total'        => $total === null ? null : pesos($total),
    'items_sum'    => pesos($sum),
    'total_check'  => $status,
    'tax'          => $r['tax'],
    'service'      => $r['service_charge'],
    'discount'     => $r['discount'],
    'tax_included' => $taxIncluded,
    'left'         => $left,
    'message'      => 'Found ' . count($lines) . ($r['store_name'] ? ' items from ' . $r['store_name'] : ' items') . '. ' . $note . ' ' . left_words($left) . '.',
]);
