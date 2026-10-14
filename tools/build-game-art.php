<?php
// Puts the Setlo chameleon (assets/mascot.png, the original artwork cut out of its background) into the Fun Mode
// game picker art (assets/games/*.svg). The pictures load through <img>, which can't fetch other files, so the
// mascot is embedded as a small PNG. Needs PHP's GD extension; run from the project root:
//   C:\xampp\php\php.exe -d extension=gd tools\build-game-art.php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this script from the command line.');
}
if (!function_exists('imagecreatefrompng')) {
    exit("GD is missing. Run: C:\\xampp\\php\\php.exe -d extension=gd tools\\build-game-art.php\n");
}

$root = dirname(__DIR__);

// ---------- The rig: the original artwork cut into pieces that turn around their joints ----------
// Coordinates are pixels on assets/mascot.png (650×785). A pixel belongs to the head or an arm inside its polygon
// (except the joint stub past its 'cut', which stays on the body), then to the body inside BODY, then to the tail
// inside its polygon; any stray outline pixel left over goes to the nearest joint. Limbs are drawn behind the body,
// so a turned limb still looks attached.
const BODY = [286, 340, 420, 340, 432, 440, 446, 540, 452, 640, 525, 690, 525, 785, 140, 785, 140, 735, 195, 700, 238, 600, 272, 520, 276, 440];
const RIG = [
    'tail' => ['pivot' => [230, 640], 'poly' => [25, 478, 242, 478, 252, 560, 246, 600, 216, 662, 196, 706, 150, 728, 25, 732]],
    'armL' => ['pivot' => [292, 420], 'poly' => [0, 222, 126, 226, 152, 276, 205, 396, 292, 384, 300, 484, 230, 484, 150, 484, 95, 482, 0, 468], 'cut' => ['x', '<', 286]],
    'armR' => ['pivot' => [412, 425], 'poly' => [398, 384, 470, 360, 500, 322, 650, 312, 650, 470, 600, 508, 520, 518, 440, 508, 406, 470], 'cut' => ['x', '>', 418]],
    'head' => ['pivot' => [345, 365], 'poly' => [110, 0, 600, 0, 590, 300, 530, 318, 480, 352, 430, 380, 300, 384, 250, 362, 185, 322, 150, 276, 128, 232, 110, 180], 'cut' => ['y', '<', 352]],
];
const SKIN = '#2aa79c';
const EYES = [[199, 162, 58, 61], [410, 210, 84, 84]];   // eye whites: cx, cy, rx, ry (for the blinking lids)
const MOUTH = [176, 246];                                // left corner of the smile
const HEAD_TOP = [420, 8];
const EMBED = 400 / 785;                                 // pieces are embedded at ~400 px tall: sharp on 2× screens

