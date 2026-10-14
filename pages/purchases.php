<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_login();
// My purchases: your own expenses outside any bill. Scan a receipt to fill one in, mark ones still to pay and settle
// them later, and read the journal of it all. Spending (stats) analyzes them next to your bill shares.
$title = 'Personal expenses';
$nav = 'purchases';
$back = 'dashboard.php';
$headExtra = ['assets/css/purchases.css'];
require __DIR__ . '/../partials/head.php';
?>
<div id="app" class="device" v-cloak>

  <div class="app-hero px-5 pb-5 pt-8">
    <div class="flex items-center gap-3">
      <?php require __DIR__ . '/../partials/back.php'; ?>
      <div class="min-w-0 flex-1">
        <h1 class="text-[21px] font-extrabold tracking-tight">Personal expenses</h1>
        <p class="text-[12px] font-medium text-brand-50/90">Your own spending, outside any bill</p>
      </div>
      <button v-if="d.scan" @click="scanOpen = true" :disabled="scanning" class="shrink-0 rounded-full bg-white/15 px-3.5 py-2 text-[13px] font-bold text-white hover:bg-white/25" aria-label="Scan a receipt">Scan</button>
      <button @click="openForm()" class="shrink-0 rounded-full bg-white/15 px-3.5 py-2 text-[13px] font-bold text-white hover:bg-white/25">+ Add</button>
    </div>
    <div class="seg mt-5" role="tablist" aria-label="Purchases view">
      <button role="tab" :aria-selected="tab === 'purchases'" :class="{ on: tab === 'purchases' }" @click="tab = 'purchases'">Purchases</button>
      <button role="tab" :aria-selected="tab === 'unpaid'" :class="{ on: tab === 'unpaid' }" @click="tab = 'unpaid'">To pay<span v-if="d.unpaid.rows.length" class="pc-count">{{ d.unpaid.rows.length }}</span></button>
      <button role="tab" :aria-selected="tab === 'journal'" :class="{ on: tab === 'journal' }" @click="showJournal">Journal</button>
    </div>
    <input ref="scanFile" type="file" accept="image/*" class="hidden" @change="scan" />
  </div>

  <spinner v-if="loading"></spinner>

  <!-- Purchases of a month -->
  <div v-else-if="tab === 'purchases'" :key="'m' + d.month" class="flex-1 space-y-4 px-5 pb-6 pt-4" :class="[dir ? 'pc-in-' + dir : '', { 'pc-dim': navBusy }]">

    <!-- Summary + where it went: side by side on laptops -->
    <div class="space-y-4 lg:grid lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)] lg:items-stretch lg:gap-5 lg:space-y-0">
      <div class="tile p-4">
        <div class="pc-month">
          <button type="button" @click="go(-1)" :disabled="navBusy" aria-label="Previous month">‹</button>
          <p class="text-[14px] font-extrabold text-ink">{{ monthLabel }}</p>
          <button type="button" @click="go(1)" :disabled="isCurrent || navBusy" aria-label="Next month">›</button>
        </div>
        <p class="mt-3 text-[12px] font-medium text-slate-500">Spent this month</p>
        <p class="text-[30px] font-extrabold leading-tight tracking-tight text-ink">{{ peso(d.total, d.currency) }}</p>
        <p class="mt-1 text-[12px] font-semibold" :class="change === null ? 'text-slate-400' : change > 0 ? 'text-rose-500' : 'text-brand-600'">
          <template v-if="change === null">{{ d.count }} purchase{{ d.count === 1 ? '' : 's' }} · nothing last month to compare</template>
          <template v-else>{{ Math.abs(change) }}% {{ change > 0 ? 'more' : 'less' }} than last month · {{ d.count }} purchase{{ d.count === 1 ? '' : 's' }}</template>
        </p>
      </div>

      <div class="tile p-4">
        <p class="text-[13px] font-extrabold text-ink">Where it went</p>
        <p v-if="!d.by_category.length" class="mt-2 text-[12px] text-slate-400">Nothing spent yet this month.</p>
        <div v-else class="pc-cats mt-3">
          <div v-for="c in d.by_category" :key="c.category" class="pc-cat">
            <div class="flex items-center justify-between gap-2 text-[12.5px]">
              <span class="truncate font-semibold text-slate-600">{{ c.category }}</span>
              <span class="font-bold text-ink">{{ peso(c.amount, d.currency) }}</span>
            </div>
            <div class="pc-bar mt-1.5"><span :style="{ width: Math.max(4, c.amount / d.total * 100) + '%', background: color(c.category) }"></span></div>
          </div>
        </div>
        <a v-if="d.by_category.length" href="stats" class="mt-3 block text-[12px] font-bold text-brand-700">See it in Spending ›</a>
      </div>
    </div>

    <!-- Recent expenses: heading, then the filters right under it, then the list -->
    <div class="space-y-3">
      <div class="pc-toolbar">
        <h2 class="text-[15px] font-extrabold text-ink">Recent expenses</h2>
