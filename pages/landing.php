<?php
// Public landing page: what Setlo is, how it works, what makes it different, guest mode, guidelines, popup tutorials,
// and ratings & feedback. Shown at the site root to signed-out visitors (index.php); signed-in users can open it at
// pages/landing to rate Setlo. The chameleon mascot (assets/mascot) walks new visitors through it.
require_once __DIR__ . '/../includes/bootstrap.php';

$user = current_user();
$title = 'Split bills, settle with proof';
$bodyClass = 'lp-body';
$headExtra = ['assets/css/landing.css', 'assets/js/landing.js'];
$home = $user ? ($user['role'] === 'admin' ? 'pages/admin-dashboard' : 'pages/dashboard') : 'pages/login';
require __DIR__ . '/../partials/head.php';

$m = fn (string $pose) => h(url('assets/mascot/' . $pose . '.png'));
$guestHref = h(url('pages/try'));
$registerHref = h(url('pages/register'));
$loginHref = h(url('pages/login'));

$strip = [['scan', 'Scan receipts'], ['pointer', 'Split by item'], ['shield', 'Verified payments'], ['user', 'Works as a guest'], ['qr', 'GCash · Maya · QR'], ['moon', 'Dark mode']];
$pillars = [
    ['receipt', 'receipt', 'Scan it', 'Take a photo of the receipt. Setlo reads the items and prices, and flags anything it is unsure about.'],
    ['tap', 'pointer', 'Tap what you had', 'Everyone taps their own dishes. Shared plates, tax, service charge and discounts are split fairly.'],
    ['thumbs', 'shield', 'Settle with proof', 'Pay by GCash, Maya or cash. A payment only counts when the receiver confirms it.'],
];
$steps = [
    ['users', 'Create a bill', 'Name it and invite the people you ate with.', 'tour'],
    ['scan', 'Scan the receipt', 'Take a photo, then review the items.', 'scan'],
    ['pointer', 'Assign items', 'Everyone taps what they had.', 'tour'],
    ['wallet', 'Settle up', 'Pay, mark as paid, confirm.', 'settle'],
];
$features = [
    ['scan', 'Scan the receipt', 'Snap a photo and Setlo reads the items and prices. Check and fix anything it misread.'],
    ['pointer', 'Split by item', 'Everyone taps what they ordered. Shared dishes are split equally between the people who shared them.'],
    ['shield', 'Verified settlement', 'A payment only counts once the sender marks it paid and the receiver confirms it, with a timestamped trail.'],
    ['link', 'Invite links & guests', 'Share a link to join a bill. Friends without an account can still be added as guests, and open their own link to see their share. If they sign up later, their bill comes with them.'],
    ['ticket', 'Senior / PWD discounts', 'Apply the discount to the right person only, and the rest of the bill adjusts fairly.'],
    ['coins', 'Whole-peso rounding', 'Round the total to whole pesos and note who actually received the change.'],
    ['globe', 'Multi-currency', 'Travelling? Split in another currency with a recorded exchange rate.'],
    ['users', 'Groups', 'Save your barkada, dorm or team once and start a bill with everyone in one tap.'],
    ['undo', 'Utang tracker', 'Keep track of change you hold for others and what is owed to you.'],
    ['qr', 'Pay-me QR', 'Add your GCash, Maya or bank QR so friends can pay you in seconds.'],
    ['award', 'Achievements & Fun Mode', 'Earn badges for good habits and play quick games to decide who pays.'],
    ['moon', 'Dark mode & app install', 'Pick your accent colour, switch to dark mode, and install Setlo on your phone.'],
];
$guides = [
    ['Be honest about what you ordered', 'Only tap the items you actually had. Fair splits keep friendships (and the app) running smoothly. If you’re unsure about a shared dish, ask before assigning it.'],
    ['Scan clear receipts', 'Use good light, fill the frame and keep the receipt flat. Always compare the scanned total with the paper receipt on the review step.'],
    ['Confirm a payment only when it’s received', 'The receiver should check their GCash, Maya or bank first. Confirming marks the payment as settled for both people, so only confirm once the money is in.'],
    ['What to do if something looks wrong', 'If a payment isn’t what you expected, use <b>Dispute</b> and give a short reason. The app manager can message both sides, but Setlo never moves money or decides for you, so talk it through.'],
    ['Guests and privacy', 'Guests are added by name and get a private link that shows only their own share. Your public reviews show only your first name and last initial, and your payment QR is shared only with people on your bills.'],
    ['Keep your account safe', 'Use a strong password, don’t share it, and sign out on shared devices. Setlo staff will never ask for your password.'],
];
?>
<div id="app" class="lp" v-cloak>
  <header class="lp-top">
    <div class="lp-wrap">
      <a href="<?= h(url('')) ?>" class="lp-brand" aria-label="Setlo home"><img src="<?= h(url('assets/setlo_logo.png')) ?>" alt="" /> setlo</a>
      <nav class="lp-nav" aria-label="Sections">
        <a href="#why">Why Setlo</a><a href="#how">How it works</a><a href="#features">Features</a><a href="#guest">Guest mode</a><a href="#reviews">Reviews</a>
      </nav>
      <div class="lp-actions">
