<?php
// Ratings and reviews of Setlo: the public summary and reviews (landing page, Ratings & reviews page) and each
// reviewer's own review. Anyone can rate: signed-in users' reviews belong to their account, everyone else's to a cookie
// token (one review per browser). Reviews go live as soon as they're saved; an admin can hide or delete one afterwards.
require __DIR__ . '/../includes/api.php';

const GUEST_REVIEWS_PER_IP_PER_DAY = 5;

/** "Ana Reyes" shown as "Ana R." — reviews are public, so no full names. */
function review_name(string $fullName): string
{
    $parts = preg_split('/\s+/', trim($fullName)) ?: [];
    $first = $parts[0] ?? 'Setlo user';
    return count($parts) > 1 ? $first . ' ' . mb_substr(end($parts), 0, 1) . '.' : $first;
}

/** The visitor's rater token (a random cookie), created on first use when $create. */
function rater_token(bool $create): ?string
{
    $t = $_COOKIE['setlo_rater'] ?? '';
    if (is_string($t) && preg_match('/^[0-9a-f]{32}$/', $t)) {
        return $t;
    }
    if (!$create) {
        return null;
    }
    $t = bin2hex(random_bytes(16));
    setcookie('setlo_rater', $t, ['expires' => time() + 365 * 86400, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => is_https()]);
    $_COOKIE['setlo_rater'] = $t;
    return $t;
}

/** Who is reviewing: [SQL condition, parameter] for their row, and the user id (null for a visitor without an account). */
function reviewer(?array $me, bool $create): array
{
    if ($me && $me['role'] === 'user') {
        return ['user_id = ?', (int) $me['id'], (int) $me['id']];
    }
    $token = rater_token($create);
    return $token ? ['guest_key = ?', hash('sha256', $token), null] : [null, null, null];
}

function ip_hash(): string
{
    $db = config('db');
    return hash_hmac('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? ''), 'setlo-rater|' . $db['name'] . '|' . $db['pass']);
}

function my_review(?array $me): ?array
{
    [$cond, $param] = reviewer($me, false);
    if (!$cond) {
        return null;
    }
    $r = q("SELECT rating, comment, status, guest_name, updated_at FROM feedback WHERE $cond", [$param])->fetch();
    return $r ? ['rating' => (int) $r['rating'], 'comment' => (string) $r['comment'], 'status' => $r['status'], 'name' => (string) $r['guest_name'], 'updated_at' => $r['updated_at']] : null;
}

if (method() === 'GET') {
    // Public: no sign-in needed
    $me = current_user();
    $mine = reviewer($me, false);
    $dist = array_fill(1, 5, 0);
    foreach (q("SELECT rating, COUNT(*) AS n FROM feedback WHERE status <> 'hidden' GROUP BY rating")->fetchAll() as $row) {
        $dist[(int) $row['rating']] = (int) $row['n'];
    }
    $count = array_sum($dist);
    $sum = 0;
    foreach ($dist as $stars => $n) {
        $sum += $stars * $n;
    }

    $limit = min(30, max(1, (int) ($_GET['limit'] ?? 10)));
    $offset = max(0, (int) ($_GET['offset'] ?? 0));
    $stars = (int) ($_GET['stars'] ?? 0);
    $order = ['high' => 'f.rating DESC, f.created_at DESC', 'low' => 'f.rating ASC, f.created_at DESC'][$_GET['sort'] ?? ''] ?? 'f.created_at DESC';
    $where = "f.status <> 'hidden' AND f.comment IS NOT NULL AND f.comment <> ''";
    $params = [];
    if ($stars >= 1 && $stars <= 5) {
        $where .= ' AND f.rating = ?';
        $params[] = $stars;
    }
    $rows = q(
        "SELECT f.user_id, f.guest_key, f.guest_name, f.rating, f.comment, f.created_at, u.full_name, u.avatar_color, u.avatar
         FROM feedback f LEFT JOIN users u ON u.id = f.user_id
         WHERE $where ORDER BY $order, f.id DESC LIMIT " . ($limit + 1) . " OFFSET $offset",
        $params
    )->fetchAll();
    $more = count($rows) > $limit;
    $rows = array_slice($rows, 0, $limit);

    json_ok([
        'average'      => $count ? round($sum / $count, 1) : 0,
        'count'        => $count,
        'distribution' => $dist,
        'reviews'      => array_map(function ($r) use ($me, $mine) {
            $name = $r['user_id'] ? review_name($r['full_name']) : ($r['guest_name'] ?: 'Guest');
            $isMine = $mine[0] === 'user_id = ?' ? (int) $r['user_id'] === $mine[1] : ($mine[0] !== null && $r['guest_key'] !== null && hash_equals((string) $r['guest_key'], (string) $mine[1]));
            return [
                'name'       => $name,
                'initials'   => initials($name),
                'color'      => $r['user_id'] ? $r['avatar_color'] : '#94a3b8',
                'photo'      => $me && $r['user_id'] ? avatar_url($r['avatar'], (int) $r['user_id']) : null, // profile pictures are for signed-in users only
                'rating'     => (int) $r['rating'],
                'comment'    => $r['comment'],
                'created_at' => $r['created_at'],
                'is_mine'    => $isMine,
            ];
        }, $rows),
        'has_more'     => $more,
        'signed_in'    => (bool) $me,
        'can_rate'     => true,
        'mine'         => my_review($me),
    ]);
}

if (method() !== 'POST') {
    fail('Method not allowed.', 405);
}

verify_csrf(); // visitors have a session too, so the usual token check still applies
$me = current_user();
[$cond, $param, $userId] = reviewer($me, true);
if (!$cond) {
    fail('Please enable cookies to leave a review.', 400);
}

if (input('action', 'save') === 'delete') {
    q("DELETE FROM feedback WHERE $cond", [$param]);
    json_ok(['mine' => null]);
}

$rating = (int) input('rating', 0);
if ($rating < 1 || $rating > 5) {
    fail('Choose a rating from 1 to 5 stars.', 422);
}
$comment = str_input('comment', 500, false);
$name = $userId ? '' : str_input('name', 40, false);
if (preg_match('/[<>]/', $comment . $name)) {
    fail('Please don’t use < or > in your review.', 422);
}
$comment = $comment === '' ? null : $comment;
$name = $name === '' ? null : $name;

$old = q("SELECT updated_at FROM feedback WHERE $cond", [$param])->fetch();
if ($old && strtotime($old['updated_at']) > time() - 3) {
    fail('One moment, then try again.', 429);
}
if ($old) {
    // Edit: it stays as it is (a review an admin hid stays hidden)
    q('UPDATE feedback SET rating = ?, comment = ?, guest_name = ? WHERE ' . $cond, [$rating, $comment, $userId ? null : $name, $param]);
} else {
    if (!$userId) {
        // Visitors without an account: a daily cap per network address, so clearing cookies doesn't allow endless reviews
        $today = (int) q('SELECT COUNT(*) FROM feedback WHERE user_id IS NULL AND ip_hash = ? AND created_at > NOW() - INTERVAL 1 DAY', [ip_hash()])->fetchColumn();
        if ($today >= GUEST_REVIEWS_PER_IP_PER_DAY) {
            fail('Too many reviews from this connection today. Please try again tomorrow.', 429);
        }
    }
    q(
        "INSERT INTO feedback (user_id, guest_key, guest_name, ip_hash, rating, comment, status) VALUES (?, ?, ?, ?, ?, ?, 'approved')",
        [$userId, $userId ? null : $param, $userId ? null : $name, $userId ? null : ip_hash(), $rating, $comment]
    );
}
json_ok(['mine' => my_review($me)]);
