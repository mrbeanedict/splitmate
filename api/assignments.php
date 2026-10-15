<?php
// Tap-to-assign: set which members share each item.
require __DIR__ . '/../includes/api.php';

$me = api_user();
if (method() !== 'POST') {
    fail('Method not allowed.', 405);
}

$bill = bill_for(int_param('bill_id'), $me);
require_creator($bill, $me);
require_editable($bill);

$memberIds = array_column(bill_members($bill['id']), 'id');
$itemIds = array_map('intval', q('SELECT id FROM receipt_items WHERE bill_id = ?', [$bill['id']])->fetchAll(PDO::FETCH_COLUMN));

$action = input('action', 'set');
if ($action === 'split_evenly') {
    // Assign every still-unassigned item to all members (e.g. communal dishes).
    $map = [];
    foreach (bill_items($bill['id']) as $it) {
        if (!$it['who']) {
            $map[$it['id']] = $memberIds;
        }
    }
} elseif ($action === 'split_all') {
    // Everything shared by all members, replacing what was assigned before.
    $map = array_fill_keys($itemIds, $memberIds);
} else {
    $map = input('assignments', []);
    if (!is_array($map)) {
        fail('Invalid assignments.', 422);
    }
}

$pdo = db();
$pdo->beginTransaction();
foreach ($map as $itemId => $userIds) {
    $itemId = (int) $itemId;
    if (!in_array($itemId, $itemIds, true)) {
        fail('Item not found on this bill.', 422);
    }
    $userIds = array_values(array_intersect(array_map('intval', (array) $userIds), $memberIds));
    q('DELETE FROM item_assignments WHERE item_id = ?', [$itemId]);
    foreach (array_unique($userIds) as $uid) {
        q('INSERT INTO item_assignments (item_id, user_id) VALUES (?, ?)', [$itemId, $uid]);
    }
}
q("UPDATE bills SET status = 'active' WHERE id = ? AND status = 'draft'", [$bill['id']]);
$pdo->commit();

json_ok(['items' => bill_items($bill['id'])]);