<?php if ($user): ?>
        <a class="lp-btn lp-btn-primary lp-btn-sm" href="<?= h(url($home)) ?>">Open Setlo</a>
<?php else: ?>
        <a class="lp-btn lp-btn-ghost lp-btn-sm lp-hide-sm" href="<?= $loginHref ?>">Log in</a>
        <a class="lp-btn lp-btn-primary lp-btn-sm" href="<?= $registerHref ?>">Sign up</a>
<?php endif; ?>
        <button class="lp-burger" type="button" @click="menu = !menu" :aria-expanded="menu ? 'true' : 'false'" aria-label="Menu">
          <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M4 7h16M4 12h16M4 17h16"/></svg>
        </button>
      </div>
    </div>
    <div v-if="menu" class="lp-drawer" @click="menu = false">
      <a href="#why">Why Setlo</a><a href="#how">How it works</a><a href="#features">Features</a><a href="#guest">Guest mode</a><a href="#guidelines">Guidelines</a><a href="#reviews">Reviews</a>
<?php if (!$user): ?><a href="<?= $loginHref ?>">Log in</a><?php endif; ?>
    </div>
  </header>

  <main>
    <!-- Hero -->
    <section class="lp-hero">
      <div class="lp-wrap">
        <div class="lp-hero-copy">
          <span class="lp-pill"><span v-html="icon('receipt', 15)"></span> Receipt-scanned, itemized splitting</span>
          <h1>Split the bill. <em>Settle with proof.</em></h1>
          <p class="lp-lead">Scan the receipt, tap what you ordered, and pay each other back with a clear record. No more “sino may utang kanino?” after every meal.</p>
          <div class="lp-cta">
<?php if ($user): ?>
            <a class="lp-btn lp-btn-primary" href="<?= h(url($home)) ?>">Open Setlo</a>
<?php else: ?>
            <a class="lp-btn lp-btn-primary" href="<?= $registerHref ?>">Get started, it’s free <span v-html="icon('arrow', 18)"></span></a>
            <a class="lp-btn lp-btn-ghost" href="<?= $guestHref ?>"><span v-html="icon('user', 18)"></span> Sign in as guest</a>
