<?php
// Ratings and feedback: the public summary and approved reviews (landing page), and each signed-in user's own review.
require __DIR__ . '/../includes/api.php';

/** "Ana Reyes" shown as "Ana R." — reviews are public, so no full names. */
function review_name(string $fullName): string
{
    $parts = preg_split('/\s+/', trim($fullName)) ?: [];
    $first = $parts[0] ?? 'Setlo user';
    return count($parts) > 1 ? $first . ' ' . mb_substr(end($parts), 0, 1) . '.' : $first;
}

function my_review(int $userId): ?array
{
    $r = q('SELECT rating, comment, status FROM feedback WHERE user_id = ?', [$userId])->fetch();
    return $r ? ['rating' => (int) $r['rating'], 'comment' => (string) $r['comment'], 'status' => $r['status']] : null;
}

if (method() === 'GET') {
    // Public: no sign-in needed
    $dist = array_fill(1, 5, 0);
    foreach (q("SELECT rating, COUNT(*) AS n FROM feedback WHERE status = 'approved' GROUP BY rating")->fetchAll() as $row) {
        $dist[(int) $row['rating']] = (int) $row['n'];
    }
    $count = array_sum($dist);
    $sum = 0;
    foreach ($dist as $stars => $n) {
        $sum += $stars * $n;
    }
    $rows = q(
        "SELECT f.rating, f.comment, f.created_at, u.full_name
         FROM feedback f JOIN users u ON u.id = f.user_id
         WHERE f.status = 'approved' AND f.comment IS NOT NULL AND f.comment <> ''
         ORDER BY f.created_at DESC LIMIT 12"
    )->fetchAll();
    $me = current_user();
    json_ok([
        'average'      => $count ? round($sum / $count, 1) : 0,
        'count'        => $count,
        'distribution' => $dist,
        'reviews'      => array_map(fn ($r) => [
            'name'       => review_name($r['full_name']),
            'rating'     => (int) $r['rating'],
            'comment'    => $r['comment'],
            'created_at' => $r['created_at'],
        ], $rows),
        'signed_in'    => (bool) $me,
        'mine'         => $me ? my_review((int) $me['id']) : null,
    ]);
}

if (method() !== 'POST') {
    fail('Method not allowed.', 405);
}

$me = api_user();
if ($me['role'] !== 'user') {
    fail('Only Setlo users can leave a review.', 403);
}
$rating = (int) input('rating', 0);
if ($rating < 1 || $rating > 5) {
    fail('Choose a rating from 1 to 5 stars.', 422);
}
$comment = str_input('comment', 500, false);
if (preg_match('/[<>]/', $comment)) {
    fail('Please don’t use < or > in your feedback.', 422);
}
$comment = $comment === '' ? null : $comment;

$old = q('SELECT comment, status, updated_at FROM feedback WHERE user_id = ?', [$me['id']])->fetch();
if ($old && strtotime($old['updated_at']) > time() - 30) {
    fail('Please wait a moment before changing your review again.', 429);
}
// A new or reworded comment goes back to the admin; a rating-only change keeps its status.
$status = !$old || ($old['comment'] ?? null) !== $comment ? 'pending' : $old['status'];
q(
    'INSERT INTO feedback (user_id, rating, comment, status) VALUES (?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE rating = VALUES(rating), comment = VALUES(comment), status = VALUES(status)',
    [$me['id'], $rating, $comment, $status]
);
json_ok(['mine' => my_review((int) $me['id'])]);