function rig_parts(string $file): array
{
    $src = imagecreatefrompng($file);
    [$w, $h] = [imagesx($src), imagesy($src)];
    $masks = [];
    foreach (['body' => BODY] + array_map(fn ($r) => $r['poly'], RIG) as $name => $poly) {
        $m = imagecreate($w, $h);
        imagecolorallocate($m, 0, 0, 0);
        imagefilledpolygon($m, $poly, imagecolorallocate($m, 255, 255, 255));
        $masks[$name] = $m;
    }
    $owner = function (int $x, int $y) use ($masks): string {
        foreach (['head', 'armR', 'armL'] as $name) {
            if (!imagecolorat($masks[$name], $x, $y)) {
                continue;
            }
            [$axis, $op, $at] = RIG[$name]['cut'];
            $v = $axis === 'x' ? $x : $y;
            if ($op === '<' ? $v < $at : $v > $at) {
                return $name;
            }
            return "$name+body";   // the joint overlap is drawn on both, so turning never opens a gap
        }
        if (imagecolorat($masks['body'], $x, $y)) {
            return 'body';
        }
        if (imagecolorat($masks['tail'], $x, $y)) {
            return 'tail';
        }
        $best = 'body';
        $bestD = INF;
        foreach (RIG as $name => $r) {
            $d = hypot($x - $r['pivot'][0], $y - $r['pivot'][1]);
            if ($d < $bestD) {
                [$best, $bestD] = [$name, $d];
            }
        }
        return $best;
    };
    $layers = [];
    $box = [];
    foreach (['body', ...array_keys(RIG)] as $name) {
        $l = imagecreatetruecolor($w, $h);
        imagealphablending($l, false);
        imagesavealpha($l, true);
        imagefill($l, 0, 0, imagecolorallocatealpha($l, 0, 0, 0, 127));
        $layers[$name] = $l;
        $box[$name] = [$w, $h, 0, 0];
    }
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $c = imagecolorat($src, $x, $y);
            if ((($c >> 24) & 127) === 127) {
                continue;
            }
            $o = $owner($x, $y);
            foreach (explode('+', $o) as $name) {
                imagesetpixel($layers[$name], $x, $y, $c);
                $b = &$box[$name];
                $b = [min($b[0], $x), min($b[1], $y), max($b[2], $x), max($b[3], $y)];
                unset($b);
            }
        }
    }
    $parts = [];
    foreach ($layers as $name => $l) {
        [$x0, $y0, $x1, $y1] = $box[$name];
        [$bw, $bh] = [$x1 - $x0 + 1, $y1 - $y0 + 1];
        $sm = imagecreatetruecolor((int) ceil($bw * EMBED), (int) ceil($bh * EMBED));
        imagealphablending($sm, false);
        imagesavealpha($sm, true);
        imagefill($sm, 0, 0, imagecolorallocatealpha($sm, 0, 0, 0, 127));
        imagecopyresampled($sm, $l, 0, 0, $x0, $y0, imagesx($sm), imagesy($sm), $bw, $bh);
        ob_start();
        imagepng($sm, null, 9);
        $parts[$name] = ['uri' => 'data:image/png;base64,' . base64_encode((string) ob_get_clean()), 'x' => $x0, 'y' => $y0, 'w' => $bw, 'h' => $bh];
    }
    return [$parts, $w, $h];
}

[$PARTS, $AW, $AH] = rig_parts("$root/assets/mascot.png");

function rotate_pt(array $p, array $c, float $deg): array
{
    $a = deg2rad($deg);
    [$dx, $dy] = [$p[0] - $c[0], $p[1] - $c[1]];
    return [$c[0] + $dx * cos($a) - $dy * sin($a), $c[1] + $dx * sin($a) + $dy * cos($a)];
}

/**
 * The mascot with its top-left at (x, y), $h px tall, posed: $pose = ['head' => deg, 'armL' => deg, 'armR' => deg,
 * 'tail' => deg] (positive = clockwise; armL up is positive, armR up is negative) plus optional per-piece animation
 * classes as 'anim' => ['armR' => 'wave', …]. Returns [svg, width, fn mapping a head point on the artwork to the scene].
 */