<?php endif; ?>
            <button class="lp-btn lp-btn-ghost" type="button" @click="tour('tour')"><span v-html="icon('play', 16)"></span> Take the 60-second tour</button>
          </div>
          <ul class="lp-ticks">
            <li><span v-html="icon('check', 15)"></span> Free to use</li>
            <li><span v-html="icon('check', 15)"></span> Try it with no account</li>
            <li><span v-html="icon('check', 15)"></span> Works on any phone</li>
          </ul>
          <div class="lp-proof" v-if="r.count">
            <span class="lp-stars" aria-hidden="true">{{ stars(Math.round(r.average)) }}</span>
            <span><b>{{ r.average }}</b> from {{ r.count }} {{ r.count === 1 ? 'review' : 'reviews' }}</span>
          </div>
        </div>
        <div class="lp-mock" aria-hidden="true">
          <img class="lp-hero-mascot" src="<?= $m('courier') ?>" alt="" />
          <div class="lp-bill">
            <div class="lp-bill-head"><b>Samgyup night</b><span class="lp-chip">Settling</span></div>
            <div class="lp-row"><div>Pork belly set<div class="lp-who"><span class="lp-av" style="--d:.6s;background:#14b8a6">A</span><span class="lp-av" style="--d:.8s;background:#6366f1">B</span><span class="lp-av" style="--d:1s;background:#f59e0b">C</span></div></div><span>₱899.00</span></div>
            <div class="lp-row"><div>Iced tea ×2<div class="lp-who"><span class="lp-av" style="--d:1.3s;background:#14b8a6">A</span><span class="lp-av" style="--d:1.5s;background:#f59e0b">C</span></div></div><span>₱160.00</span></div>
            <div class="lp-row"><div>Cheese ramyun<div class="lp-who"><span class="lp-av" style="--d:1.8s;background:#6366f1">B</span></div></div><span>₱185.00</span></div>
            <div class="lp-total"><span>Your share</span><span data-count="421.33">₱421.33</span></div>
          </div>
          <div class="lp-float a"><span class="lp-dot"></span> Ben confirmed ₱421.33</div>
          <div class="lp-float b"><span v-html="icon('camera', 16)"></span> Receipt scanned</div>
          <div class="lp-float c"><span v-html="icon('link', 16)"></span> Guest link sent</div>
        </div>
      </div>
    </section>

    <!-- What it can do, at a glance -->
    <div class="lp-strip" aria-label="Highlights">
      <div class="lp-wrap">
<?php foreach ($strip as [$icon, $label]): ?>
        <span class="lp-spill"><span v-html="icon('<?= h($icon) ?>', 17)"></span><?= h($label) ?></span>
<?php endforeach; ?>
      </div>
    </div>

    <!-- Meet Setlo: the whole idea for someone new -->
    <section id="why" class="lp-sec">
      <div class="lp-wrap">
        <div class="lp-head center">
          <p class="lp-eyebrow">New to Setlo?</p>
          <h2 class="lp-h2">Meet your Setlo buddy</h2>
          <p class="lp-sub">Splitting a meal shouldn’t end with a calculator, three screenshots and an awkward group chat. Setlo does the maths fairly and keeps the record, so everyone just pays what they ate.</p>
        </div>
        <div class="lp-meet">
          <div class="lp-meet-art lp-reveal">
            <div class="lp-bubble">Hi, I’m your Setlo buddy! The whole idea fits in <b>three moves</b>.</div>
            <img src="<?= $m('wave') ?>" alt="" />
          </div>
          <div class="lp-pillars">
<?php foreach ($pillars as $n => [$pose, $icon, $name, $text]): ?>
            <article class="lp-pillar lp-reveal" style="--i:<?= $n ?>">
              <img src="<?= $m($pose) ?>" alt="" loading="lazy" />
              <span class="lp-pnum"><?= $n + 1 ?></span>
              <h3><?= h($name) ?></h3>
              <p><?= h($text) ?></p>
            </article>
<?php endforeach; ?>
          </div>
        </div>
      </div>
    </section>

    <!-- How it works -->
    <section id="how" class="lp-sec alt">
      <div class="lp-wrap">
        <div class="lp-head center">
          <p class="lp-eyebrow">How it works</p>
          <h2 class="lp-h2">From receipt to settled in four steps</h2>
          <p class="lp-sub">Tap “See how” on any step for a short walkthrough.</p>
        </div>
        <div class="lp-steps">
