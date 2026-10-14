<?php
// The hover card for a place: GET ?lat&lng&name -> { name, address, photo: bool, tile: {fx, fy} }.
// The picture itself is api/place-photo.php (the CSP only allows images from this site).
require __DIR__ . '/../includes/api.php';
require __DIR__ . '/../includes/place_lookup.php';

const PLACE_CARDS_PER_HOUR = 120;

api_user();
if (method() !== 'GET') {
    fail('Method not allowed.', 405);
}
[$lat, $lng] = place_coords();

$label = trim(preg_replace('/\s+/', ' ', mb_substr((string) ($_GET['name'] ?? ''), 0, 200)) ?? '');
$parts = array_map('trim', explode(',', $label, 2));
$name = $parts[0] ?? '';
$address = $parts[1] ?? '';

$hits = array_filter($_SESSION['place_card_hits'] ?? [], fn ($t) => $t > time() - 3600);
$cached = place_cache_get(place_cache_file('url', round($lat, 4) . '|' . round($lng, 4))) !== null;
if (!$cached) {
    if (count($hits) >= PLACE_CARDS_PER_HOUR) {
        fail('Too many lookups. Please try again in a little while.', 429);
    }
    $hits[] = time();
    $_SESSION['place_card_hits'] = array_values($hits);
}
session_write_close();

$t = place_tile($lat, $lng);
json_ok(['name' => $name, 'address' => $address, 'photo' => place_photo_url($lat, $lng) !== null, 'tile' => ['fx' => $t['fx'], 'fy' => $t['fy']]]);