function mascot(float $x, float $y, float $h, array $pose = [], string $class = 'mascot', float $rotate = 0, string $ids = ''): array
{
    global $PARTS, $AW, $AH;
    $s = $h / $AH;
    $w = $AW * $s;
    $img = fn ($p) => '<image href="' . $p['uri'] . '" x="' . $p['x'] . '" y="' . $p['y'] . '" width="' . $p['w'] . '" height="' . $p['h'] . '"/>';
    $id = fn ($name) => $ids ? ' id="' . $ids . '-' . $name . '"' : '';   // ids let the splash reuse pieces with <use>
    $piece = function (string $name, string $extra = '') use ($pose, $img, $PARTS, $id): string {
        [$px, $py] = RIG[$name]['pivot'];
        $anim = $pose['anim'][$name] ?? '';
        return '<g' . $id($name) . ' transform="translate(' . $px . ' ' . $py . ')"><g class="pt ' . $name . ' ' . $anim . '" style="--r:' . ($pose[$name] ?? 0) . 'deg">'
            . '<g transform="translate(' . -$px . ' ' . -$py . ')">' . $img($PARTS[$name]) . $extra . '</g></g></g>';
    };
    $lids = '';
    foreach (EYES as $i => [$cx, $cy, $rx, $ry]) {
        $lids .= '<clipPath id="eye' . $i . '"><ellipse cx="' . $cx . '" cy="' . $cy . '" rx="' . $rx . '" ry="' . $ry . '"/></clipPath>'
            . '<g clip-path="url(#eye' . $i . ')"><rect class="lid" x="' . ($cx - $rx) . '" y="' . ($cy - $ry) . '" width="' . (2 * $rx) . '" height="' . (2 * $ry + 4) . '" fill="' . SKIN . '"/></g>';
    }
    $rot = $rotate ? ' rotate(' . $rotate . ' ' . round($w / 2, 1) . ' ' . round($h, 1) . ')' : '';
    $svg = '<g class="' . $class . '"><g transform="translate(' . round($x, 1) . ' ' . round($y, 1) . ')' . $rot . ' scale(' . round($s, 5) . ')">'
        . $piece('tail') . $piece('armL') . $piece('armR') . '<g' . $id('body') . '>' . $img($PARTS['body']) . '</g>' . $piece('head', $lids) . '</g></g>';
    $at = function (array $pt) use ($x, $y, $s, $w, $h, $rotate, $pose): array {
        $p = rotate_pt($pt, RIG['head']['pivot'], (float) ($pose['head'] ?? 0));
        $p = [$p[0] * $s, $p[1] * $s];
        if ($rotate) {
            $p = rotate_pt($p, [$w / 2, $h], $rotate);
        }
        return [round($x + $p[0], 1), round($y + $p[1], 1)];
    };
    return [$svg, $w, $at];
}

/**
 * Pose (each piece turned by its --r) always applies; the idle animation (bob, head tilt, tail sway, blink, plus
 * the scene's extras and per-piece classes) is off for reduced motion.
 * Piece animations: wave (arm swings up and down), pump (running arm), pump2 (the other arm, opposite beat).
 */
function scene(string $defs, string $body, string $extra = ''): string
{
    $around = fn ($name, $a, $b) => "@keyframes $name{from{transform:rotate(calc(var(--r) + {$a}deg))}to{transform:rotate(calc(var(--r) + {$b}deg))}}";
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 320 200">' . "\n"
        . '<defs><linearGradient id="bg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#f5d0fe"/><stop offset="1" stop-color="#fed7aa"/></linearGradient>' . $defs . '</defs>' . "\n"
        . '<style>.pt{transform-box:view-box;transform-origin:0 0;transform:rotate(var(--r))}.lid{transform-box:fill-box;transform-origin:50% 0;transform:scaleY(0)}'
        . '@media (prefers-reduced-motion: no-preference) {'
        . '.mascot{animation:bob 1.8s ease-in-out infinite alternate}@keyframes bob{from{transform:translateY(0)}to{transform:translateY(-3px)}}'
        . '.head{animation:tilt 3.2s ease-in-out infinite alternate}' . $around('tilt', -3, 3)
        . '.tail{animation:sway 2.4s ease-in-out infinite alternate}' . $around('sway', -6, 6)
        . '.lid{animation:blink 4.2s infinite}@keyframes blink{0%,92%,100%{transform:scaleY(0)}96%{transform:scaleY(1)}}'
        . '.fun{animation:fun 1.4s ease-in-out .5s both}@keyframes fun{from{opacity:0}to{opacity:1}}'
        . '.pt.wave{animation:wave .9s ease-in-out infinite alternate}' . $around('wave', 0, -26)
        . '.pt.pump{animation:pump .45s ease-in-out infinite alternate}' . $around('pump', -14, 14)
        . '.pt.pump2{animation:pump2 .45s ease-in-out infinite alternate}' . $around('pump2', 14, -14)
        . $extra . '}</style>' . "\n"
        . '<rect width="320" height="200" fill="url(#bg)"/>' . "\n" . $body . "\n</svg>\n";
}

/**
 * A ready-made pose (assets/mascot/<name>.png): cropped to its artwork, embedded at ~2× and bobbing like the rig.
 * $x is the left edge, centre or right edge ($anchor l|c|r), $y the top, $h the height in scene units.
 */
