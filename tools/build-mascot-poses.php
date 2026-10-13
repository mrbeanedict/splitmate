<?php
// Cuts the Setlo chameleon's poses out of the pose sheet (assets/mascot/sheet.jpg, 1024×1024 on white) into
// transparent PNGs for the dashboard tour (assets/js/tour.js). Needs PHP's GD extension; run from the project root:
//   C:\xampp\php\php.exe -d extension=gd tools\build-mascot-poses.php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this script from the command line.');
}
if (!function_exists('imagecreatefromjpeg')) {
    exit("GD is missing. Run: C:\\xampp\\php\\php.exe -d extension=gd tools\\build-mascot-poses.php\n");
}

$root = dirname(__DIR__);
const WHITE = 228;      // a pixel whose darkest channel is above this counts as paper
const HEIGHT = 360;     // output height in px (sharp at the ~180 px the tour shows them on 2× screens)

// Each pose: a point on its body (the piece of artwork it grows from), a box it can't leave, and boxes it can't enter.
// Where two poses touch on the sheet (the thinking tail runs into the thumbs-up head) these keep them apart.
const POSES = [
    'wave'   => ['seed' => [230, 300], 'box' => [40, 40, 420, 470]],
    'run'    => ['seed' => [800, 300], 'box' => [560, 40, 980, 425]],
    'think'  => ['seed' => [500, 560], 'box' => [400, 325, 700, 670]],
    'cheer'  => ['seed' => [230, 750], 'box' => [40, 495, 360, 940]],
    'thumbs' => ['seed' => [500, 800], 'box' => [340, 600, 640, 985], 'not' => [[585, 590, 640, 668]]],
    'search' => ['seed' => [800, 860], 'box' => [625, 660, 1000, 985]],
];

$src = imagecreatefromjpeg("$root/assets/mascot/sheet.jpg");
[$W, $H] = [imagesx($src), imagesy($src)];

// Paper: near-white pixels reachable from the sheet's edge. Enclosed whites (eyes, belly, the magnifier lens) stay.
$light = [];
for ($y = 0; $y < $H; $y++) {
    for ($x = 0; $x < $W; $x++) {
        $c = imagecolorat($src, $x, $y);
        $light[$y * $W + $x] = min(($c >> 16) & 255, ($c >> 8) & 255, $c & 255) > WHITE;
    }
}
$paper = array_fill(0, $W * $H, false);
$stack = [];
for ($x = 0; $x < $W; $x++) {
    $stack[] = $x;
    $stack[] = ($H - 1) * $W + $x;
}
for ($y = 0; $y < $H; $y++) {
    $stack[] = $y * $W;
    $stack[] = $y * $W + $W - 1;
}
while ($stack) {
    $i = array_pop($stack);
    if ($paper[$i] || !$light[$i]) {
        continue;
    }
    $paper[$i] = true;
    $x = $i % $W;
    if ($x > 0) $stack[] = $i - 1;
    if ($x < $W - 1) $stack[] = $i + 1;
    if ($i >= $W) $stack[] = $i - $W;
    if ($i < ($H - 1) * $W) $stack[] = $i + $W;
}

foreach (POSES as $name => $pose) {
    ['seed' => [$sx, $sy], 'box' => [$x0, $y0, $x1, $y1]] = $pose;
    // The pose: everything that isn't paper, connected to the seed, inside the box
    $mine = [];
    $stack = [$sy * $W + $sx];
    [$minX, $minY, $maxX, $maxY] = [$W, $H, 0, 0];
    while ($stack) {
        $i = array_pop($stack);
        if (isset($mine[$i]) || $paper[$i]) {
            continue;
        }
        [$x, $y] = [$i % $W, intdiv($i, $W)];
        if ($x < $x0 || $x > $x1 || $y < $y0 || $y > $y1) {
            continue;
        }
        foreach ($pose['not'] ?? [] as [$nx0, $ny0, $nx1, $ny1]) {
            if ($x >= $nx0 && $x <= $nx1 && $y >= $ny0 && $y <= $ny1) {
                continue 2;
            }
        }
        $mine[$i] = true;
        [$minX, $minY, $maxX, $maxY] = [min($minX, $x), min($minY, $y), max($maxX, $x), max($maxY, $y)];
        $stack[] = $i - 1;
        $stack[] = $i + 1;
        $stack[] = $i - $W;
        $stack[] = $i + $W;
    }

    $pad = 3;
    [$cw, $ch] = [$maxX - $minX + 1 + 2 * $pad, $maxY - $minY + 1 + 2 * $pad];
    $cut = imagecreatetruecolor($cw, $ch);
    imagealphablending($cut, false);
    imagesavealpha($cut, true);
    imagefill($cut, 0, 0, imagecolorallocatealpha($cut, 0, 0, 0, 127));
    for ($y = $minY; $y <= $maxY; $y++) {
        for ($x = $minX; $x <= $maxX; $x++) {
            $i = $y * $W + $x;
            if (!isset($mine[$i])) {
                continue;
            }
            $c = imagecolorat($src, $x, $y);
            [$r, $g, $b] = [($c >> 16) & 255, ($c >> 8) & 255, $c & 255];
            // Soft edge: light pixels next to the paper fade out instead of leaving a white fringe
            $edge = $paper[$i - 1] || $paper[$i + 1] || $paper[$i - $W] || $paper[$i + $W];
            $alpha = 0;
            if ($edge) {
                $alpha = (int) round(127 * max(0, min(1, (min($r, $g, $b) - 150) / (WHITE - 150))));
            }
            imagesetpixel($cut, $x - $minX + $pad, $y - $minY + $pad, imagecolorallocatealpha($cut, $r, $g, $b, $alpha));
        }
    }

    $ow = (int) round($cw * HEIGHT / $ch);
    $out = imagecreatetruecolor($ow, HEIGHT);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
    imagecopyresampled($out, $cut, 0, 0, 0, 0, $ow, HEIGHT, $cw, $ch);
    imagepng($out, "$root/assets/mascot/$name.png", 9);
    printf("%-7s %4d px of artwork → assets/mascot/%s.png (%d×%d)\n", $name, count($mine), $name, $ow, HEIGHT);
}