<?php foreach ($steps as $n => [$icon, $name, $text, $tour]): ?>
          <div class="lp-step lp-reveal" style="--i:<?= $n ?>"><span class="lp-stepico" v-html="icon('<?= h($icon) ?>', 24)"></span><span class="lp-num"><?= $n + 1 ?></span><h3><?= h($name) ?></h3><p><?= h($text) ?></p><button type="button" class="lp-link" @click="tour('<?= $tour ?>')">See how <span v-html="icon('arrow', 15)"></span></button></div>
<?php endforeach; ?>
        </div>
      </div>
    </section>

    <!-- What makes Setlo different -->
    <section id="features" class="lp-sec">
      <div class="lp-wrap">
        <div class="lp-head center">
          <p class="lp-eyebrow">What makes Setlo different</p>
          <h2 class="lp-h2">Built for how Filipinos actually eat out</h2>
          <p class="lp-sub">Meal sets, VAT-inclusive prices, Senior and PWD discounts, GCash and Maya: the real details of a shared meal, handled for you.</p>
        </div>
        <div class="lp-bento">
          <article class="lp-tile t7 lp-reveal">
            <div class="lp-tile-body">
              <span class="lp-ico" v-html="icon('scan', 22)"></span>
              <h3>Reads real Philippine receipts</h3>
              <p>Snap a photo and Setlo reads the items and prices, even meal sets and their contents. Anything it isn’t sure about is flagged so you can check it before you split.</p>
              <div class="lp-mini">
                <div class="lp-mini-row"><span>Chicken Inasal Set</span><b>₱189.00</b></div>
                <div class="lp-mini-row warn"><span>Hlo-Hlo <i>did you mean “Halo-halo”?</i></span><b>₱120.00</b></div>
                <div class="lp-mini-row"><span>Iced Tea Pitcher</span><b>₱150.00</b></div>
              </div>
            </div>
            <img class="lp-tile-mascot" src="<?= $m('read') ?>" alt="" loading="lazy" />
          </article>

          <article class="lp-tile t5 lp-reveal" style="--i:1">
            <div class="lp-tile-body">
              <span class="lp-ico" v-html="icon('shield', 22)"></span>
              <h3>Verified, two-sided settlement</h3>
              <p>A payment is only settled when the sender marks it paid and the receiver confirms it, with a timestamped trail for both.</p>
              <ol class="lp-trail">
                <li><i class="done" v-html="icon('check', 13)"></i><span>Ben marked ₱421.33 as paid<small>7:41 PM</small></span></li>
                <li><i class="done" v-html="icon('check', 13)"></i><span>Ana confirmed receiving it<small>7:42 PM</small></span></li>
                <li><i class="now"></i><span>Bill closed<small>everyone is settled</small></span></li>
              </ol>
            </div>
            <img class="lp-tile-mascot sm" src="<?= $m('bell') ?>" alt="" loading="lazy" />
          </article>

          <article class="lp-tile t5 lp-reveal">
            <div class="lp-tile-body">
              <span class="lp-ico" v-html="icon('link', 22)"></span>
              <h3>Guests included, accounts optional</h3>
              <p>Friends without Setlo get a private link that shows only their own share. If they sign up later, their bill and payments come with them.</p>
              <div class="lp-linkcard">
                <span class="lp-linkchip"><span v-html="icon('lock', 13)"></span> Private link · only your share</span>
                <div class="lp-linkrow"><span class="lp-av" style="background:#f472b6">G</span><div><b>Hi Gabby!</b><small>You owe ₱233.34 to Carlo</small></div><span v-html="icon('arrow', 18)"></span></div>
              </div>
            </div>
          </article>

          <article class="lp-tile t7 lp-reveal" style="--i:1">
            <div class="lp-tile-body">
              <span class="lp-ico" v-html="icon('calc', 22)"></span>
              <h3>Fair by design</h3>
              <p>The little things that usually start arguments, handled the same way every time.</p>
              <ul class="lp-chips">
                <li><span v-html="icon('ticket', 15)"></span> Senior and PWD discounts to the right person</li>
                <li><span v-html="icon('percent', 15)"></span> VAT-inclusive prices detected</li>
                <li><span v-html="icon('coins', 15)"></span> Whole-peso rounding and change tracking</li>
                <li><span v-html="icon('globe', 15)"></span> Other currencies with a recorded rate</li>
                <li><span v-html="icon('users', 15)"></span> Shared dishes split between sharers</li>
              </ul>
            </div>
          </article>

          <article class="lp-tile t4 lp-reveal">
            <div class="lp-tile-body">
              <span class="lp-ico" v-html="icon('qr', 22)"></span>
              <h3>Pay in seconds</h3>
              <p>Add your GCash, Maya or bank details and a personal Pay-me QR. Install Setlo on your phone like an app.</p>
            </div>
            <img class="lp-tile-mascot sm" src="<?= $m('phone') ?>" alt="" loading="lazy" />
          </article>

          <article class="lp-tile t4 lp-reveal" style="--i:1">
            <div class="lp-tile-body">
              <span class="lp-ico" v-html="icon('folder', 22)"></span>
              <h3>Groups and history</h3>
              <p>Save your barkada once, start a bill in one tap, and look back at every past bill and utang.</p>
            </div>
            <img class="lp-tile-mascot sm" src="<?= $m('folders') ?>" alt="" loading="lazy" />
          </article>

          <article class="lp-tile t4 lp-reveal" style="--i:2">
            <div class="lp-tile-body">
              <span class="lp-ico" v-html="icon('gamepad', 22)"></span>
              <h3>Make it fun</h3>
              <p>Quick Fun Mode games can decide who pays, and badges reward good habits like settling on time.</p>
              <div class="lp-badges"><span v-html="icon('award', 22)"></span><span v-html="icon('trophy', 22)"></span><span v-html="icon('star', 22)"></span></div>
            </div>
          </article>
        </div>
      </div>
    </section>

    <!-- Without / with Setlo -->
    <section class="lp-sec alt">
      <div class="lp-wrap">
        <div class="lp-head center">
          <p class="lp-eyebrow">The difference</p>
          <h2 class="lp-h2">Before Setlo, and after</h2>
        </div>
        <div class="lp-versus">
          <div class="lp-vs old lp-reveal">
            <h3>Without Setlo</h3>
            <ul>
              <li><span v-html="icon('x', 16)"></span>Calculator maths and screenshots in the group chat</li>
              <li><span v-html="icon('x', 16)"></span>“Sino may utang kanino?” a week later</li>
              <li><span v-html="icon('x', 16)"></span>Awkward reminders, and nobody sure who paid</li>
              <li><span v-html="icon('x', 16)"></span>One person quietly covering the difference</li>
            </ul>
          </div>
          <div class="lp-vs-arrow" aria-hidden="true" v-html="icon('arrow', 26)"></div>
          <div class="lp-vs new lp-reveal" style="--i:1">
            <h3>With Setlo</h3>
            <ul>
              <li><span v-html="icon('check', 16)"></span>Scan once, everyone taps what they had</li>
              <li><span v-html="icon('check', 16)"></span>Each person sees their exact share, and why</li>
              <li><span v-html="icon('check', 16)"></span>Payments confirmed by the person who got them</li>
              <li><span v-html="icon('check', 16)"></span>A clear record of every bill and settlement</li>
            </ul>
          </div>
        </div>
      </div>
    </section>

    <!-- Guest mode -->
    <section id="guest" class="lp-sec">
      <div class="lp-wrap">
        <div class="lp-guest lp-reveal">
          <img class="lp-guest-mascot" src="<?= $m('think') ?>" alt="" loading="lazy" />
          <div class="lp-guest-copy">
            <p class="lp-eyebrow">No account? No problem</p>
            <h2 class="lp-h2">Not ready to sign up? Sign in as guest</h2>
            <ul class="lp-gbul">
              <li><span v-html="icon('calc', 18)"></span><div><b>Try the calculator</b> with a sample receipt. Nothing is saved, so closing the tab clears it.</div></li>
              <li><span v-html="icon('link', 18)"></span><div><b>Added to a friend’s bill?</b> Open your private link and see your exact share, with no account.</div></li>
              <li><span v-html="icon('folder', 18)"></span><div><b>Register later</b> and your bills and payments come with you.</div></li>
            </ul>
            <div class="lp-cta">
