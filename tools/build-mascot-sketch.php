<?php
// Turns the chameleon's face (from assets/mascot/wave.png) into a line-art sketch, assets/mascot/face-sketch.png: solid
// black lines on transparent, so CSS can tint it with mask-image (the receipt-snake backdrop, assets/css/badge-bg.css).
// Needs PHP's GD extension; run from the project root:
//   C:\xampp\php\php.exe -d extension=gd tools\build-mascot-sketch.php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this script from the command line.');
}
if (!function_exists('imagecreatefrompng')) {
    exit("GD is missing. Run: C:\\xampp\\php\\php.exe -d extension=gd tools\\build-mascot-sketch.php\n");
}

$root = dirname(__DIR__);
const OUT_W = 360;       // sketch width in px
const LOW = 28;          // edge strength below this is nothing...
const HIGH = 90;         // ...and from here up it is a full line
const THICK = 3;         // line thickening radius in px

$src = imagecreatefrompng("$root/assets/mascot/wave.png");
[$sw, $sh] = [imagesx($src), imagesy($src)];

// The head: the top part of the picture, without the waving hand
$cx = (int) round($sw * 0.18);
$cy = 0;
$cw = (int) round($sw * 0.62);
$ch = (int) round($sh * 0.46);

// Work at the output size, on white where the picture is transparent
$h = (int) round(OUT_W * $ch / $cw);
$flat = imagecreatetruecolor(OUT_W, $h);
imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
imagecopyresampled($flat, $src, 0, 0, $cx, $cy, OUT_W, $h, $cw, $ch);

$lum = [];
for ($y = 0; $y < $h; $y++) {
    for ($x = 0; $x < OUT_W; $x++) {
        $c = imagecolorat($flat, $x, $y);
        $lum[$y][$x] = 0.299 * (($c >> 16) & 255) + 0.587 * (($c >> 8) & 255) + 0.114 * ($c & 255);
    }
}

$line = [];   // how strongly each pixel is part of a line, 0..1
for ($y = 1; $y < $h - 1; $y++) {
    for ($x = 1; $x < OUT_W - 1; $x++) {
        // Sobel
        $gx = -$lum[$y - 1][$x - 1] + $lum[$y - 1][$x + 1] - 2 * $lum[$y][$x - 1] + 2 * $lum[$y][$x + 1] - $lum[$y + 1][$x - 1] + $lum[$y + 1][$x + 1];
        $gy = -$lum[$y - 1][$x - 1] - 2 * $lum[$y - 1][$x] - $lum[$y - 1][$x + 1] + $lum[$y + 1][$x - 1] + 2 * $lum[$y + 1][$x] + $lum[$y + 1][$x + 1];
        $g = sqrt($gx * $gx + $gy * $gy) / 4;
        $strength = max(0.0, min(1.0, ($g - LOW) / (HIGH - LOW)));
        if ($strength > 0 && !($x > OUT_W * 0.93 && $y > $h * 0.55)) { // not the tip of the waving hand
            $line[$y][$x] = $strength;
        }
    }
}

// Thicken: every pixel takes the strongest line within THICK px, so the sketch stays bold when it is shown small
$out = imagecreatetruecolor(OUT_W, $h);
imagealphablending($out, false);
imagesavealpha($out, true);
imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
for ($y = 0; $y < $h; $y++) {
    for ($x = 0; $x < OUT_W; $x++) {
        $best = 0.0;
        for ($dy = -THICK; $dy <= THICK; $dy++) {
            for ($dx = -THICK; $dx <= THICK; $dx++) {
                if ($dx * $dx + $dy * $dy <= THICK * THICK && isset($line[$y + $dy][$x + $dx])) {
                    $best = max($best, $line[$y + $dy][$x + $dx]);
                }
            }
        }
        if ($best > 0) {
            imagesetpixel($out, $x, $y, imagecolorallocatealpha($out, 0, 0, 0, (int) round(127 * (1 - min(1.0, $best * 1.6)))));
        }
    }
}
// Room around the face, so a repeating tile has air between the faces instead of touching
$pad = (int) round(OUT_W * 0.25);
$tile = imagecreatetruecolor(OUT_W + 2 * $pad, $h + 2 * $pad);
imagealphablending($tile, false);
imagesavealpha($tile, true);
imagefill($tile, 0, 0, imagecolorallocatealpha($tile, 0, 0, 0, 127));
imagecopy($tile, $out, $pad, $pad, 0, 0, OUT_W, $h);
imagepng($tile, "$root/assets/mascot/face-sketch.png", 9);
printf("face-sketch.png %d×%d\n", OUT_W + 2 * $pad, $h + 2 * $pad);
