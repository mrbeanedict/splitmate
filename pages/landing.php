<?php
// Public landing page: what Setlo is, how it works, guidelines, popup tutorials, and ratings & feedback.
// Shown at the site root to signed-out visitors (index.php); signed-in users can open it at pages/landing to rate Setlo.
require_once __DIR__ . '/../includes/bootstrap.php';

$user = current_user();
$title = 'Split bills, settle with proof';
$bodyClass = 'lp-body';
$headExtra = ['assets/css/landing.css', 'assets/js/landing.js'];
$home = $user ? ($user['role'] === 'admin' ? 'pages/admin-dashboard' : 'pages/dashboard') : 'pages/login';
require __DIR__ . '/../partials/head.php';

$features = [
    ['📸', 'Scan the receipt', 'Snap a photo and Setlo reads the items and prices. Check and fix anything it misread.'],
    ['👆', 'Split by item', 'Everyone taps what they ordered. Shared dishes are split equally between the people who shared them.'],
    ['✅', 'Verified settlement', 'A payment only counts once the sender marks it paid and the receiver confirms it, with a timestamped trail.'],
    ['🔗', 'Invite links & guests', 'Share a link to join a bill. Friends without an account can still be added as guests.'],
    ['🎫', 'Senior / PWD discounts', 'Apply the discount to the right person only, and the rest of the bill adjusts fairly.'],
    ['🪙', 'Whole-peso rounding', 'Round the total to whole pesos and note who actually received the change.'],
    ['💱', 'Multi-currency', 'Travelling? Split in another currency with a recorded exchange rate.'],
    ['👥', 'Groups', 'Save your barkada, dorm or team once and start a bill with everyone in one tap.'],
    ['↩️', 'Utang tracker', 'Keep track of change you hold for others and what is owed to you.'],
    ['📱', 'Pay-me QR', 'Add your GCash, Maya or bank QR so friends can pay you in seconds.'],
    ['🏅', 'Achievements & Fun Mode', 'Earn badges for good habits and play quick games to decide who pays.'],
    ['🌙', 'Dark mode & app install', 'Pick your accent colour, switch to dark mode, and install Setlo on your phone.'],
];
$steps = [
    ['Create a bill', 'Name it and invite the people you ate with.', 'tour'],
    ['Scan the receipt', 'Take a photo, then review the items.', 'scan'],
    ['Assign items', 'Everyone taps what they had.', 'tour'],
    ['Settle up', 'Pay, mark as paid, confirm.', 'settle'],
];
$guides = [
    ['Be honest about what you ordered', 'Only tap the items you actually had. Fair splits keep friendships (and the app) running smoothly. If you’re unsure about a shared dish, ask before assigning it.'],
    ['Scan clear receipts', 'Use good light, fill the frame and keep the receipt flat. Always compare the scanned total with the paper receipt on the review step.'],
    ['Confirm a payment only when it’s received', 'The receiver should check their GCash, Maya or bank first. Confirming marks the payment as settled for both people, so only confirm once the money is in.'],
    ['What to do if something looks wrong', 'If a payment isn’t what you expected, use <b>Dispute</b> and give a short reason. The app manager can message both sides, but Setlo never moves money or decides for you, so talk it through.'],
    ['Guests and privacy', 'Guests can join with just a name. Your public reviews show only your first name and last initial, and your payment QR is shared only with people on your bills.'],
    ['Keep your account safe', 'Use a strong password, don’t share it, and sign out on shared devices. Setlo staff will never ask for your password.'],
];
?>
<div id="app" class="lp" v-cloak>
  <header class="lp-top">
    <div class="lp-wrap">
      <a href="<?= h(url('')) ?>" class="lp-brand" aria-label="Setlo home"><img src="<?= h(url('assets/setlo_logo.png')) ?>" alt="" /> setlo</a>
      <nav class="lp-nav" aria-label="Sections">
        <a href="#features">Features</a><a href="#how">How it works</a><a href="#guidelines">Guidelines</a><a href="#reviews">Reviews</a>
      </nav>
      <div class="lp-actions">
<?php if ($user): ?>
        <a class="lp-btn lp-btn-primary lp-btn-sm" href="<?= h(url($home)) ?>">Open Setlo</a>
<?php else: ?>
        <a class="lp-btn lp-btn-ghost lp-btn-sm lp-hide-sm" href="<?= h(url('pages/login')) ?>">Log in</a>
        <a class="lp-btn lp-btn-primary lp-btn-sm" href="<?= h(url('pages/register')) ?>">Sign up</a>
<?php endif; ?>
        <button class="lp-burger" type="button" @click="menu = !menu" :aria-expanded="menu ? 'true' : 'false'" aria-label="Menu">
          <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M4 7h16M4 12h16M4 17h16"/></svg>
        </button>
      </div>
    </div>
    <div v-if="menu" class="lp-drawer" @click="menu = false">
      <a href="#features">Features</a><a href="#how">How it works</a><a href="#guidelines">Guidelines</a><a href="#reviews">Reviews</a>