<?php $searchHint = 'Search expenses, places or categories'; require __DIR__ . '/../partials/list-search.php'; ?>
        <div class="pc-chips" role="tablist" aria-label="Filter by category">
          <button type="button" class="pc-chip" :class="{ on: !cat }" @click="cat = ''">All</button>
          <button v-for="c in d.categories" :key="c" type="button" class="pc-chip" :class="{ on: cat === c }" @click="cat = c">{{ c }}</button>
        </div>
      </div>

      <div v-if="!shown.length" class="tile p-6 text-center">
        <p class="text-sm font-bold text-ink">{{ q.trim() ? 'Nothing matches “' + q.trim() + '”' : d.purchases.length ? 'Nothing in this category' : 'No purchases this month' }}</p>
        <p class="mt-1 text-[12px] text-slate-400">Log what you buy — or scan the receipt — and Spending adds it up.</p>
        <div class="mt-3 flex justify-center gap-2">
          <button v-if="d.scan" @click="scanOpen = true" class="btn btn-outline btn-sm">Scan a receipt</button>
          <button @click="openForm()" class="btn btn-primary btn-sm">+ Add a purchase</button>
        </div>
      </div>
      <template v-for="g in groups" :key="g.date">
        <p class="pt-1 text-xs font-semibold text-slate-400">{{ fmtDate(g.date, true) }}</p>
        <div class="tile divide-y divide-slate-100 overflow-hidden">
          <button v-for="p in g.rows" :key="p.id" type="button" class="pc-row" @click="openForm(p)">
            <span class="pc-dot" :style="{ background: color(p.category) + '22', color: color(p.category) }">{{ p.category.charAt(0) }}</span>
            <span class="min-w-0 flex-1">
              <span class="block truncate text-[14px] font-bold text-ink">{{ p.title }}</span>
              <span class="block truncate text-[12px] text-slate-400">{{ p.category }}<template v-if="p.merchant"> · {{ p.merchant }}</template><template v-if="p.payment_method"> · {{ p.payment_method }}</template><template v-if="p.has_receipt"> · 🧾</template></span>
            </span>
            <span class="text-right">
              <span class="block text-[14px] font-extrabold text-ink">{{ peso(p.amount, d.currency) }}</span>
              <span v-if="p.status === 'unpaid'" class="pc-tag" :class="p.overdue ? 'pc-tag-late' : 'pc-tag-due'">{{ p.overdue ? 'Overdue' : 'To pay' }}</span>
            </span>
          </button>
        </div>
      </template>
    </div>
  </div>

  <!-- To pay: purchases not paid yet; settle them here -->
  <div v-else-if="tab === 'unpaid'" class="flex-1 space-y-3 px-5 pb-6 pt-4">
    <div class="tile p-4">
      <p class="text-[12px] font-medium text-slate-500">Still to pay</p>
      <p class="text-[26px] font-extrabold leading-tight tracking-tight text-ink">{{ peso(d.unpaid.total, d.currency) }}</p>
      <p class="mt-1 text-[12px] text-slate-400">{{ d.unpaid.rows.length }} purchase{{ d.unpaid.rows.length === 1 ? '' : 's' }} waiting. Settle one when you pay it — it goes in your journal.</p>
    </div>
    <div v-if="!d.unpaid.rows.length" class="tile p-6 text-center">
      <p class="text-sm font-bold text-ink">Nothing to pay</p>
      <p class="mt-1 text-[12px] text-slate-400">When you add a purchase, choose “To pay later” for a bill that is due.</p>
    </div>
    <div v-for="p in d.unpaid.rows" :key="p.id" class="tile p-4">
      <div class="flex items-start gap-3">
        <button type="button" class="min-w-0 flex-1 text-left" @click="openForm(p)">
          <span class="block truncate text-[14px] font-bold text-ink">{{ p.title }}</span>
          <span class="block text-[12px]" :class="p.overdue ? 'font-semibold text-rose-500' : 'text-slate-400'">{{ p.due ? (p.overdue ? 'Was due ' : 'Due ') + fmtDate(p.due, true) : 'No due date' }} · {{ p.category }}</span>
        </button>
        <span class="text-[15px] font-extrabold text-ink">{{ peso(p.amount, d.currency) }}</span>
      </div>
      <button type="button" class="btn btn-primary btn-sm mt-3 w-full" @click="openSettle(p)">Mark as paid</button>
    </div>
  </div>

  <!-- Journal: everything that happened to your purchases, newest first -->
  <div v-else class="flex-1 space-y-3 px-5 pb-6 pt-4">
    <spinner v-if="!journalLoaded"></spinner>
    <div v-else-if="!events.length" class="tile p-6 text-center">
      <p class="text-sm font-bold text-ink">Your journal is empty</p>
      <p class="mt-1 text-[12px] text-slate-400">Adding, scanning, editing and settling purchases are all written here.</p>
    </div>
    <template v-for="g in eventGroups" :key="g.day">
      <p class="pt-1 text-xs font-semibold text-slate-400">{{ fmtDate(g.day, true) }}</p>
      <div class="tile divide-y divide-slate-100 overflow-hidden">
        <div v-for="e in g.rows" :key="e.id" class="flex items-start gap-3 px-4 py-3">
          <span class="pc-ev" :class="'pc-ev-' + e.event" aria-hidden="true">{{ evIcon[e.event] }}</span>
          <div class="min-w-0 flex-1">
            <p class="text-[13.5px] text-slate-600"><b class="text-ink">{{ e.title }}</b> · {{ evText[e.event] }}</p>
            <p v-if="e.detail" class="truncate text-[11.5px] text-slate-400">{{ e.detail }}</p>
          </div>
          <div class="text-right">
            <p class="text-[13px] font-extrabold" :class="e.event === 'deleted' ? 'text-slate-400 line-through' : 'text-ink'">{{ peso(e.amount, currency) }}</p>
            <p class="text-[11px] text-slate-400">{{ timeOf(e.at) }}</p>
          </div>
        </div>
      </div>
    </template>
    <button v-if="journalMore" class="btn btn-outline btn-sm w-full" :disabled="busy" @click="moreJournal">Show older</button>
  </div>

  <?php require __DIR__ . '/../partials/nav.php'; ?>

  <!-- Scan a receipt: the camera, or a photo from the gallery -->
  <div v-if="scanOpen" class="sheet-backdrop pc-fade" @click.self="scanOpen = false">
    <div class="sheet pc-rise text-center">
      <div class="sheet-grip"></div>
      <div class="pc-scan-art" aria-hidden="true"><span class="pc-scan-paper"></span><span class="pc-scan-line"></span></div>
      <h2 class="text-[17px] font-extrabold text-ink">Scan a receipt</h2>
      <p class="mt-1 text-[16px] font-extrabold text-ink">Position the receipt in frame</p>
      <p class="mt-1 text-[13px] leading-snug text-slate-500">The store, date and total should be clearly visible. We fill in the purchase for you to check.</p>
      <div class="mt-5 grid grid-cols-2 gap-3">
        <button type="button" @click="$refs.scanFile.click()" class="btn-pill btn-pill-outline"><svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="2.5"/><circle cx="8.5" cy="9.5" r="1.5"/><path stroke-linecap="round" stroke-linejoin="round" d="M21 16l-5-5-9 9"/></svg> Upload</button>
        <button type="button" @click="takePhoto" class="btn-pill btn-pill-primary"><svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h.5l1-2h11l1 2H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><circle cx="12" cy="13" r="3.5"/></svg> Take Photo</button>
      </div>
      <button type="button" @click="scanOpen = false" class="btn btn-ghost mt-3 w-full">Cancel</button>
    </div>
  </div>

  <!-- Reading the receipt -->
  <div v-if="scanning" class="sheet-backdrop pc-fade">
    <div class="sheet pc-rise text-center">
      <div class="sheet-grip"></div>
      <div class="pc-scan-art pc-scan-busy" aria-hidden="true"><span class="pc-scan-paper"></span><span class="pc-scan-line"></span></div>
      <p class="text-[15px] font-extrabold text-ink">Reading your receipt…</p>
      <p class="mt-1 text-[12px] text-slate-400">This takes a few seconds.</p>
    </div>
  </div>

  <!-- Add / edit -->
  <div v-if="form" class="sheet-backdrop" @click.self="form = null">
    <form class="sheet" @submit.prevent="save" novalidate>
      <div class="sheet-grip"></div>
      <h2 class="text-[17px] font-extrabold text-ink">{{ form.id ? 'Edit purchase' : form.receipt_image ? 'Check your receipt' : 'Add a purchase' }}</h2>
      <p v-if="form.scanned" class="mt-1 text-[12px] text-slate-500">Read from your receipt{{ form.items ? ' (' + form.items + ' item' + (form.items === 1 ? '' : 's') + ')' : '' }} — fix anything that looks off.</p>
      <p v-if="form.other_currency" class="mt-2 rounded-xl bg-amber-50 px-3 py-2 text-[12px] font-semibold text-amber-800">The receipt looks like {{ form.other_currency }}. Enter the amount in {{ d.currency }}.</p>

      <div v-if="form.id && form.has_receipt" class="mt-3">
        <button type="button" class="pc-receipt-link" @click="viewReceipt(form.id)">🧾 View the receipt photo</button>
      </div>

      <label class="field-label mt-4" for="pc-title">What did you buy?</label>
      <input id="pc-title" data-field="title" v-model="form.title" @input="touch('title')" :class="{ 'is-invalid': err('title') }" class="input-soft" maxlength="80" placeholder="e.g. Iced coffee, Meralco bill" />
      <p v-if="err('title')" class="field-error">{{ err('title') }}</p>

      <div class="pc-grid2 mt-4">
        <div>
          <label class="field-label" for="pc-amount">Amount ({{ d.currency }})</label>
          <input id="pc-amount" data-field="amount" v-model="form.amount" @input="touch('amount')" :class="{ 'is-invalid': err('amount') }" class="input-soft" inputmode="decimal" placeholder="0.00" />
          <p v-if="err('amount')" class="field-error">{{ err('amount') }}</p>
        </div>
        <div>
          <label class="field-label" for="pc-date">Date</label>
          <input id="pc-date" type="date" data-field="date" v-model="form.date" :max="today" @input="touch('date')" :class="{ 'is-invalid': err('date') }" class="input-soft" />
          <p v-if="err('date')" class="field-error">{{ err('date') }}</p>
        </div>
      </div>

      <label class="field-label mt-4" for="pc-cat">Category</label>
      <select id="pc-cat" data-field="category" v-model="form.category" @change="touch('category')" :class="{ 'is-invalid': err('category') }" class="input-soft">
        <option value="" disabled>Choose one</option>
        <option v-for="c in d.categories" :key="c" :value="c">{{ c }}</option>
      </select>
      <p v-if="err('category')" class="field-error">{{ err('category') }}</p>

      <p class="field-label mt-4">Payment</p>
      <div class="seg seg-light" role="radiogroup" aria-label="Paid or to pay">
        <button type="button" role="radio" :aria-checked="form.status === 'paid'" :class="{ on: form.status === 'paid' }" @click="form.status = 'paid'">Already paid</button>
        <button type="button" role="radio" :aria-checked="form.status === 'unpaid'" :class="{ on: form.status === 'unpaid' }" @click="form.status = 'unpaid'">To pay later</button>
      </div>
      <div v-if="form.status === 'unpaid'" class="mt-3">
        <label class="field-label" for="pc-due">Due date <span class="font-normal text-slate-400">(optional)</span></label>
        <input id="pc-due" type="date" v-model="form.due" class="input-soft" />
      </div>

      <label class="field-label mt-4" for="pc-note">Note <span class="font-normal text-slate-400">(optional)</span></label>
      <textarea id="pc-note" v-model="form.note" class="input-soft" rows="2" maxlength="300" placeholder="Anything to remember"></textarea>

      <details class="pc-details" :open="!!(form.merchant || form.receipt_no || form.payment_method)">
        <summary>Receipt details</summary>
        <label class="field-label mt-3" for="pc-merchant">Store / seller</label>
        <input id="pc-merchant" v-model="form.merchant" class="input-soft" maxlength="60" placeholder="e.g. 7-Eleven, Mercury Drug" />
        <div class="pc-grid2 mt-3">
          <div>
            <label class="field-label" for="pc-rno">Receipt / OR no.</label>
            <input id="pc-rno" v-model="form.receipt_no" class="input-soft" maxlength="40" placeholder="optional" />
          </div>
          <div>
            <label class="field-label" for="pc-method">Paid with</label>
            <select id="pc-method" v-model="form.payment_method" class="input-soft">
              <option value="">—</option>
              <option v-for="m in d.methods" :key="m" :value="m">{{ m }}</option>
            </select>
          </div>
        </div>
      </details>

      <div class="mt-5 grid grid-cols-2 gap-2.5">
        <button type="button" @click="closeForm" class="btn-pill btn-pill-soft">Cancel</button>
        <button class="btn-pill btn-pill-primary" :disabled="busy">{{ form.id ? 'Save changes' : 'Add purchase' }}</button>
      </div>
      <button v-if="form.id && form.status === 'paid'" type="button" @click="reopen" class="btn btn-ghost mt-2 w-full" :disabled="busy">Move back to “to pay”</button>
      <button v-if="form.id" type="button" @click="remove" class="btn btn-ghost mt-1 w-full !text-rose-600" :disabled="busy">Delete purchase</button>
    </form>
  </div>

  <!-- Settle a purchase that was still to pay -->
  <div v-if="settling" class="sheet-backdrop" @click.self="settling = null">
    <form class="sheet" @submit.prevent="settle" novalidate>
      <div class="sheet-grip"></div>
      <h2 class="text-[17px] font-extrabold text-ink">Mark as paid</h2>
      <p class="mt-1 text-[13px] text-slate-500">{{ settling.title }} · <b class="text-ink">{{ peso(settling.amount, d.currency) }}</b></p>
      <div class="pc-grid2 mt-4">
        <div>
          <label class="field-label" for="st-date">Paid on</label>
          <input id="st-date" type="date" v-model="settling.date" :max="today" class="input-soft" />
        </div>
        <div>
          <label class="field-label" for="st-method">Paid with</label>
          <select id="st-method" v-model="settling.method" class="input-soft">
            <option value="">—</option>
            <option v-for="m in d.methods" :key="m" :value="m">{{ m }}</option>
          </select>
        </div>
      </div>
      <div class="mt-5 grid grid-cols-2 gap-2.5">
        <button type="button" @click="settling = null" class="btn-pill btn-pill-soft">Cancel</button>
        <button class="btn-pill btn-pill-primary" :disabled="busy">Mark as paid</button>
      </div>
    </form>
  </div>
