<?php
// Community "Feeling/activity" choices (api/community.php checks a post's feeling against these keys, and
// pages/community.php shows them in the picker). A post stores the key, e.g. "feeling:happy" or "activity:eating";
// 'text' is what the post header says after "is": "feeling happy", "eating".

declare(strict_types=1);

const COMMUNITY_FEELINGS = [
    'happy'     => ['emoji' => '😊', 'text' => 'feeling happy'],
    'blessed'   => ['emoji' => '😇', 'text' => 'feeling blessed'],
    'loved'     => ['emoji' => '🥰', 'text' => 'feeling loved'],
    'in_love'   => ['emoji' => '😍', 'text' => 'feeling in love'],
    'excited'   => ['emoji' => '🤩', 'text' => 'feeling excited'],
    'grateful'  => ['emoji' => '🙏', 'text' => 'feeling grateful'],
    'proud'     => ['emoji' => '😎', 'text' => 'feeling proud'],
    'relaxed'   => ['emoji' => '😌', 'text' => 'feeling relaxed'],
    'silly'     => ['emoji' => '🤪', 'text' => 'feeling silly'],
    'hungry'    => ['emoji' => '😋', 'text' => 'feeling hungry'],
    'tired'     => ['emoji' => '😴', 'text' => 'feeling tired'],
    'sad'       => ['emoji' => '😢', 'text' => 'feeling sad'],
    'emotional' => ['emoji' => '🥲', 'text' => 'feeling emotional'],
    'angry'     => ['emoji' => '😡', 'text' => 'feeling angry'],
    'sick'      => ['emoji' => '🤒', 'text' => 'feeling sick'],
    'surprised' => ['emoji' => '😮', 'text' => 'feeling surprised'],
];

const COMMUNITY_ACTIVITIES = [
    'eating'      => ['emoji' => '🍽️', 'text' => 'eating'],
    'drinking'    => ['emoji' => '☕', 'text' => 'drinking'],
    'cooking'     => ['emoji' => '🍳', 'text' => 'cooking'],
    'traveling'   => ['emoji' => '✈️', 'text' => 'traveling'],
    'shopping'    => ['emoji' => '🛍️', 'text' => 'shopping'],
    'watching'    => ['emoji' => '📺', 'text' => 'watching something'],
    'listening'   => ['emoji' => '🎧', 'text' => 'listening to music'],
    'playing'     => ['emoji' => '🎮', 'text' => 'playing games'],
    'celebrating' => ['emoji' => '🎉', 'text' => 'celebrating'],
    'working'     => ['emoji' => '💼', 'text' => 'working'],
    'studying'    => ['emoji' => '📚', 'text' => 'studying'],
    'exercising'  => ['emoji' => '🏃', 'text' => 'exercising'],
];

/** ['key' => 'feeling:happy', 'emoji' => '😊', 'text' => 'feeling happy'] for a stored key, or null for an unknown one. */
function community_feeling(?string $key): ?array
{
    if ($key === null || !str_contains($key, ':')) {
        return null;
    }
    [$kind, $name] = explode(':', $key, 2);
    $item = ($kind === 'feeling' ? COMMUNITY_FEELINGS : ($kind === 'activity' ? COMMUNITY_ACTIVITIES : []))[$name] ?? null;
    return $item ? ['key' => $key] + $item : null;
}
