<?php
// A picture for the place card: GET ?lat&lng. A Wikipedia / Wikimedia photo of the place when there is one, otherwise
// the OpenStreetMap tile around it. Proxied (and cached) because the CSP only allows images from this site.
require __DIR__ . '/../includes/api.php';
require __DIR__ . '/../includes/place_lookup.php';

api_user();
if (method() !== 'GET') {
    fail('Method not allowed.', 405);
}
[$lat, $lng] = place_coords();
session_write_close();

$url = place_photo_url($lat, $lng);
if ($url === null) {
    $t = place_tile($lat, $lng);
    $url = 'https://tile.openstreetmap.org/' . PLACE_ZOOM . '/' . $t['x'] . '/' . $t['y'] . '.png';
}
$file = place_cache_file('img', $url);
$bytes = place_cache_get($file);
if ($bytes === null) {
    $bytes = place_http($url);
    $type = $bytes === null ? '' : (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
    if ($bytes === null || !in_array($type, ['image/jpeg', 'image/png'], true) || strlen($bytes) > 3_000_000) {
        fail('Picture unavailable.', 502);
    }
    @file_put_contents($file, $bytes);
}
header('Content-Type: ' . (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes));
header('Content-Length: ' . strlen($bytes));
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');
echo $bytes;