<?php if (!$user): ?>
              <a class="lp-btn lp-btn-primary" href="<?= $guestHref ?>"><span v-html="icon('user', 18)"></span> Sign in as guest</a>
              <a class="lp-btn lp-btn-ghost" href="<?= $registerHref ?>">Create an account</a>
<?php else: ?>
              <a class="lp-btn lp-btn-primary" href="<?= $guestHref ?>"><span v-html="icon('calc', 18)"></span> Open the calculator</a>
<?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- Everything else -->
    <section id="everything" class="lp-sec alt">
      <div class="lp-wrap">
        <div class="lp-head center">
          <p class="lp-eyebrow">Everything in Setlo</p>
          <h2 class="lp-h2">Everything a shared meal needs</h2>
          <p class="lp-sub">Setlo is built for friends, barkadas, dorms and teams who split meals and want the money part to be fair, quick and written down.</p>
        </div>
        <div class="lp-grid">
<?php foreach ($features as $n => [$icon, $name, $text]): ?>
          <article class="lp-card lp-reveal" style="--i:<?= $n % 3 ?>"><span class="lp-ico" aria-hidden="true" v-html="icon('<?= h($icon) ?>', 22)"></span><h3><?= h($name) ?></h3><p><?= h($text) ?></p></article>
<?php endforeach; ?>
        </div>
      </div>
    </section>

    <!-- Guidelines -->
    <section id="guidelines" class="lp-sec">
      <div class="lp-wrap">
        <div class="lp-head center">
          <p class="lp-eyebrow">Guidelines</p>
          <h2 class="lp-h2">Good habits for happy splits</h2>
          <p class="lp-sub">A few simple rules that keep everyone paid, and everyone friends.</p>
        </div>
        <div class="lp-guides">
