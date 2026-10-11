<?php
// Login splash (once per browser session): theme color fills the screen, "setl" appears and the "o" is the
// chameleon's tail. The mascot hides camouflaged in the background color, the word turns tail-yellow, then she
// shows herself with a receipt in her tail and waves hi with a penny. Timeline: assets/css/splash.css.
// Shown only while <html> has .splash-on (set before first paint by partials/head.php when $splash is set).
$tail = 'M385,665 383,652 379,639 373,628 366,617 358,608 349,600 338,593 327,588 316,585 304,583 293,583 282,585 271,588 261,592 252,598 243,605 236,613 231,622 226,631 223,641 222,650 222,660 223,670 225,679 229,687 234,695 239,702 246,708 253,712 260,716 268,719 276,720 284,720 292,719 299,717 306,714 312,710 318,706 322,701 326,695 329,689 331,683 332,677 332,671 331,665 329,659 327,654 324,650 321,646 317,643 313,640 308,638 304,637 299,636 295,636 291,637 287,639 284,640 281,643 278,645 277,648 275,651 274,654 274,656 274,659 275,662 275,664 277,666 278,668 280,669 281,670 283,670';
?>
<div id="splash" class="sp-overlay" role="presentation">
  <div class="sp-bg"></div>
  <svg class="sp-scene" viewBox="30 80 510 330" aria-hidden="true" focusable="false">
    <!-- The word: "setl" ends where the tail (the "o") begins -->
    <g class="sp-word">
      <text x="271" y="360" text-anchor="end" class="sp-text"><tspan class="sp-l" style="--i:0">s</tspan><tspan class="sp-l" style="--i:1">e</tspan><tspan class="sp-l" style="--i:2">t</tspan><tspan class="sp-l" style="--i:3">l</tspan></text>
    </g>

    <!-- The chameleon, drawn on a 960×960 canvas and scaled into place -->
    <g transform="translate(209.3 109.5) scale(.33)" stroke-linejoin="round" stroke-linecap="round">
      <!-- Receipt, tucked into the tail's curl -->
      <g transform="translate(282 650) rotate(-14)">
        <g class="sp-receipt">
          <path d="M-45,-185 -36,-200 -27,-185 -18,-200 -9,-185 0,-200 9,-185 18,-200 27,-185 36,-200 45,-185 45,0 -45,0Z" fill="#fff" stroke="#12303f" stroke-width="9"/>
          <g fill="#94a3b8"><rect x="-28" y="-160" width="56" height="9" rx="4"/><rect x="-28" y="-136" width="40" height="9" rx="4"/><rect x="-28" y="-112" width="50" height="9" rx="4"/><rect x="-28" y="-88" width="30" height="9" rx="4"/><rect x="2" y="-60" width="26" height="11" rx="4" fill="#64748b"/></g>
        </g>
      </g>

      <!-- Tail base (from the hip) -->
      <path class="sp-olu" d="M470,745 C415,770 385,725 385,665" fill="none" stroke="#12303f" stroke-width="58"/>
      <path class="sp-skin-s" d="M470,745 C415,770 385,725 385,665" fill="none" stroke-width="44"/>
      <!-- Tail spiral = the "o" -->
      <path class="sp-tail-ol" d="<?= $tail ?>" fill="none" stroke="#12303f" stroke-width="56"/>
      <path class="sp-tail" d="<?= $tail ?>" fill="none" stroke-width="44"/>
      <path class="sp-stripes" d="<?= $tail ?>" fill="none" stroke="#2aa79b" stroke-width="44" stroke-linecap="butt" pathLength="100" stroke-dasharray="6 6"/>

      <!-- Legs -->
      <path class="sp-skin sp-ln" stroke-width="9" d="M480,700 C470,760 452,800 425,818 L388,828 C360,836 362,862 392,862 L470,857 C492,852 502,832 507,802 L522,730Z"/>
      <path class="sp-skin sp-ln" stroke-width="9" d="M588,715 L598,800 C600,828 590,843 562,849 C538,855 540,878 570,879 L640,873 C666,869 668,840 651,820 C640,782 641,742 631,708Z"/>
      <!-- Torso + belly -->
      <path class="sp-skin sp-ln" stroke-width="9" d="M455,440 C418,520 418,640 445,720 C470,792 600,800 640,740 C672,690 642,560 602,460Z"/>
      <path class="sp-belly" d="M478,470 C458,560 462,660 486,718 C514,760 580,758 600,716 C618,660 600,545 572,470Z"/>
      <!-- Resting arm -->
      <path class="sp-olu" d="M478,505 C445,545 432,585 446,618" fill="none" stroke="#12303f" stroke-width="46"/>
      <path class="sp-skin-s" d="M478,505 C445,545 432,585 446,618" fill="none" stroke-width="32"/>
      <circle class="sp-skin sp-ln" stroke-width="8" cx="450" cy="626" r="23"/>

      <!-- Head -->
      <path class="sp-skin sp-ln" stroke-width="9" d="M300,300 C290,220 340,170 400,165 C430,120 480,95 540,100 C600,110 612,170 600,210 C670,240 700,300 690,360 C680,430 600,462 480,456 C380,450 310,400 300,300Z"/>
      <path class="sp-feat" d="M425,182 C470,150 545,160 592,212" fill="none" stroke="#12303f" stroke-width="8"/>
      <circle class="sp-feat" cx="418" cy="322" r="7" fill="#12303f"/>
      <path class="sp-feat" d="M352,362 C412,402 500,408 566,386" fill="none" stroke="#12303f" stroke-width="9"/>
      <!-- Eyes (visible even while camouflaged) -->
      <g class="sp-eye">
        <circle class="sp-ln" cx="345" cy="252" r="50" fill="#fff" stroke="#12303f" stroke-width="9"/>
        <g class="sp-pupil"><circle cx="360" cy="262" r="28" fill="#12303f"/><circle cx="370" cy="250" r="9" fill="#fff"/></g>
      </g>
      <g class="sp-eye">
        <circle class="sp-ln" cx="560" cy="305" r="76" fill="#fff" stroke="#12303f" stroke-width="9"/>
        <g class="sp-pupil"><circle cx="575" cy="316" r="42" fill="#12303f"/><circle cx="591" cy="298" r="13" fill="#fff"/></g>
      </g>

      <!-- Waving arm with the penny (rotates around the shoulder) -->
      <g transform="translate(600 505)">
        <g class="sp-arm">
          <path class="sp-olu" d="M0,0 C50,0 95,-35 108,-80" fill="none" stroke="#12303f" stroke-width="46"/>
          <path class="sp-skin-s" d="M0,0 C50,0 95,-35 108,-80" fill="none" stroke-width="32"/>
          <g transform="translate(160 -138)">
            <g class="sp-coin">
              <circle r="64" fill="#d98a2b" stroke="#12303f" stroke-width="9"/>
              <circle r="50" fill="#f2a94a"/>
              <path d="M-31,-17 A36,36 0 0 1 10,-40" fill="none" stroke="#fde3b0" stroke-width="10"/>
            </g>
          </g>
          <circle class="sp-skin sp-ln" stroke-width="8" cx="110" cy="-86" r="25"/>
        </g>
      </g>
    </g>

    <!-- "Hi!" -->
    <g transform="translate(474 126)">
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