<?php if (!$user): ?><a href="<?= h(url('pages/login')) ?>">Log in</a><?php endif; ?>
    </div>
  </header>

  <main>
    <!-- Hero -->
    <section class="lp-hero">
      <div class="lp-wrap">
        <div>
          <span class="lp-pill">🧾 Receipt-scanned, itemized splitting</span>
          <h1>Split the bill. <em>Settle with proof.</em></h1>
          <p class="lp-lead">Scan the receipt, tap what you ordered, and pay each other back with a clear record. No more “sino may utang kanino?” after every meal.</p>
          <div class="lp-cta">
<?php if ($user): ?>
            <a class="lp-btn lp-btn-primary" href="<?= h(url($home)) ?>">Open Setlo</a>
<?php else: ?>
            <a class="lp-btn lp-btn-primary" href="<?= h(url('pages/register')) ?>">Get started, it’s free</a>
<?php endif; ?>
            <button class="lp-btn lp-btn-ghost" type="button" @click="tour('tour')">▶ Take the 60-second tour</button>
          </div>
          <div class="lp-proof" v-if="r.count">
            <span class="lp-stars" aria-hidden="true">{{ stars(Math.round(r.average)) }}</span>
            <span><b>{{ r.average }}</b> from {{ r.count }} {{ r.count === 1 ? 'review' : 'reviews' }}</span>
          </div>
        </div>
        <div class="lp-mock" aria-hidden="true">
          <div class="lp-bill">
            <div class="lp-bill-head"><b>Samgyup night</b><span class="lp-chip">Settling</span></div>
            <div class="lp-row"><div>Pork belly set<div class="lp-who"><span class="lp-av" style="background:#14b8a6">A</span><span class="lp-av" style="background:#6366f1">B</span><span class="lp-av" style="background:#f59e0b">C</span></div></div><span>₱899.00</span></div>
            <div class="lp-row"><div>Iced tea ×2<div class="lp-who"><span class="lp-av" style="background:#14b8a6">A</span><span class="lp-av" style="background:#f59e0b">C</span></div></div><span>₱160.00</span></div>
            <div class="lp-row"><div>Cheese ramyun<div class="lp-who"><span class="lp-av" style="background:#6366f1">B</span></div></div><span>₱185.00</span></div>
            <div class="lp-total"><span>Your share</span><span>₱421.33</span></div>
          </div>
          <div class="lp-float a"><span class="lp-dot"></span> Ben confirmed ₱421.33</div>
          <div class="lp-float b">📸 Receipt scanned</div>
        </div>
      </div>
    </section>

    <!-- Features -->
    <section id="features" class="lp-sec alt">
      <div class="lp-wrap">
        <div class="lp-head center">
          <p class="lp-eyebrow">About Setlo</p>
          <h2 class="lp-h2">Everything a shared meal needs</h2>
          <p class="lp-sub">Setlo is built for friends, barkadas, dorms and teams who split meals and want the money part to be fair, quick and written down.</p>
        </div>
        <div class="lp-grid">
<?php foreach ($features as [$icon, $name, $text]): ?>
          <article class="lp-card"><span class="lp-ico" aria-hidden="true"><?= $icon ?></span><h3><?= h($name) ?></h3><p><?= h($text) ?></p></article>
<?php endforeach; ?>
        </div>
      </div>
    </section>

    <!-- How it works -->
    <section id="how" class="lp-sec">
      <div class="lp-wrap">
        <div class="lp-head center">
          <p class="lp-eyebrow">How it works</p>
          <h2 class="lp-h2">From receipt to settled in four steps</h2>
          <p class="lp-sub">Tap “See how” on any step for a short walkthrough.</p>
        </div>
        <div class="lp-steps">
<?php foreach ($steps as $n => [$name, $text, $tour]): ?>
          <div class="lp-step"><span class="lp-num"><?= $n + 1 ?></span><h3><?= h($name) ?></h3><p><?= h($text) ?></p><button type="button" class="lp-link" @click="tour('<?= $tour ?>')">See how →</button></div>
<?php endforeach; ?>
        </div>
      </div>
    </section>

    <!-- Guidelines -->
    <section id="guidelines" class="lp-sec alt">
      <div class="lp-wrap">
        <div class="lp-head center">
          <p class="lp-eyebrow">Guidelines</p>
          <h2 class="lp-h2">Good habits for happy splits</h2>
          <p class="lp-sub">A few simple rules that keep everyone paid, and everyone friends.</p>
        </div>
        <div class="lp-guides">
<?php foreach ($guides as $n => [$q, $a]): ?>
          <details class="lp-guide"<?= $n === 0 ? ' open' : '' ?>><summary><span class="tick" aria-hidden="true">✔️</span><?= h($q) ?></summary><div class="body"><?= $a ?></div></details>