</div>

<script>
const COLORS = { 'Food & drinks': '#f97316', Groceries: '#16a34a', Transport: '#0ea5e9', Shopping: '#a855f7', 'Bills & utilities': '#eab308', Health: '#ef4444', Entertainment: '#ec4899', Education: '#6366f1', Other: '#64748b' };
const todayStr = () => { const d = new Date(); return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10); };
const blank = (extra) => ({ id: null, title: '', amount: '', date: todayStr(), category: '', status: 'paid', due: '', note: '', merchant: '', receipt_no: '', payment_method: '', receipt_image: '', ...extra });
Setlo.mount({
  mixins: [Setlo.validation],
  data: () => ({
    loading: true, busy: false, navBusy: false, dir: '', scanning: false, scanOpen: false, tab: new URLSearchParams(location.search).get('tab') || 'purchases',
    month: new URLSearchParams(location.search).get('month') || '', cat: '', q: '', form: null, settling: null, today: todayStr(),
    d: { month: '', currency: 'PHP', total: 0, last_month: 0, count: 0, by_category: [], purchases: [], unpaid: { total: 0, rows: [] }, categories: [], methods: [], scan: false },
    events: [], journalLoaded: false, journalMore: false, currency: 'PHP',
    evIcon: { added: '+', scanned: '▣', edited: '✎', settled: '✓', reopened: '↺', deleted: '✕' },
    evText: { added: 'added', scanned: 'added from a scanned receipt', edited: 'edited', settled: 'settled', reopened: 'moved back to pay', deleted: 'deleted' },
  }),
  computed: {
    shown() {
      const q = this.q.trim().toLowerCase();
      const hit = (p) => !q || [p.title, p.category, p.merchant, p.payment_method].some((v) => v && String(v).toLowerCase().includes(q));
      return this.d.purchases.filter((p) => (!this.cat || p.category === this.cat) && hit(p));
    },
    groups() {
      const out = [];
      this.shown.forEach((p) => {
        const last = out[out.length - 1];
        if (last && last.date === p.date) last.rows.push(p); else out.push({ date: p.date, rows: [p] });
      });
      return out;
    },
    eventGroups() {
      const out = [];
      this.events.forEach((e) => {
        const day = e.at.slice(0, 10), last = out[out.length - 1];
        if (last && last.day === day) last.rows.push(e); else out.push({ day, rows: [e] });
      });
      return out;
    },
    monthLabel() { return /^\d{4}-\d{2}$/.test(this.d.month) ? new Date(this.d.month + '-01T00:00:00').toLocaleDateString('en-PH', { month: 'long', year: 'numeric' }) : ''; },
    isCurrent() { return this.d.month >= this.today.slice(0, 7); },
    change() { return this.d.last_month > 0 ? Math.round((this.d.total - this.d.last_month) / this.d.last_month * 100) : null; },
  },
  async mounted() {
    await this.load();
    if (this.tab === 'journal') this.showJournal();
    if (new URLSearchParams(location.search).has('new')) this.openForm();
  },
  methods: {
    color(c) { return COLORS[c] || COLORS.Other; },
    timeOf(s) { return new Date(s.replace(' ', 'T')).toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' }); },
    async load(busyKey) {
      await Setlo.load(this, 'purchases.php', this.month ? { month: this.month } : null, (r) => { this.d = r; this.month = r.month; }, busyKey);
    },
    go(step) {
      const [y, m] = (/^\d{4}-\d{2}$/.test(this.d.month) ? this.d.month : this.today.slice(0, 7)).split('-').map(Number);
      const n = new Date(y, m - 1 + step, 1);
      this.month = `${n.getFullYear()}-${String(n.getMonth() + 1).padStart(2, '0')}`;
      this.cat = '';
      this.dir = step > 0 ? 'next' : 'prev'; // the new month slides in from that side
      this.load('navBusy');
    },
    /** The journal: loaded when first opened, and again after any change */
    async showJournal() {
      this.tab = 'journal';
      if (this.journalLoaded) return;
      await this.loadJournal(false);
    },
    async loadJournal(more) {
      try {
        const r = await api.get('purchases.php', { view: 'journal', ...(more && this.events.length ? { before: this.events[this.events.length - 1].id } : {}) });
        this.events = more ? this.events.concat(r.events) : r.events;
        this.journalMore = r.more;
        this.currency = r.currency;
        this.journalLoaded = true;
      } catch (e) { Setlo.toast(e.message); }
    },
    async moreJournal() { this.busy = true; await this.loadJournal(true); this.busy = false; },
    /** After any change: the lists refresh, and the journal reloads next time it is opened */
    async changed() { this.journalLoaded = false; this.dir = ''; await this.load(); if (this.tab === 'journal') await this.loadJournal(false); },

    openForm(p) {
      this.resetValidation();
      this.form = p
        ? { ...blank(), id: p.id, title: p.title, amount: String(p.amount), date: p.date, category: p.category, status: p.status, due: p.due || '', note: p.note || '', merchant: p.merchant || '',
            receipt_no: p.receipt_no || '', payment_method: p.payment_method || '', has_receipt: p.has_receipt }
        : blank({ category: this.cat || '' });
    },
    /** Closing a scanned, unsaved form leaves its photo on the server; it is harmless and cleared with the next scan. */
    closeForm() { this.form = null; },
    viewReceipt(id) { Setlo.photoViewer('<?= h(url('api/purchases.php')) ?>?image=1&id=' + id, { title: 'Receipt' }); },
    validators() {
      const f = this.form || {};
      const title = (f.title || '').trim(), amt = Number(String(f.amount || '').replace(/,/g, ''));
      return {
        title: title.length < 2 ? 'Say what you bought.' : /[<>]/.test(title) ? 'No < or > please.' : '',
        amount: !(amt > 0) ? 'Enter the amount (more than 0).' : amt > 9999999.99 ? 'That is too large.' : '',
        date: !f.date ? 'Pick the date.' : f.date > this.today ? 'Not in the future.' : '',
        category: f.category ? '' : 'Pick a category.',
      };
    },
    async save() {
      if (!this.validateAll(['title', 'amount', 'date', 'category'])) return;
      const f = this.form;
      const body = { action: f.id ? 'update' : 'create', id: f.id, title: f.title.trim(), amount: Number(String(f.amount).replace(/,/g, '')), date: f.date, category: f.category,
        status: f.status, due: f.status === 'unpaid' ? f.due : '', note: f.note, merchant: f.merchant, receipt_no: f.receipt_no, payment_method: f.payment_method, receipt_image: f.receipt_image };
      const r = await Setlo.run(this, () => api.post('purchases.php', body));
      if (!r) return;
      this.form = null;
      Setlo.toast(f.id ? 'Purchase updated' : 'Purchase added', 'ok');
      this.month = f.date.slice(0, 7); // show the month it landed in
      this.cat = '';
      if (this.tab !== 'journal') this.tab = f.status === 'unpaid' && !f.id ? 'unpaid' : 'purchases';
      await this.changed();
    },
    async remove() {
      if (!(await Setlo.confirm({ title: 'Delete this purchase?', text: this.form.title, confirmText: 'Delete', danger: true }))) return;
      const r = await Setlo.run(this, () => api.post('purchases.php', { action: 'delete', id: this.form.id }));
      if (r) { this.form = null; Setlo.toast('Deleted', 'ok'); await this.changed(); }
    },
    async reopen() {
      const r = await Setlo.run(this, () => api.post('purchases.php', { action: 'reopen', id: this.form.id }));
      if (r) { this.form = null; Setlo.toast('Moved back to “to pay”', 'ok'); await this.changed(); }
    },
    openSettle(p) { this.settling = { id: p.id, title: p.title, amount: p.amount, date: this.today, method: p.payment_method || '' }; },
    async settle() {
      const s = this.settling;
      const r = await Setlo.run(this, () => api.post('purchases.php', { action: 'settle', id: s.id, date: s.date, payment_method: s.method }));
      if (r) { this.settling = null; Setlo.toast('Marked as paid', 'ok'); await this.changed(); }
    },

    /** A receipt photo, read by the scanner; the result fills a new purchase to confirm */
    async takePhoto() {
      this.scanOpen = false;
      const [file] = await Setlo.captureCamera({ max: 1, title: 'Scan a receipt' });
      if (file) await this.scanFile(file);
    },
    async scan(e) {
      const file = e.target.files[0];
      e.target.value = '';
      this.scanOpen = false;
      if (file) await this.scanFile(file);
    },
    async scanFile(file) {
      if (!file.type.startsWith('image/')) { Setlo.toast('Choose a photo of a receipt.'); return; }
      this.scanning = true;
      try {
        const fd = new FormData();
        fd.append('action', 'scan');
        fd.append('image', await Setlo.compressImage(file), 'receipt.jpg');
        const r = await api.post('purchases.php', fd);
        if (r.not_receipt) { Setlo.toast("That doesn't look like a receipt. Try a clearer, flat photo."); return; }
        this.resetValidation();
        this.form = blank({ title: r.title, amount: String(r.amount), date: r.date, merchant: r.merchant, receipt_no: r.receipt_no, receipt_image: r.receipt_image,
          category: this.cat || '', scanned: true, items: r.items, other_currency: r.other_currency });
      } catch (err) { Setlo.toast(err.message); }
      finally { this.scanning = false; }
    },
  },
});
</script>
<?php require __DIR__ . '/../partials/foot.php'; ?>
