<?php
// One-time clean-up of Community posts and comments written before the offensive-language rule existed.
// Run from the project root:
//   C:\xampp\php\php.exe tools\scan-community.php            only lists what it would remove (changes nothing)
//   C:\xampp\php\php.exe tools\scan-community.php --apply    deletes them and tells the authors
// No strike is added to anyone here: these were written before the rule. The text is shown with letters hidden.

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this script from the command line.');
}

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/domain.php';
require __DIR__ . '/../includes/uploads.php';
require __DIR__ . '/../includes/assistant_filter.php';

$apply = in_array('--apply', $argv, true);

/** The first letters of each word, the rest hidden: enough to recognise the post without printing it in full. */
$mask = fn (string $t) => preg_replace_callback('/\p{L}+/u', fn ($m) => mb_substr($m[0], 0, 1) . str_repeat('*', max(0, mb_strlen($m[0]) - 1)), mb_substr($t, 0, 70));

$posts = q("SELECT p.id, p.user_id, p.body, p.location, u.full_name FROM community_posts p JOIN users u ON u.id = p.user_id WHERE u.role <> 'admin' ORDER BY p.id")->fetchAll();
$comments = q("SELECT c.id, c.user_id, c.body, u.full_name FROM community_comments c JOIN users u ON u.id = c.user_id WHERE u.role <> 'admin' ORDER BY c.id")->fetchAll();

$found = ['post' => [], 'comment' => []];
foreach ($posts as $p) {
    if ($hit = assistant_moderate((string) $p['body'] . ' ' . (string) $p['location'])) {
        $found['post'][] = [$p, $hit];
    }
}
foreach ($comments as $c) {
    if ($hit = assistant_moderate((string) $c['body'])) {
        $found['comment'][] = [$c, $hit];
    }
}

printf("Checked %d posts and %d comments.\n\n", count($posts), count($comments));
foreach ($found as $kind => $rows) {
    foreach ($rows as [$row, $hit]) {
        printf("%-8s #%-5d %-22s %-9s \"%s\"\n", $kind, $row['id'], mb_substr((string) $row['full_name'], 0, 22), $hit['category'], $mask((string) $row['body']));
    }
}
$total = count($found['post']) + count($found['comment']);
if (!$total) {
    exit("Nothing to remove.\n");
}
if (!$apply) {
    exit("\n$total item(s) would be removed. Run again with --apply to delete them and notify the authors (no strikes).\n");
}

foreach ($found['post'] as [$p]) {
    $files = q('SELECT image FROM community_photos WHERE post_id = ?', [$p['id']])->fetchAll(PDO::FETCH_COLUMN);
    q('DELETE FROM community_posts WHERE id = ?', [$p['id']]);
    foreach ($files as $file) {
        delete_upload('community', $file);
    }
    notify((int) $p['user_id'], 'community', 'A post of yours was removed because it contained offensive language. This was a one-time clean-up, so no strike was added. New posts that break the rules earn strikes.', null);
}
foreach ($found['comment'] as [$c]) {
    q('DELETE FROM community_comments WHERE id = ?', [$c['id']]);
    notify((int) $c['user_id'], 'community', 'A comment of yours was removed because it contained offensive language. This was a one-time clean-up, so no strike was added. New comments that break the rules earn strikes.', null);
}
echo "\nRemoved $total item(s) and notified the authors.\n";
