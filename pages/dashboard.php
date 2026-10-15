<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_login();
$title = 'Dashboard';
$nav = 'dashboard';
$hour = (int) date('G');
$greeting = $hour < 12 ? 'Good morning,' : ($hour < 18 ? 'Good afternoon,' : 'Good evening,');
// First visit after signing up: the mascot's tour of the app (assets/js/tour.js), until finished or skipped
$showTour = q('SELECT onboarded_at FROM users WHERE id = ?', [$user['id']])->fetchColumn() === null;
$headExtra = ['assets/css/tour.css', 'assets/js/tour.js', 'assets/css/purchases.css'];
require __DIR__ . '/../partials/head.php';
?>
<div id="app" class="device" v-cloak>

  <div class="app-hero px-5 pb-16 pt-8">
    <div class="flex items-center justify-between">
      <div>
        <p class="text-[13px] font-medium text-brand-50/90"><?= h($greeting) ?></p>
        <h1 class="text-[22px] font-extrabold leading-tight tracking-tight"><?= h($user['full_name']) ?></h1>
      </div>
      <div class="flex items-center gap-2.5">
        <notif-bell data-tour="bell"></notif-bell>
        <div class="relative" data-tour="account">
          <?php $photo = avatar_url($user['avatar'], $user['id']); ?>
          <button class="glass-btn<?= $photo ? ' overflow-hidden !bg-white/90 p-0.5' : '' ?>" @click="menu = !menu" aria-label="Account menu"><?php if ($photo): ?><img src="<?= h($photo) ?>" alt="" class="h-full w-full rounded-full object-cover" /><?php else: ?><?= h(initials($user['full_name'])) ?><?php endif; ?></button>
          <div v-if="menu" class="hero-pop tile acct-menu absolute right-0 top-12 z-40 overflow-hidden text-slate-800 shadow-xl">
            <div class="border-b border-slate-100 px-4 py-3">
              <p class="text-sm font-bold text-ink"><?= h($user['full_name']) ?></p>
              <p class="truncate text-xs text-slate-400"><?= h($user['email']) ?> · <?= h($user['payment_method']) ?></p>
            </div>
<?php
// Each row: [href or null for a button, icon colour, icon paths, title, description, extra attributes]
$chevron = '<svg class="acct-chev" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 6l6 6-6 6"/></svg>';
$acctRows = [
    ['profile', 'c-brand', '<circle cx="12" cy="8" r="4"/><path stroke-linecap="round" d="M4 20c1.5-3.5 4.5-5 8-5s6.5 1.5 8 5"/>', 'My Profile', 'Name and photo'],
    ['payment-methods', 'c-amber', '<rect x="3" y="6" width="18" height="13" rx="2.5"/><path stroke-linecap="round" d="M3 10h18M7 15h3"/>', 'Payment Methods', 'GCash, Maya, QR'],
    ['account-settings', 'c-indigo', '<circle cx="12" cy="12" r="3"/><path stroke-linecap="round" d="M12 3v2.5M12 18.5V21M3 12h2.5M18.5 12H21M5.6 5.6l1.8 1.8M16.6 16.6l1.8 1.8M5.6 18.4l1.8-1.8M16.6 7.4l1.8-1.8"/>', 'Account Settings', 'Password, theme'],
    
];
foreach ($acctRows as [$href, $color, $paths, $title, $desc]):
    $inner = '<span class="acct-ic ' . $color . '"><svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24">' . $paths . '</svg></span>'
        . '<span class="acct-body"><span class="acct-title">' . $title . '</span><span class="acct-desc">' . h($desc) . '</span></span>' . $chevron;
?>
            <a href="<?= $href ?>" class="acct-item"><?= $inner ?></a>
