<?php
// Member search for the Create Bill / Invite Member pickers.
require __DIR__ . '/../includes/api.php';

$me = api_user();
$term = trim((string) ($_GET['q'] ?? ''));
if (strlen($term) < 2) {
    json_ok(['users' => []]);
}

$like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
$rows = q(
    "SELECT id, full_name, email, avatar_color, avatar, role, featured_achievement FROM users
     WHERE role = 'user' AND status = 'active' AND id <> ? AND (full_name LIKE ? OR email LIKE ?)
     ORDER BY full_name LIMIT 8",
    [$me['id'], $like, $like]
)->fetchAll();

json_ok(['users' => array_map(fn ($u) => public_user($u) + ['email' => $u['email']], $rows)]);
