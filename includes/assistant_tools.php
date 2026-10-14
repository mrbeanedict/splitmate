<?php
// Setlo Buddy assistant: the tools the model may ask for, and what each one does. Every tool reads only the signed-in
// user's own data — the user id comes from the session, never from the model — and returns small, plain arrays.
// Amounts are in currency units (pesos, not centavos), in the currency named alongside them.

declare(strict_types=1);

/** Tool declarations (upper-case JSON-schema types); groq_tools() turns them into Groq's function format. */
function assistant_tool_declarations(): array
{
    $none = ['type' => 'OBJECT', 'properties' => new stdClass()];
    return [
        [
            'name'        => 'get_balance_summary',
            'description' => 'How much the user owes in total and how much others owe the user, across all unsettled debts, in the user\'s home currency.',
            'parameters'  => $none,
        ],
        [
            'name'        => 'list_settlements',
            'description' => 'The user\'s debts: who owes whom, how much is left, for which bill, and its status. Use for "who hasn\'t paid me", "what do I owe", "who do I owe".',
            'parameters'  => [
                'type'       => 'OBJECT',
                'properties' => [
                    'direction' => ['type' => 'STRING', 'enum' => ['owe', 'owed', 'all'], 'description' => 'owe = the user owes others; owed = others owe the user'],
                    'status'    => ['type' => 'STRING', 'enum' => ['open', 'settled', 'all'], 'description' => 'open = not settled yet (default)'],
                ],
            ],
        ],
        [
            'name'        => 'list_my_bills',
            'description' => 'The user\'s bills (newest first) with status, total, number of members and progress.',
            'parameters'  => [
                'type'       => 'OBJECT',
                'properties' => ['status' => ['type' => 'STRING', 'enum' => ['open', 'closed', 'all'], 'description' => 'open = active or settling (default)']],
            ],
        ],
        [
            'name'        => 'get_bill',
            'description' => 'One of the user\'s bills: members (first names), total, and what the user\'s own share is. Only works for bills the user is a member of.',
            'parameters'  => [
                'type'       => 'OBJECT',
                'properties' => ['bill_id' => ['type' => 'INTEGER']],
                'required'   => ['bill_id'],
            ],
        ],
        [
            'name'        => 'spending_report',
            'description' => 'The user\'s spending for one month: their share of split bills, and their own purchases by category. Use for "how much did I spend this month / last month".',
            'parameters'  => [
                'type'       => 'OBJECT',
                'properties' => ['month' => ['type' => 'STRING', 'description' => 'YYYY-MM; omit for the current month']],
            ],
        ],
        [
            'name'        => 'list_purchases',
            'description' => 'The user\'s most recent personal purchases (title, category, amount, date).',
            'parameters'  => [
                'type'       => 'OBJECT',
                'properties' => ['limit' => ['type' => 'INTEGER', 'description' => '1 to 20, default 10']],
            ],
        ],
        [
            'name'        => 'list_groups',
            'description' => 'The user\'s saved groups with how many members each has.',
            'parameters'  => $none,
        ],
        [
            'name'        => 'propose_reminder',
            'description' => 'Offer to send a payment reminder to someone who owes the user. This does NOT send anything: it shows the user a confirmation card and they decide. Use the settlement id from list_settlements (direction "owes you", status pending). Only call it when the user asks to remind or nudge someone.',
            'parameters'  => [
                'type'       => 'OBJECT',
                'properties' => ['settlement_id' => ['type' => 'INTEGER']],
                'required'   => ['settlement_id'],
            ],
        ],
        [
            'name'        => 'get_setlo_guide',
            'description' => 'The full Setlo how-to guide: step-by-step tutorials (splitting a bill, scanning, settling, paying), currencies, groups and Fun Mode games, achievements and account help. Call it for any "how do I" / "teach me" / "what is" question about using Setlo. Not needed for questions about the user\'s own money.',
            'parameters'  => $none,
        ],
        [
            'name'        => 'convert_currency',
            'description' => 'Convert an amount between currencies at today\'s market rate (an estimate). Currency codes like USD, PHP, JPY, EUR.',
            'parameters'  => [
                'type'       => 'OBJECT',
                'properties' => [
                    'amount' => ['type' => 'NUMBER'],
                    'from'   => ['type' => 'STRING'],
                    'to'     => ['type' => 'STRING'],
                ],
                'required'   => ['amount', 'from', 'to'],
            ],
        ],
    ];
}

