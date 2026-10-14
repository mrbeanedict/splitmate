<?php
// Looks up a picture for a place (by coordinates) from Wikipedia / Wikimedia Commons, for the community place card.
// No API key needed. Used by api/place-card.php (details) and api/place-photo.php (image proxy, since the CSP only
// lets pages load images from this site).

const PLACE_UA = "User-Agent: SetloSplitmate/1.0 (community place card)\r\nAccept: application/json\r\n";
const PLACE_CACHE_SECONDS = 604800;
const PLACE_ZOOM = 17;

/** GET a URL with a short timeout; the body, or null. */
function place_http(string $url): ?string
{
    $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 6, 'header' => PLACE_UA]]);
    $raw = @file_get_contents($url, false, $ctx);
    return $raw === false ? null : $raw;
}

function place_cache_file(string $kind, string $key): string
{
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'setlo_place_' . $kind . '_' . md5($key);
}

function place_cache_get(string $file): ?string
{
    return is_file($file) && time() - (int) filemtime($file) < PLACE_CACHE_SECONDS ? (string) file_get_contents($file) : null;
}

/** A picture URL on upload.wikimedia.org near these coordinates, or null (cached, including "none"). */
function place_photo_url(float $lat, float $lng): ?string
{
    $coord = round($lat, 4) . '|' . round($lng, 4);
    $file = place_cache_file('url', $coord);
    $hit = place_cache_get($file);
    if ($hit !== null) {
        return $hit === '' ? null : $hit;
    }
    $found = '';
    $attempts = [
        // Wikipedia articles about places right here come with a curated lead picture
        ['https://en.wikipedia.org/w/api.php', ['generator' => 'geosearch', 'ggsradius' => 250, 'ggslimit' => 5, 'prop' => 'pageimages', 'piprop' => 'thumbnail', 'pithumbsize' => 520]],
        // otherwise any photo geotagged on Wikimedia Commons within a short walk
        ['https://commons.wikimedia.org/w/api.php', ['generator' => 'geosearch', 'ggsradius' => 250, 'ggslimit' => 8, 'ggsnamespace' => 6, 'prop' => 'imageinfo', 'iiprop' => 'url|mime', 'iiurlwidth' => 520]],
    ];
    foreach ($attempts as [$base, $params]) {
        $raw = place_http($base . '?' . http_build_query($params + ['action' => 'query', 'format' => 'json', 'ggscoord' => $coord]));
        $pages = $raw ? (json_decode($raw, true)['query']['pages'] ?? []) : [];
        uasort($pages, fn ($a, $b) => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));
        foreach ($pages as $pg) {
            $u = $pg['thumbnail']['source'] ?? (in_array($pg['imageinfo'][0]['mime'] ?? '', ['image/jpeg', 'image/png'], true) ? ($pg['imageinfo'][0]['thumburl'] ?? '') : '');
            if ($u !== '' && parse_url($u, PHP_URL_HOST) === 'upload.wikimedia.org') {
                $found = $u;
                break 2;
            }
        }
    }
    @file_put_contents($file, $found);
    return $found === '' ? null : $found;
}

/** The OpenStreetMap tile around a point, and where in it (0–1) the point sits. */
function place_tile(float $lat, float $lng): array
{
    $n = 2 ** PLACE_ZOOM;
    $x = ($lng + 180) / 360 * $n;
    $latR = deg2rad($lat);
    $y = (1 - log(tan($latR) + 1 / cos($latR)) / M_PI) / 2 * $n;
    return ['x' => (int) floor($x), 'y' => (int) floor($y), 'fx' => round($x - floor($x), 3), 'fy' => round($y - floor($y), 3)];
}

/** Parses ?lat & ?lng, or ends with a 400. */
function place_coords(): array
{
    $lat = filter_var($_GET['lat'] ?? null, FILTER_VALIDATE_FLOAT);
    $lng = filter_var($_GET['lng'] ?? null, FILTER_VALIDATE_FLOAT);
    if ($lat === false || $lng === false || abs($lat) > 85 || abs($lng) > 180) {
        fail('Invalid location.', 400);
    }
    return [(float) $lat, (float) $lng];
}