<?php endforeach; ?>
            <form method="post" action="logout" class="border-t border-slate-100">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>" />
              <button class="acct-item acct-out">
                <span class="acct-ic c-rose"><svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg></span>
                <span class="acct-body"><span class="acct-title">Sign out</span><span class="acct-desc">This device</span></span>
                <?= $chevron ?>
              </button>
            </form>
          </div>
        </div>
      </div>
    </div>

    <!-- Search: Setlo's pages and features, plus the open bills on this page. No button: results appear as you type. -->
    <div class="relative mt-4 !z-40" data-search>
      <label for="dash-search" class="sr-only">Search Setlo</label>
      <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-white/70" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path stroke-linecap="round" d="M16 16l4 4"/></svg>
      <input id="dash-search" v-model="q" @focus="showResults = true" @input="showResults = true" @keydown.enter.prevent="openFirst" @keydown.esc="q = ''" type="search" autocomplete="off" spellcheck="false"
             class="h-11 w-full rounded-2xl border border-white/20 bg-white/15 pl-10 pr-4 text-[14px] font-medium text-white placeholder-white/70 outline-none backdrop-blur focus:border-white/50 focus:bg-white/20"
             placeholder="Search bills, utang, groups, settings…" role="combobox" :aria-expanded="results.length > 0" aria-controls="dash-results" />
      <div v-if="showResults && !q.trim()" role="listbox" class="tile absolute inset-x-0 top-12 z-40 overflow-hidden py-1 text-slate-800 shadow-xl">
        <p class="px-4 pb-1 pt-2 text-[11px] font-extrabold uppercase tracking-[.08em] text-slate-400">Suggestions</p>
        <a v-for="r in suggestions" :key="r.href" :href="r.href" role="option" class="flex items-center gap-3 px-4 py-2.5 hover:bg-[color:var(--field)]">
          <span class="min-w-0 flex-1">
            <span class="block truncate text-[14px] font-bold text-ink">{{ r.title }}</span>
            <span class="block truncate text-[12px] text-slate-400">{{ r.hint }}</span>
          </span>
          <svg class="h-4 w-4 shrink-0 text-slate-300" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
        </a>
      </div>
      <div v-if="q.trim() && showResults" id="dash-results" role="listbox" class="tile absolute inset-x-0 top-12 z-40 max-h-[min(24rem,60vh)] overflow-y-auto py-1 text-slate-800 shadow-xl">
        <a v-for="r in results" :key="r.href + r.title" :href="r.href" role="option" class="flex items-center gap-3 px-4 py-2.5 hover:bg-[color:var(--field)]">
          <span class="min-w-0 flex-1">
            <span class="block truncate text-[14px] font-bold text-ink">{{ r.title }}</span>
            <span class="block truncate text-[12px] text-slate-400">{{ r.hint }}</span>
          </span>
          <span class="shrink-0 rounded-full bg-[color:var(--tint)] px-2 py-0.5 text-[10.5px] font-bold text-brand-700">{{ r.kind }}</span>
        </a>
        <p v-if="!results.length" class="px-4 py-3 text-[13px] text-slate-400">Nothing matches “{{ q.trim() }}”. Try bills, utang, groups, scan or password.</p>
      </div>
    </div>
  </div>

  <div class="relative z-10 -mt-10 flex-1 space-y-6 px-5 pb-6">
    <!-- Owe / owed summary -->
    <div class="tile grid grid-cols-2 divide-x divide-slate-100 py-4 shadow-lg shadow-slate-900/5" data-tour="balance">
      <a href="my-settlements" class="px-4">
        <p class="text-[12px] font-medium text-slate-500">You owe</p>
        <p class="mt-0.5 text-[22px] font-extrabold tracking-tight text-rose-500">{{ (d.owe.estimated ? "≈ " : "") + peso(d.owe.total, d.owe.currency) }}</p>
        <p class="mt-1 text-[11px] text-slate-400">{{ d.owe.count }} open</p>
      </a>
      <a href="my-settlements?tab=owed" class="px-4">
        <p class="text-[12px] font-medium text-slate-500">You're owed</p>
        <p class="mt-0.5 text-[22px] font-extrabold tracking-tight text-brand-600">{{ (d.owed.estimated ? "≈ " : "") + peso(d.owed.total, d.owed.currency) }}</p>
        <p class="mt-1 text-[11px] text-slate-400">{{ d.owed.count }} open</p>
      </a>
    </div>

    <!-- Quick actions -->
    <div class="grid grid-cols-5 gap-1.5">
      <a href="my-bills?new=1" class="flex flex-col items-center gap-1.5" data-tour="new-bill">
        <div class="qa-icon bg-brand-100/70 text-brand-600">
          <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 8V6a2 2 0 012-2h2M16 4h2a2 2 0 012 2v2M20 16v2a2 2 0 01-2 2h-2M8 20H6a2 2 0 01-2-2v-2M8 12h8"/></svg>
        </div>
        <span class="text-[12px] font-semibold text-slate-600">New Bill</span>
      </a>
      <a href="my-bills" class="flex flex-col items-center gap-1.5">
        <div class="qa-icon bg-indigo-100 text-indigo-500">
          <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 3h12v18l-3-2-3 2-3-2-3 2V3zM9 8h6M9 12h6"/></svg>
        </div>
        <span class="text-[12px] font-semibold text-slate-600">My Bills</span>
      </a>
      <a href="my-settlements" class="flex flex-col items-center gap-1.5">
        <div class="qa-icon bg-amber-100 text-amber-600">
          <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" stroke-linejoin="round" d="M14.5 9.5c-.4-.9-1.4-1.5-2.5-1.5-1.4 0-2.5.8-2.5 2s1.1 1.7 2.5 2 2.5.8 2.5 2-1.1 2-2.5 2c-1.1 0-2.1-.6-2.5-1.5M12 6.5V8m0 8v1.5"/></svg>
        </div>
        <span class="text-[12px] font-semibold text-slate-600">Settle Up</span>
      </a>
      <a href="scan-pay" class="flex flex-col items-center gap-1.5">
        <div class="qa-icon bg-fuchsia-100 text-fuchsia-600">
          <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path stroke-linecap="round" d="M14 14h3v3h-3zM20 14v.01M14 20h3M20 17v4"/></svg>
        </div>
        <span class="text-[12px] font-semibold text-slate-600">Scan &amp; Pay</span>
      </a>
      <a href="stats?tab=history" class="flex flex-col items-center gap-1.5">
        <div class="qa-icon bg-slate-200/80 text-slate-600">
          <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 7v5l3 2"/></svg>
        </div>
        <span class="text-[12px] font-semibold text-slate-600">History</span>
      </a>
    </div>

    <a href="stats" class="tile flex items-center gap-3 px-4 py-3">
      <span class="qa-icon !h-10 !w-10 bg-brand-100/70 text-brand-700">
        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg>
      </span>
      <span class="flex-1">
        <span class="block text-[13px] font-bold text-ink">Your food spending</span>
        <span class="block text-[12px] text-slate-400">Monthly totals, favorite spots, what you order most</span>
      </span>
      <span class="text-slate-300">›</span>
    </a>

    <div class="space-y-6 lg:grid lg:grid-cols-2 lg:items-start lg:gap-6 lg:space-y-0">
    <!-- Active bills -->
    <section data-tour="bills">
      <div class="mb-3 flex items-center justify-between">
        <h2 class="text-[15px] font-extrabold text-ink">Active Bills</h2>
        <a href="my-bills" class="text-[13px] font-bold text-brand-700">See all</a>
      </div>
      <spinner v-if="loading"></spinner>
      <div v-else-if="!d.bills.length" class="tile p-5 text-center">
        <p class="text-sm font-bold text-ink">No open bills</p>
        <p class="mt-1 text-[12px] text-slate-400">Create one after your next meal out.</p>
        <a href="my-bills?new=1" class="btn btn-primary btn-sm mt-3">Create a bill</a>
      </div>
      <div v-else class="space-y-3">
        <a v-for="b in d.bills" :key="b.id" :href="b.link" class="tile flex items-center gap-3.5 p-4">
          <div class="initials">{{ b.initials }}</div>
          <div class="min-w-0 flex-1">
            <p class="truncate text-[14px] font-bold text-ink">{{ b.name }}</p>
            <p class="mt-0.5 text-[12px] text-slate-400">{{ b.members }} members · {{ peso(b.total, b.currency) }}<template v-if="b.receipt"> · from {{ peso(b.receipt.total, b.receipt.currency) }}</template></p>
            <div class="progress mt-2 w-full max-w-[150px]"><span :style="{ width: b.progress + '%' }"></span></div>
          </div>
          <status-pill :status="b.status"></status-pill>
        </a>
      </div>
    </section>

    <!-- My purchases: fills the column beside Active Bills -->
    <section>
      <div class="mb-3 flex items-center justify-between">
        <h2 class="text-[15px] font-extrabold text-ink">My Purchases</h2>
        <a href="purchases" class="text-[13px] font-bold text-brand-700">See all</a>
      </div>
      <div class="tile p-4">
        <div class="flex items-end justify-between gap-3">
          <div>
            <p class="text-[12px] font-medium text-slate-500">Spent this month</p>
            <p class="text-[22px] font-extrabold leading-tight tracking-tight text-ink">{{ peso(d.purchases.month_total, d.user.currency) }}</p>
          </div>
          <a href="purchases?new=1" class="btn btn-primary btn-sm">+ Add</a>
        </div>
        <p v-if="!d.purchases.recent.length" class="mt-3 text-[12px] text-slate-400">Log your own purchases — coffee, groceries, a ride — and see where your money goes.</p>
        <div v-else class="-mx-4 -mb-4 mt-3 divide-y divide-slate-100 border-t border-slate-100">
          <a v-for="p in d.purchases.recent" :key="p.id" href="purchases" class="flex items-center gap-3 px-4 py-3">
            <div class="min-w-0 flex-1">
              <p class="truncate text-[13.5px] font-bold text-ink">{{ p.title }}</p>
              <p class="text-[11.5px] text-slate-400">{{ p.category }} · {{ fmtDate(p.date) }}</p>
            </div>
            <span class="text-[13.5px] font-extrabold text-ink">{{ peso(p.amount, d.user.currency) }}</span>
          </a>
        </div>
      </div>
    </section>

    <!-- Recent activity -->
    <section v-if="d.activity.length">
      <h2 class="mb-3 text-[15px] font-extrabold text-ink">Recent Activity</h2>
      <div class="tile divide-y divide-slate-100">
        <div v-for="(a, i) in d.activity" :key="i" class="flex items-center gap-3 px-4 py-3.5">
          <span class="h-2 w-2 shrink-0 rounded-full" :class="dot[a.event] || 'bg-slate-300'"></span>
          <p class="flex-1 text-[13px] text-slate-600"><b class="text-ink">{{ a.actor }}</b> {{ describe(a) }}</p>
          <span class="shrink-0 text-[11px] text-slate-400">{{ timeAgo(a.created_at) }}</span>
        </div>
      </div>
    </section>
    </div>
  </div>

  <?php require __DIR__ . '/../partials/nav.php'; ?>
