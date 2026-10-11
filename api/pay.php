<?php
// "Pay me" page data: whose QR was scanned, how to pay them, and what I still owe them.
require __DIR__ . '/../includes/api.php';

$me = api_user();
if (method() !== 'GET') {
    fail('Method not allowed.', 405);
}

$code = (string) ($_GET['u'] ?? '');
if (!preg_match('/^[A-Za-z0-9]{12}$/', $code)) {
    fail('This Pay-me QR is not valid.', 404);
}
$u = q("SELECT id, full_name, avatar_color, payment_method, payment_account, status FROM users
        WHERE pay_code = BINARY ? AND role = 'user'", [$code])->fetch();
if (!$u || $u['status'] !== 'active') {
    fail('This Pay-me QR is no longer valid. Ask them for their new one.', 404);
}

$owe = q(
    "SELECT s.id, s.currency, s.amount - s.paid_amount AS amount, s.status, b.name AS bill_name, b.id AS bill_id FROM settlements s JOIN bills b ON b.id = s.bill_id
     WHERE s.from_user_id = ? AND s.to_user_id = ? AND s.status <> 'settled' ORDER BY s.created_at",
    [$me['id'], $u['id']]
)->fetchAll();
// One total per currency (debts to the same person can be in several)
$totals = [];
foreach ($owe as $s) {
    $totals[$s['currency']] = ($totals[$s['currency']] ?? 0) + cents($s['amount']);
}

json_ok([
    'person' => public_user($u) + [
        'payment_method'  => $u['payment_method'],
        'payment_account' => $u['payment_account'],
    ],
    'is_me'  => (int) $u['id'] === $me['id'],
    'owe'    => array_map(fn ($s) => [
        'id'        => (int) $s['id'],
        'amount'    => (float) $s['amount'],
        'currency'  => $s['currency'],
        'status'    => $s['status'],
        'bill_name' => $s['bill_name'],
        'bill_id'   => (int) $s['bill_id'],
    ], $owe),
    'owe_totals' => array_map('pesos', $totals),
]);