<?php foreach ($guides as $n => [$q, $a]): ?>
          <details class="lp-guide"<?= $n === 0 ? ' open' : '' ?>><summary><span class="tick" aria-hidden="true" v-html="icon('check', 16)"></span><?= h($q) ?></summary><div class="body"><?= $a ?></div></details>
<?php endforeach; ?>
        </div>
        <div class="lp-tours">
          <button type="button" class="lp-btn lp-btn-ghost lp-btn-sm" @click="tour('tour')"><span v-html="icon('play', 15)"></span> Quick tour</button>
          <button type="button" class="lp-btn lp-btn-ghost lp-btn-sm" @click="tour('scan')"><span v-html="icon('camera', 16)"></span> Scanning tips</button>
          <button type="button" class="lp-btn lp-btn-ghost lp-btn-sm" @click="tour('settle')"><span v-html="icon('wallet', 16)"></span> Settling up</button>
        </div>
      </div>
    </section>

    <!-- Ratings & feedback -->
    <section id="reviews" class="lp-sec alt">
      <div class="lp-wrap">
        <div class="lp-head center">
          <p class="lp-eyebrow">Ratings &amp; feedback</p>
          <h2 class="lp-h2">What people say about Setlo</h2>
        </div>
        <div class="lp-rev">
          <div class="lp-card lp-score">
            <div v-if="r.count">
              <div class="big">{{ r.average }}</div>
              <div class="lp-stars" style="font-size:1.3rem" aria-hidden="true">{{ stars(Math.round(r.average)) }}</div>
              <p class="lp-sub" style="margin-top:.3rem">{{ r.count }} {{ r.count === 1 ? 'rating' : 'ratings' }}</p>
              <div class="lp-bars">
                <div v-for="n in [5, 4, 3, 2, 1]" :key="n" class="lp-bar"><span>{{ n }}★</span><i><b :style="{ width: pct(n) + '%' }"></b></i><span>{{ r.distribution[n] }}</span></div>
              </div>
            </div>
            <p v-else class="lp-sub" style="margin:0">No ratings yet. Be the first!</p>
          </div>
          <div>
            <div v-if="r.reviews.length" class="lp-reviews">
              <article v-for="(v, i) in r.reviews" :key="i" class="lp-card lp-review">
                <div class="lp-stars" aria-hidden="true">{{ stars(v.rating) }}<span class="off">{{ stars(5 - v.rating, true) }}</span></div>
                <p>{{ v.comment }}</p>
                <small>{{ v.name }} · {{ timeAgo(v.created_at) }}</small>
              </article>
            </div>
            <div v-else class="lp-empty">Reviews will show up here.</div>

            <div class="lp-card lp-form">
              <h3>{{ r.mine ? 'Your review' : 'Rate Setlo' }}</h3>
              <form @submit.prevent="send" novalidate>
                <p class="lp-sub" style="margin:.3rem 0 0">{{ r.mine ? 'Change your stars or comment any time.' : 'Tap a star to rate Setlo. No account needed.' }}</p>
                <div class="lp-starpick" role="radiogroup" aria-label="Your rating" @mouseleave="hover = 0">
                  <button v-for="n in 5" :key="n" type="button" role="radio" :aria-checked="form.rating === n ? 'true' : 'false'" :aria-label="n + (n === 1 ? ' star' : ' stars')" :class="{ on: n <= (hover || form.rating) }" @mouseenter="hover = n" @click="pickStar(n)">★</button>
                </div>
                <input v-if="!r.signed_in" class="lp-text" style="min-height:0;margin-bottom:.6rem" v-model="form.name" maxlength="40" placeholder="Your name (optional)" aria-label="Your name" />
                <textarea class="lp-text" v-model="form.comment" maxlength="500" placeholder="What do you like? What could be better? (optional)" aria-label="Your feedback"></textarea>
                <div class="lp-meta"><span>{{ form.comment.length }}/500</span><button class="lp-btn lp-btn-primary lp-btn-sm" :disabled="busy || !form.rating">{{ busy ? 'Saving…' : (r.mine ? 'Update review' : 'Submit review') }}</button></div>
                <p v-if="err" class="lp-err" role="alert">{{ err }}</p>
                <p v-if="r.mine && r.mine.status === 'approved'" class="lp-note">Your review is live. Thank you for helping others find Setlo! <a v-if="r.signed_in" href="<?= h(url('pages/reviews')) ?>" style="font-weight:800;text-decoration:underline">See all ratings &amp; reviews</a></p>
                <p v-else-if="r.mine && r.mine.status === 'hidden'" class="lp-note">Your comment isn’t shown publicly, but your rating still counts toward our feedback.</p>
              </form>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- Final call to action -->
    <section class="lp-sec" style="padding-top:48px">
      <div class="lp-wrap">
        <div class="lp-final">
          <img class="lp-final-mascot a" src="<?= $m('celebrate') ?>" alt="" loading="lazy" />
          <img class="lp-final-mascot b" src="<?= $m('cheer') ?>" alt="" loading="lazy" />
          <h2>Ready for stress-free splits?</h2>
          <p>Create your free account and split your next meal in minutes. Or take it for a spin first, no account needed.</p>
          <div class="lp-cta">