function pose(string $name, float $x, float $y, float $h, string $anchor = 'l'): string
{
    global $root;
    $src = imagecreatefrompng("$root/assets/mascot/$name.png");
    imagealphablending($src, false);
    imagesavealpha($src, true);
    [$w, $ht] = [imagesx($src), imagesy($src)];
    $box = [$w, $ht, 0, 0];
    for ($py = 0; $py < $ht; $py++) {
        for ($px = 0; $px < $w; $px++) {
            if (((imagecolorat($src, $px, $py) >> 24) & 127) < 100) {
                $box = [min($box[0], $px), min($box[1], $py), max($box[2], $px), max($box[3], $py)];
            }
        }
    }
    [$bw, $bh] = [$box[2] - $box[0] + 1, $box[3] - $box[1] + 1];
    $outH = 440;
    $sm = imagecreatetruecolor((int) round($bw * $outH / $bh), $outH);
    imagealphablending($sm, false);
    imagesavealpha($sm, true);
    imagefill($sm, 0, 0, imagecolorallocatealpha($sm, 0, 0, 0, 127));
    imagecopyresampled($sm, $src, 0, 0, $box[0], $box[1], imagesx($sm), $outH, $bw, $bh);
    $embed = function ($im): string {
        ob_start();
        imagepng($im, null, 9);
        return 'data:image/png;base64,' . base64_encode((string) ob_get_clean());
    };
    $w2 = $h * $bw / $bh;
    $x -= $anchor === 'c' ? $w2 / 2 : ($anchor === 'r' ? $w2 : 0);
    $at = ' x="' . round($x, 1) . '" y="' . round($y, 1) . '" width="' . round($w2, 1) . '" height="' . round($h, 1) . '"';
    // A Fun Mode copy, faded in over the green: the teal skin (and the sweat drops) become a head-to-toe gradient, orange
    // -> pink -> purple, and the yellow tail stripes a lighter shade of it. Belly, outlines, tongue and eyes stay.
    $stops = [[0, [242, 100, 31]], [.35, [232, 68, 122]], [.62, [204, 67, 196]], [1, [155, 77, 242]]];
    $grad = function (float $t) use ($stops): array {
        for ($i = 1; $i < count($stops); $i++) {
            if ($t <= $stops[$i][0]) {
                [$t0, $c0] = $stops[$i - 1];
                [$t1, $c1] = $stops[$i];
                $k = ($t - $t0) / ($t1 - $t0);
                return [$c0[0] + ($c1[0] - $c0[0]) * $k, $c0[1] + ($c1[1] - $c0[1]) * $k, $c0[2] + ($c1[2] - $c0[2]) * $k];
            }
        }
        return $stops[count($stops) - 1][1];
    };
    $fun = imagecreatetruecolor(imagesx($sm), imagesy($sm));
    imagealphablending($fun, false);
    imagesavealpha($fun, true);
    $H = imagesy($sm);
    for ($py = 0; $py < $H; $py++) {
        $base = $grad($py / $H);
        for ($px = 0; $px < imagesx($sm); $px++) {
            $c = imagecolorat($sm, $px, $py);
            [$r, $g, $b] = [($c >> 16) & 255, ($c >> 8) & 255, $c & 255];
            $mx = max($r, $g, $b);
            $d = $mx - min($r, $g, $b);
            if ($d > 40) {
                $hue = $mx === $r ? fmod(60 * (($g - $b) / $d) + 360, 360) : ($mx === $g ? 60 * (($b - $r) / $d + 2) : 60 * (($r - $g) / $d + 4));
                $tint = null;
                if ($hue >= 150 && $hue <= 235) {
                    $shade = $mx / 167;                           // teal skin: keep its shading
                    $tint = array_map(fn ($v) => $v * $shade, $base);
                } elseif ($hue >= 30 && $hue <= 70) {
                    $shade = $mx / 245;                           // yellow stripe: lighter, toward white
                    $tint = array_map(fn ($v) => ($v * .55 + 255 * .45) * $shade, $base);
                }
                if ($tint) {
                    [$r, $g, $b] = array_map(fn ($v) => (int) max(0, min(255, round($v))), $tint);
                    $c = ($c & 0xFF000000) | ($r << 16) | ($g << 8) | $b;
                }
            }
            imagesetpixel($fun, $px, $py, $c);
        }
    }
    return '<g class="mascot"><image href="' . $embed($sm) . '"' . $at . '/><image class="fun" href="' . $embed($fun) . '"' . $at . '/></g>';
}