</div>

<script>
const SHOW_TOUR = <?= $showTour ? 'true' : 'false' ?>;

// Where each page lives and the words people use for it; the dashboard search matches against these
const SEARCH_INDEX = [
  { title: 'Dashboard', href: 'dashboard', hint: 'Your balance and active bills', kw: 'home overview balance summary' },
  { title: 'Scan to pay', href: 'scan-pay', hint: 'Scan a friend’s Pay-me QR and pay them', kw: 'qr code camera scan pay friend send money gcash' },
  { title: 'Scan a receipt', href: 'scan-receipt', hint: 'Photograph a receipt to start a bill', kw: 'ocr camera photo upload new bill split' },
  { title: 'New bill', href: 'my-bills?new=1', hint: 'Start splitting a bill', kw: 'create split add expense dinner' },
  { title: 'My bills', href: 'my-bills', hint: 'Open and past bills', kw: 'bills active history receipts split' },
  { title: 'Groups', href: 'groups', hint: 'Your groups and their members', kw: 'group members friends roommates invite join' },
  { title: 'Settle up', href: 'my-settlements', hint: 'Pay what you owe, confirm what you are owed', kw: 'pay payment owe owed gcash maya cash confirm dispute settlement' },
  { title: 'Utang', href: 'utang', hint: 'Money lent or borrowed, credits and history', kw: 'debt debts loan lend borrow repay repayments credits change history owe personal' },
  { title: 'Personal expenses', href: 'purchases', hint: 'Your own spending outside any bill', kw: 'purchases expenses spending food transport journal to pay' },
  { title: 'Spending overview', href: 'stats', hint: 'Monthly totals and favorite spots', kw: 'stats analytics chart month food budget' },
  { title: 'Settlement history', href: 'stats?tab=history', hint: 'Past bills and settlements', kw: 'history past closed bills paid' },
  { title: 'Community', href: 'community', hint: 'Posts from other Setlo users', kw: 'posts social feed share tips' },
  { title: 'Notifications', href: 'notifications', hint: 'What happened on your bills', kw: 'alerts bell inbox updates' },
  { title: 'My profile', href: 'profile', hint: 'Name, username, achievements and balances', kw: 'account name username email picture avatar photo badges achievements' },
  { title: 'Payment methods', href: 'payment-methods', hint: 'GCash/Maya, bank, Pay-me QR, currency', kw: 'gcash maya bank qr pay-me currency peso preferred' },
  { title: 'Account settings', href: 'account-settings', hint: 'Password and appearance', kw: 'password security theme dark mode light color background appearance' },
  { title: 'Terms & Privacy', href: 'conditions_policy', hint: 'Terms and conditions', kw: 'terms conditions privacy policy legal' },
];
Setlo.mount({
  data: () => ({
    loading: true, menu: false, q: '', showResults: false,
    d: { owe: { total: 0, count: 0 }, owed: { total: 0, count: 0 }, bills: [], activity: [], purchases: { month_total: 0, recent: [] }, user: { currency: 'PHP' } },
    dot: { marked_paid: 'bg-amber-400', confirmed: 'bg-brand-500', disputed: 'bg-rose-400', resent: 'bg-sky-400', nudged: 'bg-slate-400' },
  }),
  computed: {
    /** Shown when the box is focused and empty: the things people reach for most, and a bill that needs attention. */
    suggestions() {
      const pick = ['Scan a receipt', 'New bill', 'Settle up', 'Utang', 'Personal expenses'];
      const rows = pick.map((t) => SEARCH_INDEX.find((f) => f.title === t)).filter(Boolean);
      const open = (this.d.bills || [])[0];
      return open ? [{ title: open.name, href: open.link, hint: 'Your latest active bill' }, ...rows].slice(0, 5) : rows;
    },
    /** Features first (title matches, then keywords), then the open bills on this page. */
    results() {
      const words = this.q.toLowerCase().split(/\s+/).filter(Boolean);
      if (!words.length) return [];
      const score = (title, rest) => {
        const t = title.toLowerCase(), r = rest.toLowerCase();
        let n = 0;
        for (const w of words) {
          if (t.startsWith(w)) n += 4; else if (t.includes(w)) n += 3; else if (r.includes(w)) n += 1; else return 0; // every word has to match
        }
        return n;
      };
      const pages = SEARCH_INDEX.map((f) => ({ ...f, kind: 'Page', n: score(f.title, f.kw + ' ' + f.hint) }));
      const bills = (this.d.bills || []).map((b) => ({ title: b.name, href: b.link, hint: b.members + ' members · ' + this.peso(b.total, b.currency), kind: 'Bill', n: score(b.name, 'bill ' + b.status) }));
      return [...pages, ...bills].filter((r) => r.n > 0).sort((a, b) => b.n - a.n).slice(0, 8);
    },
  },
  async mounted() {
    document.addEventListener('click', (e) => { if (!e.target.closest('[data-search]')) this.showResults = false; });
    document.addEventListener('click', (e) => { if (!e.target.closest('[aria-label="Account menu"]') && !e.target.closest('form')) this.menu = false; });
    await Setlo.load(this, 'dashboard.php', null, (r) => { this.d = r; });
    // The tour first, so a badge pop-up never opens on top of it
    if (SHOW_TOUR) await SetloTour.start();
    else if (new URLSearchParams(location.search).has('tour')) await SetloTour.start({ replay: true }); // Help & Support → Replay the tour
    this.showNewAchievements();
  },
  methods: {
    openFirst() { if (this.results.length) location.href = this.results[0].href; },
    replayTour() {
      this.menu = false;
      SetloTour.start({ replay: true });
    },
    /** Badges earned while away (someone else's action earned them): their pop-ups, once. */
    async showNewAchievements() {
      try {
        const r = await api.get('achievements.php');
        if (!r.unseen.length) return;
        await Setlo.showAchievements(r.unseen);
        await api.post('achievements.php', { action: 'seen', codes: r.unseen.map((a) => a.code) });
      } catch (e) { /* badges are non-critical */ }
    },
    describe(a) {
      const amt = this.peso(a.amount, a.currency);
      switch (a.event) {
        case 'marked_paid': return `marked ${amt} as paid to ${a.to}`;
        case 'confirmed': return `confirmed receiving ${amt}`;
        case 'disputed': return `disputed a ${amt} payment`;
        case 'resent': return `reopened a ${amt} payment`;
        case 'nudged': return a.by_admin ? `followed up on a dispute in ${a.bill}` : `sent a reminder about ${amt}`;
        default: return `updated ${a.bill}`;
      }
    },
  },
});
</script>
<?php require __DIR__ . '/../partials/foot.php'; ?>