/**
 * The model's answer to the conversation in $messages (OpenAI format, system prompt first): it may ask for tools (run
 * for $me and fed back) a few times before it replies in words. Returns the reply text ('' when the model gave none).
 * @throws GroqException
 */
function assistant_answer(array $me, array $messages, int $maxToolRounds = 3): string
{
    $tools = groq_tools(assistant_tool_declarations());
    for ($round = 0; $round <= $maxToolRounds; $round++) {
        // On the last round the tools are withheld, so the model has to answer with what it has.
        $reply = groq_chat($messages, $round < $maxToolRounds ? $tools : []);
        $calls = $reply['tool_calls'] ?? [];
        if (!$calls) {
            return trim((string) ($reply['content'] ?? ''));
        }
        $messages[] = $reply;
        foreach ($calls as $c) {
            $name = (string) ($c['function']['name'] ?? '');
            $args = json_decode((string) ($c['function']['arguments'] ?? ''), true);
            error_log("[setlo] assistant tool $name user {$me['id']}");
            $result = assistant_run_tool($name, is_array($args) ? $args : [], $me);
            $messages[] = [
                'role'         => 'tool',
                'tool_call_id' => (string) ($c['id'] ?? ''),
                'content'      => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            ];
        }
    }
    return '';
}

/** Run one tool for $me. Never throws to the model: problems come back as ['error' => ...]. */
function assistant_run_tool(string $name, array $args, array $me): array
{
    try {
        switch ($name) {
            case 'get_balance_summary':
                return assistant_balance($me);
            case 'list_settlements':
                return assistant_settlements($me, (string) ($args['direction'] ?? 'all'), (string) ($args['status'] ?? 'open'));
            case 'list_my_bills':
                return assistant_bills($me, (string) ($args['status'] ?? 'open'));
            case 'get_bill':
                return assistant_bill($me, (int) ($args['bill_id'] ?? 0));
            case 'spending_report':
                return assistant_spending($me, (string) ($args['month'] ?? ''));
            case 'list_purchases':
                return assistant_purchases($me, (int) ($args['limit'] ?? 10));
            case 'list_groups':
                return assistant_groups($me);
            case 'get_setlo_guide':
                return ['guide' => assistant_guide()[1]];
            case 'propose_reminder':
                return assistant_propose_reminder($me, (int) ($args['settlement_id'] ?? 0));
            case 'convert_currency':
                return assistant_convert((float) ($args['amount'] ?? 0), strtoupper((string) ($args['from'] ?? '')), strtoupper((string) ($args['to'] ?? '')));
        }
    } catch (Throwable $e) {
        error_log("[setlo] assistant tool $name failed: " . $e);
        return ['error' => 'That information is not available right now.'];
    }
    return ['error' => 'Unknown tool.'];
}

function assistant_balance(array $me): array
{
    $home = user_currency($me['id']);
    $out = ['currency' => $home];
    foreach (['you_owe' => 'from_user_id', 'owed_to_you' => 'to_user_id'] as $key => $col) {
        $total = $n = 0;
        $estimated = false;
        $rows = q("SELECT currency, COALESCE(SUM(amount - paid_amount), 0) AS total, COUNT(*) AS n FROM settlements WHERE $col = ? AND status <> 'settled' GROUP BY currency", [$me['id']])->fetchAll();
        foreach ($rows as $r) {
            $n += (int) $r['n'];
            if ($r['currency'] === $home) {
                $total += cents($r['total']);
            } else {
                $estimated = true;
                $total += fx_estimate(cents($r['total']), $r['currency'], $home)['cents'] ?? 0;
            }
        }
        $out[$key] = ['amount' => pesos($total), 'open_debts' => $n, 'estimated_from_other_currencies' => $estimated];
    }
    return $out;
}