$files = [];

// ---------- Roulette: beside the wheel, penny ready ----------
$m = pose('lean', 312, 8, 186, 'r');
$files['assets/games/roulette.svg'] = scene('', '
  <circle cx="30" cy="30" r="6" fill="#fde047"/><circle cx="304" cy="186" r="4" fill="#d946ef" opacity=".5"/><circle cx="40" cy="176" r="4" fill="#fff"/><circle cx="300" cy="22" r="4" fill="#fff" opacity=".8"/>
  <g fill="none" stroke="#c084fc" stroke-width="4" stroke-linecap="round" opacity=".7"><path d="M24 80a86 86 0 0 1 26-34"/><path d="M150 172a86 86 0 0 1-30 16"/></g>
  <g transform="translate(96 108)">
    <circle r="76" fill="#7e22ce"/>
    <g class="spin">
      <path d="M0 0V-68A68 68 0 0 1 48.1-48.1z" fill="#d946ef"/><path d="M0 0 48.1-48.1A68 68 0 0 1 68 0z" fill="#fff"/>
      <path d="M0 0H68A68 68 0 0 1 48.1 48.1z" fill="#f97316"/><path d="M0 0 48.1 48.1A68 68 0 0 1 0 68z" fill="#fff"/>
      <path d="M0 0V68A68 68 0 0 1-48.1 48.1z" fill="#a855f7"/><path d="M0 0-48.1 48.1A68 68 0 0 1-68 0z" fill="#fff"/>
      <path d="M0 0H-68A68 68 0 0 1-48.1-48.1z" fill="#facc15"/><path d="M0 0-48.1-48.1A68 68 0 0 1 0-68z" fill="#fff"/>
    </g>
    <g fill="#fff"><circle cy="-72" r="3"/><circle cx="72" r="3"/><circle cy="72" r="3"/><circle cx="-72" r="3"/><circle cx="51" cy="-51" r="3"/><circle cx="51" cy="51" r="3"/><circle cx="-51" cy="51" r="3"/><circle cx="-51" cy="-51" r="3"/></g>
    <circle r="14" fill="#7e22ce"/><circle r="6" fill="#fff"/>
    <path d="M-11-90h22L0-66z" fill="#e11d48" stroke="#fff" stroke-width="3" stroke-linejoin="round"/>
  </g>
  ' . $m, '
  .spin{transform-box:fill-box;transform-origin:center;animation:nudge 3.6s ease-in-out infinite}
  @keyframes nudge{0%,100%{transform:rotate(0)}40%{transform:rotate(14deg)}55%{transform:rotate(9deg)}}');

// ---------- Receipt Race: leaning into a sprint with the receipt ----------
$m = pose('sprint', 135, 16, 170, 'c');
$files['assets/games/race.svg'] = scene('<linearGradient id="watch" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#d946ef"/><stop offset="1" stop-color="#f97316"/></linearGradient>', '
  <circle cx="40" cy="30" r="5" fill="#fff" opacity=".7"/><circle cx="300" cy="184" r="4" fill="#fde047"/><circle cx="190" cy="14" r="4" fill="#d946ef" opacity=".4"/>
  <g class="speed" stroke="#fff" stroke-width="5" stroke-linecap="round" opacity=".85"><path d="M12 84h42"/><path d="M4 108h52"/><path d="M14 132h38"/><path d="M26 156h24"/></g>
  <g transform="translate(262 58)">
    <rect x="-5" y="-40" width="10" height="8" rx="2" fill="#7e22ce"/>
    <circle r="32" fill="url(#watch)"/><circle r="24" fill="#fff"/>
    <g stroke="#e9d5ff" stroke-width="2.5" stroke-linecap="round"><path d="M0-20v4"/><path d="M20 0h-4"/><path d="M0 20v-4"/><path d="M-20 0h4"/></g>
    <path class="tick" d="M0 0 0-17" stroke="#f97316" stroke-width="3.5" stroke-linecap="round"/><circle r="3.5" fill="#7e22ce"/>
  </g>
  <g transform="translate(276 120)">
    <path d="M0 0v66" stroke="#475569" stroke-width="4" stroke-linecap="round"/>
    <rect x="2" y="0" width="34" height="22" fill="#fff" stroke="#1e293b" stroke-width="1.5"/>
    <g fill="#1e293b"><rect x="2" y="0" width="8.5" height="7.3"/><rect x="19" y="0" width="8.5" height="7.3"/><rect x="10.5" y="7.3" width="8.5" height="7.3"/><rect x="27.5" y="7.3" width="8.5" height="7.3"/><rect x="2" y="14.6" width="8.5" height="7.3"/><rect x="19" y="14.6" width="8.5" height="7.3"/></g>
  </g>
  ' . $m . '', '
  .run{animation-duration:.45s}
  .speed path{animation:dash .7s linear infinite}.speed path:nth-child(2){animation-delay:-.25s}.speed path:nth-child(3){animation-delay:-.45s}.speed path:nth-child(4){animation-delay:-.6s}
  @keyframes dash{0%{opacity:.2;transform:translateX(10px)}50%{opacity:1}100%{opacity:.2;transform:translateX(-10px)}}
  .tick{transform-box:view-box;transform-origin:0 0;animation:tick 2s steps(8) infinite}@keyframes tick{to{transform:rotate(360deg)}}');

// ---------- Closest Without Going Over: tongue on the bullseye, basket at her feet ----------
$m = '<g transform="translate(320 0) scale(-1 1)">' . pose('tongue', 8, 54, 140) . '</g>';   // mirrored: her tongue then points at the target
$files['assets/games/closest.svg'] = scene('', '
  <circle cx="24" cy="176" r="5" fill="#fde047"/><circle cx="160" cy="20" r="3" fill="#d946ef" opacity=".5"/><circle cx="304" cy="186" r="3" fill="#fff"/>
  <path d="M14 34h292" stroke="#e11d48" stroke-width="3" stroke-dasharray="8 6"/>
  <rect x="14" y="21" width="76" height="22" rx="11" fill="#e11d48"/>
  <text x="52" y="36.5" text-anchor="middle" font-family="Arial, sans-serif" font-size="12" font-weight="800" fill="#fff">TARGET</text>
  <g transform="translate(88 98)"><g class="board">
    <circle r="54" fill="#d946ef"/><circle r="43" fill="#fff"/><circle r="32" fill="#f97316"/><circle r="21" fill="#fff"/><circle r="10" fill="#a855f7"/>
  </g></g>
  <g transform="translate(60 156) scale(.3)">
    <path d="M-70,40 C-60,-40 60,-40 70,40" fill="none" stroke="#a855f7" stroke-width="14" stroke-linecap="round"/>
    <circle cx="-34" cy="28" r="26" fill="#f97316"/><circle cx="16" cy="22" r="23" fill="#22c55e"/><rect x="36" y="-6" width="30" height="50" rx="8" fill="#facc15"/>
    <path d="M-96,40 H96 L72,160 H-72Z" fill="#d946ef"/>
    <g stroke="#f5d0fe" stroke-width="8"><path d="M-88,80 H88"/><path d="M-80,120 H80"/><path d="M-32,40 L-26,160"/><path d="M32,40 L26,160"/></g>
  </g>
  ' . $m . '', '
  .board{transform-box:fill-box;transform-origin:center;animation:hit 2.4s ease-out infinite}
  @keyframes hit{0%,70%,100%{transform:scale(1)}76%{transform:scale(1.06)}84%{transform:scale(.98)}}');

// ---------- Mystery Card: the shocked pose, YOU PAY in hand ----------
$m = pose('shock', 160, 6, 190, 'c');
$files['assets/games/cards.svg'] = scene('', '
  <circle cx="30" cy="40" r="5" fill="#fde047"/><circle cx="296" cy="22" r="4" fill="#d946ef" opacity=".5"/><circle cx="26" cy="160" r="4" fill="#fff"/>
  ' . $m . '
  <g fill="#facc15" class="twinkle"><path d="M232 30l4 10 10 4-10 4-4 10-4-10-10-4 10-4z"/><path d="M40 92l3 7 7 3-7 3-3 7-3-7-7-3 7-3z"/></g>', '
  .twinkle path{transform-box:fill-box;transform-origin:center;animation:tw 1.8s ease-in-out infinite alternate}.twinkle path:nth-child(2){animation-delay:-.9s}
  @keyframes tw{from{transform:scale(.6);opacity:.6}to{transform:scale(1.1);opacity:1}}');

// ---------- Login splash (partials/splash.php): the puppet, its camouflage and the white "o" ----------
// Her tail spiral is the "o" of "setlo": centre (138, 600), radius ~106 on the artwork, placed at (305, 329) with a
// 33-unit radius in the splash scene. While camouflaged, a background-coloured copy of her silhouette (.sp-camo)
// covers her, with holes at the eyes and over the tail, where a white copy of the tail (.sp-o, outlines kept)
// reads as a white letter. assets/css/splash.css fades those away to reveal her.
$S = 33 / 106;
[$SX, $SY] = [305 - 138 * $S, 329 - 600 * $S];
$T = 'translate(' . round($SX, 2) . ' ' . round($SY, 2) . ') scale(' . round($S, 5) . ')';
[$m] = mascot($SX, $SY, $AH * $S, [], 'sp-mascot', 0, 'sp');
$holes = '';
foreach (EYES as [$cx, $cy, $rx, $ry]) {
    $holes .= '<ellipse cx="' . round($SX + $cx * $S, 2) . '" cy="' . round($SY + $cy * $S, 2) . '" rx="' . round($rx * $S, 2) . '" ry="' . round($ry * $S, 2) . '" fill="#000"/>';
}
$solid = fn ($id, $color) => '<filter id="' . $id . '" color-interpolation-filters="sRGB"><feFlood flood-color="' . $color . '"/><feComposite in2="SourceAlpha" operator="in"/></filter>';
$files['partials/splash-mascot.php'] = "<?php // Generated by tools/build-game-art.php — edit that script, not this file. ?>\n"
    . '<defs>'
    . '<filter id="sp-camo-f" color-interpolation-filters="sRGB"><feMorphology in="SourceAlpha" operator="dilate" radius="1.4" result="grown"/><feFlood class="sp-flood"/><feComposite in2="grown" operator="in"/></filter>'   // grown a little so her soft outline edge can't show
    // white where the tail is coloured, transparent on its dark outlines
    . '<filter id="sp-white-f" color-interpolation-filters="sRGB"><feColorMatrix type="matrix" values="0 0 0 0 1 0 0 0 0 1 0 0 0 0 1 1.2 2.36 .44 0 -1"/></filter>'
    . $solid('sp-k-f', '#000') . $solid('sp-w-f', '#fff')
    . '<mask id="sp-holes" maskUnits="userSpaceOnUse" x="0" y="0" width="600" height="500"><rect width="600" height="500" fill="#fff"/>'
    . '<use href="#sp-tail" transform="' . $T . '" filter="url(#sp-k-f)"/><use href="#sp-body" transform="' . $T . '" filter="url(#sp-w-f)"/>' . $holes . '</mask>'
    . '</defs>' . "\n"
    . '<g class="sp-cham"><g id="sp-puppet">' . $m . '</g>' . "\n"
    . '<use class="sp-o" href="#sp-tail" transform="' . $T . '" filter="url(#sp-white-f)"/>'
    . '<use class="sp-camo" href="#sp-puppet" filter="url(#sp-camo-f)" mask="url(#sp-holes)"/></g>' . "\n";

foreach ($files as $path => $svg) {
    file_put_contents("$root/$path", $svg);
    echo "Wrote $path (" . round(strlen($svg) / 1024) . " KB)\n";
}