<?php endforeach; ?>
        </div>
        <div class="lp-tours">
          <button type="button" class="lp-btn lp-btn-ghost lp-btn-sm" @click="tour('tour')">▶ Quick tour</button>
          <button type="button" class="lp-btn lp-btn-ghost lp-btn-sm" @click="tour('scan')">📸 Scanning tips</button>
          <button type="button" class="lp-btn lp-btn-ghost lp-btn-sm" @click="tour('settle')">💸 Settling up</button>
        </div>
      </div>
    </section>

    <!-- Ratings & feedback -->
    <section id="reviews" class="lp-sec">
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
            <div v-else class="lp-empty">Approved reviews will show up here.</div>

            <div class="lp-card lp-form">
              <h3>{{ r.mine ? 'Your review' : 'Rate Setlo' }}</h3>
              <div v-if="!r.signed_in">
                <p class="lp-sub" style="margin-top:.4rem">Log in to leave a rating and feedback. It takes less than a minute.</p>
                <div class="lp-cta"><a class="lp-btn lp-btn-primary lp-btn-sm" href="<?= h(url('pages/login')) ?>">Log in</a><a class="lp-btn lp-btn-ghost lp-btn-sm" href="<?= h(url('pages/register')) ?>">Create an account</a></div>
              </div>
              <form v-else @submit.prevent="send" novalidate>
                <div class="lp-starpick" role="radiogroup" aria-label="Your rating" @mouseleave="hover = 0">
                  <button v-for="n in 5" :key="n" type="button" role="radio" :aria-checked="form.rating === n ? 'true' : 'false'" :aria-label="n + (n === 1 ? ' star' : ' stars')" :class="{ on: n <= (hover || form.rating) }" @mouseenter="hover = n" @click="form.rating = n">★</button>
                </div>
                <textarea class="lp-text" v-model="form.comment" maxlength="500" placeholder="What do you like? What could be better? (optional)" aria-label="Your feedback"></textarea>
                <div class="lp-meta"><span>{{ form.comment.length }}/500</span><button class="lp-btn lp-btn-primary lp-btn-sm" :disabled="busy || !form.rating">{{ busy ? 'Saving…' : (r.mine ? 'Update review' : 'Submit review') }}</button></div>
                <p v-if="err" class="lp-err" role="alert">{{ err }}</p>
                <p v-if="r.mine && r.mine.status === 'pending' && r.mine.comment" class="lp-note">Thanks! Your comment is waiting for approval before it appears publicly.</p>
                <p v-else-if="r.mine && r.mine.status === 'approved'" class="lp-note">Your review is live. Thank you for helping others find Setlo!</p>
                <p v-else-if="r.mine && r.mine.status === 'hidden'" class="lp-note">Your comment isn’t shown publicly, but your rating still counts toward our feedback.</p>
              </form>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- Final call to action -->
    <section class="lp-sec" style="padding-top:0">
      <div class="lp-wrap">
        <div class="lp-final">
          <h2>Ready for stress-free splits?</h2>
          <p>Create your free account and split your next meal in minutes.</p>
          <div class="lp-cta">
<?php if ($user): ?>
            <a class="lp-btn lp-btn-primary" href="<?= h(url($home)) ?>">Open Setlo</a>
<?php else: ?>
            <a class="lp-btn lp-btn-primary" href="<?= h(url('pages/register')) ?>">Sign up free</a>
            <a class="lp-btn lp-btn-ghost" href="<?= h(url('pages/login')) ?>">Log in</a>
<?php endif; ?>
          </div>
        </div>
      </div>
    </section>
  </main>

  <footer class="lp-foot">
    <div class="lp-wrap">
      <span>© <?= date('Y') ?> Setlo. Split. Track. Stay Together.</span>
      <nav aria-label="Footer"><a href="#features">Features</a><a href="#guidelines">Guidelines</a><a href="#reviews">Reviews</a><a href="<?= h(url('pages/admin-login')) ?>">Admin Panel</a></nav>
    </div>
  </footer>

  <!-- First-visit nudge: small and dismissible, never a blocking pop-up -->
  <div v-if="nudge" class="lp-nudge" role="dialog" aria-label="Take the tour">
    <div><b>New here?</b><span>See how Setlo works in 60 seconds.</span></div>
    <button class="lp-btn lp-btn-primary lp-btn-sm" type="button" @click="tour('tour')">Start</button>
    <button class="lp-x" type="button" @click="dismissNudge" aria-label="Dismiss">×</button>
  </div>
</div>

<script>
Setlo.mount({
  data: () => ({
    menu: false, nudge: false, busy: false, err: '', hover: 0,
    r: { average: 0, count: 0, distribution: { 1: 0, 2: 0, 3: 0, 4: 0, 5: 0 }, reviews: [], signed_in: false, mine: null },
    form: { rating: 0, comment: '' },
  }),
  async mounted() {
    try { await this.load(); } catch (e) { /* the page works without reviews */ }
    if (!window.SetloTour.nudgeSeen()) setTimeout(() => { this.nudge = true; }, 4000);
  },
  methods: {
    async load() {
      const r = await api.get('feedback.php');
      this.r = r;
      if (r.mine) this.form = { rating: r.mine.rating, comment: r.mine.comment || '' };
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
        const res = await api.post('feedback.php', { rating: this.form.rating, comment: this.form.comment.trim() });
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