function assistant_settlements(array $me, string $direction, string $status): array
{
    $where = match ($direction) {
        'owe'   => 's.from_user_id = ?',
        'owed'  => 's.to_user_id = ?',
        default => '(s.from_user_id = ? OR s.to_user_id = ?)',
    };
    $params = $direction === 'all' || !in_array($direction, ['owe', 'owed'], true) ? [$me['id'], $me['id']] : [$me['id']];
    $statusSql = $status === 'settled' ? "AND s.status = 'settled'" : ($status === 'all' ? '' : "AND s.status <> 'settled'");
    $rows = q(
        "SELECT s.id, s.currency, s.amount, s.paid_amount, s.status, s.from_user_id, b.name AS bill_name, fu.full_name AS from_name, tu.full_name AS to_name
         FROM settlements s JOIN bills b ON b.id = s.bill_id JOIN users fu ON fu.id = s.from_user_id JOIN users tu ON tu.id = s.to_user_id
         WHERE $where $statusSql ORDER BY s.id DESC LIMIT 25",
        $params
    )->fetchAll();
    return ['settlements' => array_map(fn ($r) => [
        'settlement_id' => (int) $r['id'],
        'direction'  => (int) $r['from_user_id'] === $me['id'] ? 'you owe' : 'owes you',
        'person'     => first_name((int) $r['from_user_id'] === $me['id'] ? $r['to_name'] : $r['from_name']),
        'bill'       => $r['bill_name'],
        'remaining'  => round((float) $r['amount'] - (float) $r['paid_amount'], 2),
        'currency'   => $r['currency'],
        'status'     => $r['status'],
    ], $rows)];
}

function assistant_bills(array $me, string $status): array
{
    $statusSql = $status === 'closed' ? "AND b.status = 'closed'" : ($status === 'all' ? '' : "AND b.status IN ('active','settling')");
    $rows = q(
        "SELECT b.*, u.full_name AS creator_name FROM bills b JOIN bill_members m ON m.bill_id = b.id JOIN users u ON u.id = b.creator_id
         WHERE m.user_id = ? AND b.kind = 'bill' AND b.status <> 'draft' $statusSql ORDER BY b.created_at DESC LIMIT 15",
        [$me['id']]
    )->fetchAll();
    return ['bills' => array_map(function ($b) use ($me) {
        $c = bill_card($b);
        return ['id' => $c['id'], 'name' => $c['name'], 'status' => $c['status'], 'total' => $c['total'], 'currency' => $c['currency'],
                'members' => $c['members'], 'progress_percent' => $c['progress'], 'created' => substr((string) $c['created_at'], 0, 10),
                'created_by' => $c['creator'] ? first_name($c['creator']) : null, 'link' => bill_link($b, $me['id'])];
    }, $rows)];
}

function assistant_bill(array $me, int $billId): array
{
    $b = q(
        "SELECT b.*, u.full_name AS creator_name FROM bills b JOIN bill_members m ON m.bill_id = b.id AND m.user_id = ? JOIN users u ON u.id = b.creator_id
         WHERE b.id = ? AND b.kind = 'bill'",
        [$me['id'], $billId]
    )->fetch();
    if (!$b) {
        return ['error' => 'No such bill among your bills.'];
    }
    foreach (['id', 'creator_id', 'payer_id'] as $k) {
        $b[$k] = (int) $b[$k];
    }
    $members = bill_members($b['id']);
    $items = bill_items($b['id']);
    $c = bill_card($b);
    $out = ['id' => $c['id'], 'name' => $c['name'], 'status' => $c['status'], 'total' => $c['total'], 'currency' => $c['currency'],
            'receipt_in_other_currency' => $c['receipt'], 'items' => count($items), 'link' => bill_link($b, $me['id']),
            'members' => array_map(fn ($m) => $m['first'], $members)];
    if ($items) {
        $calc = compute_shares($b, $members, $items);
        $out['your_share'] = pesos($calc['shares'][$me['id']] ?? 0);
        $out['your_share_currency'] = $calc['currency'];
    }
    return $out;
}

