<?php
// Login splash (once per browser session): theme color fills the screen, "setl" appears and the "o" is the
// chameleon's tail. She hides camouflaged in the background color (only her eyes show), the word turns tail-yellow,
// the "o" gets its stripes, then she shows herself, lifts her receipt and waves hi with her penny.
// She is the original artwork (assets/mascot.png) cut into pieces by tools/build-game-art.php, which also writes
// partials/splash-mascot.php. Timeline: assets/css/splash.css.
// Shown only while <html> has .splash-on (set before first paint by partials/head.php when $splash is set).
?>
<div id="splash" class="sp-overlay" role="presentation">
  <div class="sp-bg"></div>
  <svg class="sp-scene" viewBox="30 80 510 330" aria-hidden="true" focusable="false">
    <!-- The word: "setl" ends where the tail (the "o") begins -->
    <g class="sp-word">
      <text x="268" y="360" text-anchor="end" class="sp-text"><tspan class="sp-l" style="--i:0">s</tspan><tspan class="sp-l" style="--i:1">e</tspan><tspan class="sp-l" style="--i:2">t</tspan><tspan class="sp-l" style="--i:3">l</tspan></text>
    </g>

    <!-- The chameleon (generated) -->
    <?php require __DIR__ . '/splash-mascot.php'; ?>

    <!-- "Hi!" -->
    <g transform="translate(474 132)">
      <g class="sp-hi">
        <path d="M-20,14 -34,32 -6,17Z" fill="#fff"/>
        <rect x="-38" y="-22" width="76" height="40" rx="20" fill="#fff"/>
        <text x="0" y="6" text-anchor="middle" class="sp-hi-text">Hi!</text>
      </g>
    </g>
  </svg>
  <button type="button" class="sp-skipbtn">Skip ›</button>
</div>
<script src="<?= h(url('assets/js/splash.js')) ?>?v=<?= @filemtime(__DIR__ . '/../assets/js/splash.js') ?>"></script>
