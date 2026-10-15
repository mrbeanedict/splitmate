<?php
// Place suggestions for the community composer. The browser can't call OpenStreetMap's Nominatim directly (the CSP only
// allows this site), so it asks here: GET ?q=text -> { places: [{ label, lat, lng }] }.
require __DIR__ . '/../includes/api.php';

const PLACES_PER_HOUR = 60;
const PLACES_CACHE_SECONDS = 86400;

api_user();

if (method() !== 'GET') {
    fail('Method not allowed.', 405);
}

$query = trim(preg_replace('/\s+/', ' ', (string) ($_GET['q'] ?? '')) ?? '');
if (mb_strlen($query) < 3) {
    json_ok(['places' => []]);
}
$query = mb_substr($query, 0, 160);

$cacheFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'setlo_places_v2_' . md5(mb_strtolower($query)) . '.json';
if (is_file($cacheFile) && time() - (int) filemtime($cacheFile) < PLACES_CACHE_SECONDS) {
    $cached = json_decode((string) file_get_contents($cacheFile), true);
    if (is_array($cached)) {
        json_ok(['places' => $cached]);
    }
}

// At most PLACES_PER_HOUR live lookups per session per hour (cached answers above don't count)
$hits = array_filter($_SESSION['places_hits'] ?? [], fn ($t) => $t > time() - 3600);
if (count($hits) >= PLACES_PER_HOUR) {
    fail('You’re searching too fast. Please try again in a little while.', 429);
}
$hits[] = time();
$_SESSION['places_hits'] = array_values($hits);

/** One Nominatim search (null when the service can't be reached), as [{label, lat, lng}]. $extra adds query parameters. */
function search_places(string $query, array $extra = []): ?array
{
    $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query(['format' => 'jsonv2', 'limit' => 5, 'accept-language' => 'en', 'q' => $query] + $extra);
    $context = stream_context_create(['http' => [
        'method'  => 'GET',
        'timeout' => 6,
        'header'  => "User-Agent: SetloSplitmate/1.0 (community place search)\r\nAccept: application/json\r\n",
    ]]);
    $raw = @file_get_contents($url, false, $context);
    $rows = $raw === false ? null : json_decode($raw, true);
    if (!is_array($rows)) {
        return null;
    }
    $places = [];
    foreach ($rows as $r) {
        if (!isset($r['lat'], $r['lon'], $r['display_name']) || !is_numeric($r['lat']) || !is_numeric($r['lon'])) {
            continue;
        }
        $label = (string) $r['display_name'];
        if (mb_strlen($label) > 160) {
            $label = mb_substr($label, 0, 159) . '…';
        }
        $places[] = ['label' => $label, 'lat' => round((float) $r['lat'], 6), 'lng' => round((float) $r['lon'], 6)];
    }
    return $places;
}

// The Philippines first; only when that finds little, add worldwide matches after them (so a place abroad can still be pinned).
$places = search_places($query, ['countrycodes' => 'ph']);
if ($places === null) {
    fail('Place search is unavailable right now.', 502);
}
if (count($places) < 3) {
    $seen = array_map(fn ($p) => $p['lat'] . ',' . $p['lng'], $places);
    foreach (search_places($query) ?? [] as $p) {
        if (count($places) >= 5) {
            break;
        }
        if (!in_array($p['lat'] . ',' . $p['lng'], $seen, true)) {
            $places[] = $p;
        }
    }
}

@file_put_contents($cacheFile, json_encode($places));
json_ok(['places' => $places]);