function assistant_spending(array $me, string $month): array
{
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
        $month = date('Y-m');
    }
    $home = user_currency($me['id']);
    $from = $month . '-01';
    $to = date('Y-m-d', strtotime("$from +1 month"));

    // My share of the split bills created that month, in my home currency (others at today's market rate)
    $billTotal = 0;
    $count = 0;
    $estimated = false;
    $bills = q(
        "SELECT b.* FROM bills b JOIN bill_members m ON m.bill_id = b.id
         WHERE m.user_id = ? AND b.kind = 'bill' AND b.status IN ('active','settling','closed') AND b.created_at >= ? AND b.created_at < ?",
        [$me['id'], $from, $to]
    )->fetchAll();
    foreach ($bills as $b) {
        foreach (['id', 'creator_id', 'payer_id'] as $k) {
            $b[$k] = (int) $b[$k];
        }
        $items = bill_items($b['id']);
        if (!$items) {
            continue;
        }
        $calc = compute_shares($b, bill_members($b['id']), $items);
        $mine = $calc['shares'][$me['id']] ?? 0;
        if ($mine <= 0) {
            continue;
        }
        $count++;
        if ($calc['currency'] !== $home) {
            $estimated = true;
            $mine = fx_estimate($mine, $calc['currency'], $home)['cents'] ?? 0;
        }
        $billTotal += $mine;
    }

    $byCat = [];
    $purchaseTotal = 0;
    foreach (q('SELECT category, amount FROM purchases WHERE user_id = ? AND purchased_on >= ? AND purchased_on < ?', [$me['id'], $from, $to])->fetchAll() as $p) {
        $byCat[$p['category']] = ($byCat[$p['category']] ?? 0) + cents($p['amount']);
        $purchaseTotal += cents($p['amount']);
    }
    arsort($byCat);

    // A small bar chart for the chat: split bills first, then each purchase category (shown by the page, not the model)
    $bars = $billTotal > 0 ? [['label' => 'Split bills', 'amount' => pesos($billTotal)]] : [];
    foreach (array_slice($byCat, 0, 6, true) as $cat => $c) {
        $bars[] = ['label' => (string) $cat, 'amount' => pesos($c)];
    }
    if ($bars) {
        assistant_actions(['type' => 'chart', 'title' => 'Spending in ' . date('F Y', strtotime($from)), 'currency' => $home, 'bars' => $bars]);
    }

    return [
        'month'    => $month,
        'currency' => $home,
        'split_bills'    => ['your_share_total' => pesos($billTotal), 'bills_counted' => $count, 'estimated_from_other_currencies' => $estimated],
        'own_purchases'  => ['total' => pesos($purchaseTotal), 'by_category' => array_map(fn ($k, $v) => ['category' => $k, 'amount' => pesos($v)], array_keys($byCat), array_values($byCat))],
        'combined_total' => pesos($billTotal + $purchaseTotal),
    ];
}

function assistant_purchases(array $me, int $limit): array
{
    $limit = max(1, min(20, $limit ?: 10));
    $rows = q("SELECT title, category, status, amount, purchased_on FROM purchases WHERE user_id = ? ORDER BY purchased_on DESC, id DESC LIMIT $limit", [$me['id']])->fetchAll();
    return ['currency' => user_currency($me['id']), 'purchases' => array_map(fn ($p) => [
        'title' => $p['title'], 'category' => $p['category'], 'status' => $p['status'], 'amount' => (float) $p['amount'], 'date' => $p['purchased_on'],
    ], $rows)];
}