<?php if ($user): ?>
            <a class="lp-btn lp-btn-primary" href="<?= h(url($home)) ?>">Open Setlo</a>
<?php else: ?>
            <a class="lp-btn lp-btn-primary" href="<?= $registerHref ?>">Sign up free</a>
            <a class="lp-btn lp-btn-ghost" href="<?= $guestHref ?>"><span v-html="icon('user', 18)"></span> Sign in as guest</a>
            <a class="lp-btn lp-btn-ghost" href="<?= $loginHref ?>">Log in</a>
<?php endif; ?>
          </div>
        </div>
      </div>
    </section>
  </main>

  <footer class="lp-foot">
    <div class="lp-wrap">
      <span>© <?= date('Y') ?> Setlo. Split. Track. Stay Together.</span>
      <nav aria-label="Footer"><a href="#features">Features</a><a href="#guest">Guest mode</a><a href="#guidelines">Guidelines</a><a href="#reviews">Reviews</a><a href="<?= h(url('pages/admin-login')) ?>">Admin Panel</a></nav>
    </div>
  </footer>

  <!-- First-visit nudge: small and dismissible, never a blocking pop-up -->
  <div v-if="nudge" class="lp-nudge" role="dialog" aria-label="Take the tour">
    <img src="<?= $m('wave') ?>" alt="" />
    <div><b>New here?</b><span>See how Setlo works in 60 seconds.</span></div>
    <button class="lp-btn lp-btn-primary lp-btn-sm" type="button" @click="tour('tour')">Start</button>
    <button class="lp-x" type="button" @click="dismissNudge" aria-label="Dismiss" v-html="icon('x', 16)"></button>
  </div>
