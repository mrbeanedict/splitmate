<?php
// Cuts the chameleon poses in assets/mascot/src/*.png (480×480, drawn on white) out into transparent PNGs in
// assets/mascot/, for the landing page. Only paper that touches the picture's edge is removed, so the belly, a held
// receipt and a phone screen stay solid. Needs PHP's GD extension; run from the project root:
//   C:\xampp\php\php.exe -d extension=gd tools\build-mascot-extra.php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this script from the command line.');
}
if (!function_exists('imagecreatefrompng')) {
    exit("GD is missing. Run: C:\\xampp\\php\\php.exe -d extension=gd tools\\build-mascot-extra.php\n");
}

$root = dirname(__DIR__);
const PAPER = 232;   // a pixel whose darkest channel is above this counts as paper
const FADE = 160;    // ...and from here up it starts to fade, so the edge has no white fringe
const OUT_HEIGHT = 380;

foreach (glob("$root/assets/mascot/src/*.png") as $file) {
    $name = basename($file, '.png');
    $src = imagecreatefrompng($file);
    [$w, $h] = [imagesx($src), imagesy($src)];

    $light = [];
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $c = imagecolorat($src, $x, $y);
            $light[$y * $w + $x] = min(($c >> 16) & 255, ($c >> 8) & 255, $c & 255) > PAPER;
        }
    }
    // Paper = light pixels connected to the border
    $paper = array_fill(0, $w * $h, false);
    $stack = [];
    for ($x = 0; $x < $w; $x++) {
        $stack[] = $x;
        $stack[] = ($h - 1) * $w + $x;
    }
    for ($y = 0; $y < $h; $y++) {
        $stack[] = $y * $w;
        $stack[] = $y * $w + $w - 1;
    }
    while ($stack) {
        $i = array_pop($stack);
        if ($paper[$i] || !$light[$i]) {
            continue;
        }
        $paper[$i] = true;
        $x = $i % $w;
        if ($x > 0) $stack[] = $i - 1;
        if ($x < $w - 1) $stack[] = $i + 1;
        if ($i >= $w) $stack[] = $i - $w;
        if ($i < ($h - 1) * $w) $stack[] = $i + $w;
    }

    $out = imagecreatetruecolor($w, $h);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
    [$minX, $minY, $maxX, $maxY] = [$w, $h, 0, 0];
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $i = $y * $w + $x;
            if ($paper[$i]) {
                continue;
            }
            $c = imagecolorat($src, $x, $y);
            [$r, $g, $b] = [($c >> 16) & 255, ($c >> 8) & 255, $c & 255];
            $edge = $x === 0 || $y === 0 || $x === $w - 1 || $y === $h - 1 || $paper[$i - 1] || $paper[$i + 1] || $paper[$i - $w] || $paper[$i + $w];
            $alpha = $edge ? (int) round(127 * max(0, min(1, (min($r, $g, $b) - FADE) / (PAPER - FADE)))) : 0;
            imagesetpixel($out, $x, $y, imagecolorallocatealpha($out, $r, $g, $b, $alpha));
            if ($alpha < 100) {
                [$minX, $minY, $maxX, $maxY] = [min($minX, $x), min($minY, $y), max($maxX, $x), max($maxY, $y)];
            }
        }
    }

    $pad = 4;
    [$minX, $minY] = [max(0, $minX - $pad), max(0, $minY - $pad)];
    [$cw, $ch] = [min($w, $maxX + $pad) - $minX + 1, min($h, $maxY + $pad) - $minY + 1];
    $ow = (int) round($cw * OUT_HEIGHT / $ch);
    $dst = imagecreatetruecolor($ow, OUT_HEIGHT);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    imagecopyresampled($dst, $out, 0, 0, $minX, $minY, $ow, OUT_HEIGHT, $cw, $ch);
    imagepng($dst, "$root/assets/mascot/$name.png", 9);
    printf("%-10s → assets/mascot/%s.png (%d×%d)\n", $name, $name, $ow, OUT_HEIGHT);
}