function assistant_groups(array $me): array
{
    $rows = q(
        'SELECT g.id, g.name, g.owner_id, (SELECT COUNT(*) FROM user_group_members x WHERE x.group_id = g.id) AS members
         FROM user_groups g JOIN user_group_members m ON m.group_id = g.id AND m.user_id = ? ORDER BY g.name',
        [$me['id']]
    )->fetchAll();
    return ['groups' => array_map(fn ($g) => ['name' => $g['name'], 'members' => (int) $g['members'], 'you_created_it' => (int) $g['owner_id'] === $me['id']], $rows)];
}

function assistant_convert(float $amount, string $from, string $to): array
{
    if (!is_currency($from) || !is_currency($to)) {
        return ['error' => 'Unsupported currency code. Supported: ' . implode(', ', array_keys(CURRENCIES))];
    }
    $est = fx_estimate(cents($amount), $from, $to);
    if (!$est) {
        return ['error' => 'No exchange rate is available right now.'];
    }
    return ['amount' => $amount, 'from' => $from, 'to' => $to, 'converted' => pesos($est['cents']), 'rate' => round($est['rate'], 6),
            'rate_source' => $est['source'], 'note' => 'Market estimate; the actual amount charged can differ.'];
}

/**
 * Actions the assistant has proposed during this request. Nothing in here has happened yet: the page shows each one
 * as a card and only runs it (through the normal API, with its own checks) after the user taps Confirm.
 */
function assistant_actions(?array $add = null): array
{
    static $actions = [];
    if ($add !== null) {
        $actions[] = $add;
    }
    return $actions;
}

/** Check that a reminder to this debtor makes sense, and offer it. Sends nothing. */
function assistant_propose_reminder(array $me, int $settlementId): array
{
    $s = q(
        "SELECT s.id, s.currency, s.amount, s.paid_amount, s.status, b.name AS bill_name, fu.full_name AS from_name, fu.role AS from_role
         FROM settlements s JOIN bills b ON b.id = s.bill_id JOIN users fu ON fu.id = s.from_user_id
         WHERE s.id = ? AND s.to_user_id = ?",
        [$settlementId, $me['id']]
    )->fetch();
    if (!$s) {
        return ['error' => 'No such debt owed to the user.'];
    }
    if ($s['from_role'] === 'guest') {
        return ['error' => 'That person is a guest without an account, so they cannot get reminders.'];
    }
    if ($s['status'] !== 'pending') {
        return ['error' => 'There is nothing to remind about on that debt right now (status: ' . $s['status'] . ').'];
    }
    $recent = q(
        "SELECT 1 FROM settlement_events WHERE settlement_id = ? AND actor_id = ? AND event = 'nudged' AND created_at > NOW() - INTERVAL 12 HOUR",
        [$settlementId, $me['id']]
    )->fetchColumn();
    if ($recent) {
        return ['error' => 'A reminder was already sent for that debt in the last 12 hours.'];
    }
    $left = money_str(cents($s['amount']) - cents($s['paid_amount']), $s['currency']);
    $summary = 'Remind ' . first_name($s['from_name']) . " to pay you $left for {$s['bill_name']}";
    assistant_actions(['type' => 'nudge', 'settlement_id' => (int) $s['id'], 'summary' => $summary, 'confirm' => 'Send reminder']);
    return ['proposed' => true, 'summary' => $summary, 'note' => 'A confirmation card is now shown to the user. Tell them to tap it to send. Do not say it was sent.'];
}

/**
 * The help guide, in two parts: [0] the short intro and page list that always goes in the system prompt, [1] the long
 * tutorials, which the model only fetches (get_setlo_guide) for how-to questions. This keeps most requests small.
 */
function assistant_guide(): array
{
    static $parts = null;
    if ($parts === null) {
        $text = (string) @file_get_contents(__DIR__ . '/assistant_help.md');
        $at = strpos($text, '## How to split a bill');
        $parts = $at === false ? [$text, $text] : [trim(substr($text, 0, $at)), trim(substr($text, $at))];
    }
    return $parts;
}