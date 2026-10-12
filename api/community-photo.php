<?php
// A community post photo, for anyone who may see that post (public posts, or the author's own).
require __DIR__ . '/../includes/api.php';
require __DIR__ . '/../includes/uploads.php';

$me = api_user();
$photo = q(
    'SELECT ph.image, p.audience, p.user_id FROM community_photos ph JOIN community_posts p ON p.id = ph.post_id WHERE ph.id = ?',
    [int_param('id')]
)->fetch();
if (!$photo || ($photo['audience'] !== 'public' && (int) $photo['user_id'] !== $me['id'] && $me['role'] !== 'admin')) {
    fail('Photo not found.', 404);
}
serve_upload('community', $photo['image']);