</div>

<script>
Setlo.mount({
  data: () => ({
    menu: false, nudge: false, busy: false, err: '', hover: 0,
    r: { average: 0, count: 0, distribution: { 1: 0, 2: 0, 3: 0, 4: 0, 5: 0 }, reviews: [], signed_in: false, mine: null },
    form: { rating: 0, comment: '', name: '' },
  }),
  async mounted() {
    window.SetloLanding.init(document.getElementById("app"));
    try { await this.load(); } catch (e) { /* the page works without reviews */ }
    if (!window.SetloTour.nudgeSeen()) setTimeout(() => { this.nudge = true; }, 4000);
  },
  methods: {
    icon: SetloIcons.svg,
    async load() {
      const r = await api.get('feedback.php');
      this.r = r;
      if (r.mine) this.form = { rating: r.mine.rating, comment: r.mine.comment || '', name: r.mine.name || this.form.name };
    },
    /** A star was tapped: select it and move on to the optional comment. */
    pickStar(n) {
      this.form.rating = n;
      this.$nextTick(() => { const box = document.querySelector('.lp-form textarea'); if (box && !this.form.comment) box.focus({ preventScroll: true }); });
    },
    stars(n, empty) { return (empty ? '☆' : '★').repeat(Math.max(0, n)); },
    pct(n) { return this.r.count ? Math.round((this.r.distribution[n] / this.r.count) * 100) : 0; },
    tour(id) { this.nudge = false; window.SetloTour.open(id); },
    dismissNudge() { this.nudge = false; window.SetloTour.markNudgeSeen(); },
    async send() {
      if (!this.form.rating) { this.err = 'Tap a star to rate Setlo.'; return; }
      this.err = '';
      this.busy = true;
      try {
        const res = await api.post('feedback.php', { rating: this.form.rating, comment: this.form.comment.trim(), name: (this.form.name || '').trim() });
        this.r.mine = res.mine;
        Setlo.toast('Thanks for your feedback!', 'ok');
        await this.load();
      } catch (e) {
        this.err = e.message || 'Could not save your review.';
      } finally {
        this.busy = false;
      }
    },
  },
});
</script>
<?php require __DIR__ . '/../partials/foot.php'; ?>
