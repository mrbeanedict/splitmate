<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_login();
// Financial Activity: an overview of your share of every bill, and (toggle) the settled bills it came from.
// ?tab=history opens the settlement history (the old Spending and Bill History pages redirect here).
$title = 'Financial Activity';
$nav = 'activity';
$headExtra = ['assets/css/stats-charts.css'];
$back = 'dashboard.php';
require __DIR__ . '/../partials/head.php';
?>
<div id="app" class="device" v-cloak>

  <div class="app-hero px-5 pb-5 pt-8">
    <div class="flex items-center gap-3">
      <?php require __DIR__ . '/../partials/back.php'; ?>
      <div class="min-w-0">
        <h1 class="text-[21px] font-extrabold tracking-tight">Financial Activity</h1>
        <p class="text-[12px] font-medium text-brand-50/90">
          <template v-if="tab === 'overview'">Your share of every bill — not what the table paid</template>
          <template v-else-if="history">{{ history.length }} closed {{ history.length === 1 ? 'bill' : 'bills' }} · {{ historyTotal }} split in total</template>
          <template v-else>Bills that are fully settled</template>
        </p>
      </div>
    </div>
    <div class="seg mt-5" role="tablist" aria-label="Financial Activity view">
      <button role="tab" :aria-selected="tab === 'overview'" :class="{ on: tab === 'overview' }" @click="show('overview')">Spending</button>
      <button role="tab" :aria-selected="tab === 'history'" :class="{ on: tab === 'history' }" @click="show('history')">Settlement history</button>
    </div>
  </div>

  <!-- Past bills: closed bills by month (open one for its breakdown) -->
  <div v-if="tab === 'history'" class="flex-1 space-y-3 px-5 pb-6 pt-4">
    <spinner v-if="!history"></spinner>
    <div v-else-if="!history.length" class="tile p-6 text-center">
      <p class="text-sm font-bold text-ink">No closed bills yet</p>
      <p class="mt-1 text-[12px] text-slate-400">Bills move here once every payment is confirmed.</p>
    </div>
    <template v-for="g in historyGroups" :key="g.month">
      <p class="pt-2 text-xs font-semibold text-slate-400">{{ g.month }}</p>
      <a v-for="b in g.bills" :key="b.id" :href="b.link" class="tile flex items-center gap-3 p-3.5">
        <div class="initials initials-muted">{{ b.initials }}</div>
        <div class="min-w-0 flex-1">
          <p class="truncate text-sm font-semibold text-slate-800">{{ b.name }}</p>
          <p class="text-xs text-slate-400">{{ b.members }} members · Closed {{ fmtDate(b.closed_at) }}</p>
        </div>
        <p class="text-sm font-bold text-slate-700">{{ peso(b.total, b.currency) }}</p>
      </a>
    </template>
  </div>

  <spinner v-else-if="loading"></spinner>
  <div v-else class="flex-1 space-y-5 px-5 pb-6 pt-4 lg:grid lg:grid-cols-2 lg:items-start lg:gap-5 lg:space-y-0">

    <!-- Headline numbers -->
    <div class="grid grid-cols-2 gap-3 lg:col-span-2 lg:grid-cols-4">
      <div class="tile col-span-2 p-4">
        <p class="text-[12px] font-medium text-slate-500">This month</p>
        <p class="mt-0.5 text-[30px] font-extrabold leading-tight tracking-tight text-ink">{{ peso(s.this_month) }}</p>
        <p class="mt-1 text-[12px] font-semibold" :class="change === null ? 'text-slate-400' : change > 0 ? 'text-rose-500' : 'text-brand-600'">
          <template v-if="change === null">No spending last month to compare</template>
          <template v-else>{{ change > 0 ? '▲' : change < 0 ? '▼' : '' }} {{ Math.abs(change) }}% vs last month ({{ peso(s.last_month) }})</template>
        </p>
      </div>
      <div class="tile p-4">
        <p class="text-[12px] font-medium text-slate-500">Average per bill</p>
        <p class="mt-0.5 text-[19px] font-extrabold text-ink">{{ peso(s.average) }}</p>
        <p class="text-[11px] text-slate-400">{{ s.bills }} bills all-time</p>
      </div>
      <div class="tile p-4">
        <p class="text-[12px] font-medium text-slate-500">Saved with discounts</p>
        <p class="mt-0.5 text-[19px] font-extrabold text-ink">{{ peso(s.saved) }}</p>
        <p class="text-[11px] text-slate-400">Senior/PWD &amp; promos</p>
      </div>
    </div>

    <!-- Monthly bars -->
    <section class="tile p-4 lg:col-span-2">
      <div class="flex items-center justify-between">
        <h2 class="text-[14px] font-extrabold text-ink">Last 6 months</h2>
        <button @click="asTable = !asTable" class="text-[12px] font-bold text-brand-700">{{ asTable ? 'Show chart' : 'Show table' }}</button>
      </div>

      <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-[11.5px] text-slate-500">
        <span class="inline-flex items-center gap-1.5"><i class="inline-block h-2.5 w-2.5 rounded-sm bg-brand-600"></i>Bill shares</span>
        <span class="inline-flex items-center gap-1.5"><i class="inline-block h-2.5 w-2.5 rounded-sm" style="background:#f59e0b"></i>My purchases</span>
      </div>

      <table v-if="asTable" class="mt-3 w-full text-[13px]">
        <thead><tr class="text-left text-[11px] text-slate-400"><th class="pb-1 font-medium">Month</th><th class="pb-1 text-right font-medium">Bill shares</th><th class="pb-1 text-right font-medium">My purchases</th><th class="pb-1 text-right font-medium">Total</th></tr></thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-for="m in s.months" :key="m.month"><td class="py-1.5 text-slate-600">{{ monthLong(m.month) }}</td><td class="py-1.5 text-right text-slate-600">{{ peso(m.amount) }}</td><td class="py-1.5 text-right text-slate-600">{{ peso(m.solo) }}</td><td class="py-1.5 text-right font-semibold text-ink">{{ peso(m.amount + m.solo) }}</td></tr>
        </tbody>
      </table>

      <div v-else class="relative mt-4" @mouseleave="hover = null">
        <!-- Tooltip -->
        <div v-if="hover !== null" class="pointer-events-none absolute -top-2 z-10 -translate-x-1/2 -translate-y-full whitespace-nowrap rounded-lg bg-night px-2.5 py-1.5 text-[11px] font-semibold text-white shadow-lg"
             :style="{ left: ((hover + 0.5) / s.months.length * 100) + '%' }">
          {{ monthLong(s.months[hover].month) }} · Bills {{ peso(s.months[hover].amount) }} · Mine {{ peso(s.months[hover].solo) }}
        </div>
        <div class="flex h-40 items-end gap-2 border-b border-slate-200" role="img" :aria-label="'Monthly spending: ' + s.months.map((m) => monthShort(m.month) + ' bills ' + peso(m.amount) + ', purchases ' + peso(m.solo)).join('; ')">
          <button v-for="(m, i) in s.months" :key="m.month" class="group relative flex h-full flex-1 items-end focus:outline-none"
                  @mouseenter="hover = i" @focus="hover = i" @blur="hover = null" :aria-label="monthLong(m.month) + ': bills ' + peso(m.amount) + ', purchases ' + peso(m.solo)">
            <span class="flex w-full flex-col overflow-hidden rounded-t-[4px] transition-opacity group-hover:opacity-80 group-focus-visible:ring-2 group-focus-visible:ring-brand-300"
                  :style="{ height: m.amount + m.solo > 0 ? Math.max(2, (m.amount + m.solo) / maxMonth * 100) + '%' : '0' }">
              <span v-if="m.solo > 0" :style="{ flex: m.solo + ' 1 0', background: '#f59e0b' }"></span>
              <span v-if="m.amount > 0" class="bg-brand-600" :style="{ flex: m.amount + ' 1 0' }"></span>
            </span>
          </button>
        </div>
        <div class="mt-1.5 flex gap-2">
          <span v-for="(m, i) in s.months" :key="m.month" class="flex-1 text-center text-[11px]" :class="i === s.months.length - 1 ? 'font-bold text-ink' : 'text-slate-400'">{{ monthShort(m.month) }}</span>
        </div>
      </div>
    </section>

    <!-- My purchases: the personal expenses logged on the purchases page -->
    <section v-if="s.personal" class="tile p-4 lg:col-span-2">
      <div class="flex items-center justify-between">
        <h2 class="text-[14px] font-extrabold text-ink">My purchases</h2>
        <a href="purchases" class="text-[12px] font-bold text-brand-700">{{ s.personal.count ? 'Manage' : 'Add one' }} ›</a>
      </div>
      <template v-if="s.personal.count">
        <p class="mt-2 text-[12px] font-medium text-slate-500">Your own purchases this month, outside any bill</p>
        <p class="text-[24px] font-extrabold leading-tight tracking-tight text-ink">{{ peso(s.personal.this_month) }}</p>
        <p class="text-[11.5px] text-slate-400">Last month {{ peso(s.personal.last_month) }}</p>
        <ul v-if="s.personal.by_category.length" class="mt-3 divide-y divide-slate-100 text-[13px]">
          <li v-for="c in s.personal.by_category" :key="c.name" class="flex items-center justify-between py-1.5"><span class="text-slate-600">{{ c.name }}</span><span class="font-semibold text-ink">{{ peso(c.amount) }}</span></li>
        </ul>
      </template>
      <p v-else class="mt-2 text-[12px] text-slate-400">Log what you buy on your own and it shows up here.</p>
    </section>

    <!-- Where your money goes: share of spending per place, as a donut -->
    <section class="tile p-4">
      <div class="sc-head">
        <h2 class="text-[14px] font-extrabold text-ink">Where your money goes</h2>
        <button v-if="s.places.length" type="button" class="sc-toggle" @click="setView('places')">{{ view.places === 'chart' ? 'Show list' : 'Show chart' }}</button>
      </div>
      <p v-if="!s.places.length" class="mt-2 text-[12px] text-slate-400">Nothing yet.</p>
      <div v-else-if="view.places === 'chart'" class="sc-donut-layout">
        <div class="sc-donut-wrap">
          <svg class="sc-donut" viewBox="0 0 120 120" role="img" :aria-label="'Spending by place: ' + donut.rows.map((r) => r.name + ' ' + peso(r.amount) + ' (' + r.label + ')').join(', ')">
            <circle class="sc-ring-track" cx="60" cy="60" r="46" fill="none" stroke-width="16"></circle>
            <g transform="rotate(-90 60 60)">
              <circle v-for="(r, i) in donut.rows" :key="r.name" class="sc-arc" :class="{ dim: hoverPlace !== null && hoverPlace !== i }" cx="60" cy="60" r="46" fill="none" stroke-width="16"
                      :stroke="r.color" :stroke-dasharray="r.dash" :stroke-dashoffset="r.off" tabindex="0"
                      :aria-label="r.name + ': ' + peso(r.amount) + ', ' + r.label"
                      @mouseenter="hoverPlace = i" @mouseleave="hoverPlace = null" @focus="hoverPlace = i" @blur="hoverPlace = null"></circle>
            </g>
          </svg>
          <div class="sc-center" aria-hidden="true">
            <template v-if="hoverPlace === null"><small>Total spent</small><b>{{ peso(donut.total) }}</b></template>
            <template v-else><small>{{ donut.rows[hoverPlace].name }}</small><b>{{ peso(donut.rows[hoverPlace].amount) }}</b><small>{{ donut.rows[hoverPlace].label }}</small></template>
          </div>
        </div>
        <ul class="sc-legend">
          <li v-for="(r, i) in donut.rows" :key="r.name" :class="{ dim: hoverPlace !== null && hoverPlace !== i }" @mouseenter="hoverPlace = i" @mouseleave="hoverPlace = null">
            <i :style="{ background: r.color }"></i><span class="n" :title="r.name">{{ r.name }}</span><span class="a">{{ peso(r.amount) }}</span><span class="p">{{ r.label }}</span>
          </li>
        </ul>
      </div>
      <div v-else class="mt-3 space-y-3">
        <div v-for="row in s.places" :key="row.name">
          <div class="flex items-baseline justify-between gap-3 text-[13px]"><span class="truncate font-semibold text-slate-700">{{ row.name }}</span><span class="shrink-0 font-bold text-ink">{{ peso(row.amount) }}</span></div>
          <div class="mt-1 h-2 rounded-full bg-slate-100"><div class="h-2 rounded-full bg-brand-600" :style="{ width: Math.max(2, row.amount / s.places[0].amount * 100) + '%' }"></div></div>
        </div>
      </div>
    </section>

    <!-- What you order most: ranked bar chart -->
    <section class="tile p-4">
      <div class="sc-head">
        <h2 class="text-[14px] font-extrabold text-ink">What you order most</h2>
        <button v-if="s.items.length" type="button" class="sc-toggle" @click="setView('items')">{{ view.items === 'chart' ? 'Show list' : 'Show chart' }}</button>
      </div>
      <p v-if="!s.items.length" class="mt-2 text-[12px] text-slate-400">Nothing yet.</p>
      <div v-else-if="view.items === 'chart'" class="sc-bars" role="img" :aria-label="'What you order most: ' + itemBars.rows.map((r) => r.name + ' ' + peso(r.amount)).join(', ')" @mouseleave="hoverItem = null">
        <div class="sc-grid" aria-hidden="true"><i v-for="t in itemBars.ticks" :key="t.at" :style="{ left: t.at + '%' }"></i></div>
        <div v-for="(r, i) in itemBars.rows" :key="r.name" class="sc-row" :class="{ dim: hoverItem !== null && hoverItem !== i }">
          <span class="sc-rank" aria-hidden="true">{{ i + 1 }}</span>
          <span class="sc-name" :title="r.name">{{ r.name }}</span>
          <span class="sc-track">
            <button type="button" class="sc-bar" :class="{ top: i === 0 }" :style="{ width: r.w + '%', animationDelay: (i * 70) + 'ms' }" :aria-label="r.name + ': ' + peso(r.amount) + ', ' + r.label"
                    @mouseenter="hoverItem = i" @focus="hoverItem = i" @blur="hoverItem = null"></button>
            <b class="sc-val">{{ peso(r.amount) }}</b>
          </span>
        </div>
        <div class="sc-axis" aria-hidden="true"><span v-for="t in itemBars.ticks" :key="t.at" :style="{ left: t.at + '%' }">{{ t.label }}</span></div>
        <p class="sc-caption" aria-live="polite">{{ hoverItem === null ? 'Hover or tap a bar for details' : itemBars.rows[hoverItem].name + ' · ' + peso(itemBars.rows[hoverItem].amount) + ' · ' + itemBars.rows[hoverItem].label + ' of these items' }}</p>
      </div>
      <div v-else class="mt-3 space-y-3">
        <div v-for="row in s.items" :key="row.name">
          <div class="flex items-baseline justify-between gap-3 text-[13px]"><span class="truncate font-semibold text-slate-700">{{ row.name }}</span><span class="shrink-0 font-bold text-ink">{{ peso(row.amount) }}</span></div>
          <div class="mt-1 h-2 rounded-full bg-slate-100"><div class="h-2 rounded-full bg-brand-600" :style="{ width: Math.max(2, row.amount / s.items[0].amount * 100) + '%' }"></div></div>
        </div>
      </div>
    </section>

    <div class="tile grid grid-cols-2 divide-x divide-slate-100 py-3.5 text-center lg:col-span-2">
      <div><p class="text-[11px] text-slate-500">You've paid friends</p><p class="text-[15px] font-extrabold text-ink">{{ peso(s.paid_out) }}</p></div>
      <div><p class="text-[11px] text-slate-500">Friends paid you back</p><p class="text-[15px] font-extrabold text-ink">{{ peso(s.received) }}</p></div>
    </div>
    <p class="text-center text-[11px] text-slate-400 lg:col-span-2">Confirmed settlements only. Draft bills aren't counted.<template v-if="s.estimated"> Amounts in other currencies are estimated in {{ s.currency }} at today's rate.</template>
      <button @click="show('history')" class="font-bold text-brand-700">See past bills →</button></p>
  </div>

  <?php require __DIR__ . '/../partials/nav.php'; ?>
</div>

<script>
/** Chart or list for each card, remembered in this browser. */
const chartViews = () => {
  try { return { places: 'chart', items: 'chart', ...JSON.parse(localStorage.getItem('setlo-stats-view') || '{}') }; } catch (e) { return { places: 'chart', items: 'chart' }; }
};
const pctLabel = (frac) => { const v = Math.round(frac * 1000) / 10; return (v % 1 === 0 ? v.toFixed(0) : v.toFixed(1)) + '%'; };
Setlo.useCurrency(Setlo.homeCurrency); // spending is added up in my currency (api/stats.php)
Setlo.mount({
  data: () => ({
    loading: true, s: null, hover: null, asTable: false,
    tab: new URLSearchParams(location.search).get('tab') === 'history' ? 'history' : 'overview',
    history: null, historyLoading: false, // closed bills, loaded the first time Past bills is opened
    hoverPlace: null, hoverItem: null, view: chartViews(),
  }),
  computed: {
    /** Places as donut arcs: the top places plus "Other places", each with its share of the total. */
    donut() {
      const rows = this.s.places.map((p, i) => ({ name: p.name, amount: p.amount, color: 'var(--sc-' + (i + 1) + ')' }));
      if (this.s.places_other > 0) rows.push({ name: 'Other places', amount: this.s.places_other, color: 'var(--sc-other)' });
      const total = rows.reduce((a, r) => a + r.amount, 0);
      const C = 2 * Math.PI * 46;
      const gap = rows.length > 1 ? 1.5 : 0;
      let used = 0;
      return {
        total,
        rows: rows.map((r) => {
          const frac = total ? r.amount / total : 0;
          const len = Math.max(0.5, frac * C - gap);
          const arc = { ...r, label: pctLabel(frac), dash: len.toFixed(2) + ' ' + (C - len).toFixed(2), off: (-used).toFixed(2) };
          used += frac * C;
          return arc;
        }),
      };
    },
    /** Items as horizontal bars scaled to the biggest one, with gridlines and peso ticks along the bottom. */
    itemBars() {
      const items = this.s.items;
      const max = items.length ? items[0].amount : 1;
      const total = items.reduce((a, r) => a + r.amount, 0);
      const plot = 82; // percent of the track the bars may use; the amount is printed after the bar
      return {
        rows: items.map((r) => ({ ...r, w: Math.max(2, r.amount / max * plot), label: pctLabel(total ? r.amount / total : 0) })),
        ticks: [0, 0.25, 0.5, 0.75, 1].map((t) => ({ at: t * plot, label: t === 0 ? '0' : Setlo.pesoShort(max * t) })),
      };
    },
    maxMonth() { return Math.max(1, ...this.s.months.map((m) => m.amount + (m.solo || 0))); },
    change() {
      if (!this.s.last_month) return null;
      return Math.round(((this.s.this_month - this.s.last_month) / this.s.last_month) * 100);
    },
    /** Closed bills can be in different currencies: one sum per currency. */
    historyTotal() {
      const by = {};
      for (const b of this.history || []) by[b.currency || 'PHP'] = (by[b.currency || 'PHP'] || 0) + b.total;
      return Object.entries(by).map(([c, v]) => this.peso(v, c)).join(' + ') || this.peso(0);
    },
    historyGroups() {
      const out = [];
      for (const b of this.history || []) {
        const month = this.fmtMonth(b.closed_at || b.created_at);
        let g = out.find((x) => x.month === month);
        if (!g) out.push((g = { month, bills: [] }));
        g.bills.push(b);
      }
      return out;
    },
  },
  async mounted() {
    if (this.tab === 'history') this.loadHistory();
    await Setlo.load(this, 'stats.php', null, (r) => { this.s = r; });
  },
  methods: {
    setView(key) {
      this.view[key] = this.view[key] === 'chart' ? 'list' : 'chart';
      try { localStorage.setItem('setlo-stats-view', JSON.stringify(this.view)); } catch (e) { /* private mode */ }
    },
    /** Switch views; the URL keeps the choice, so Back from a past bill returns to the list. */
    show(tab) {
      this.tab = tab;
      window.history.replaceState(null, '', tab === 'history' ? '?tab=history' : location.pathname);
      if (tab === 'history' && !this.history) this.loadHistory();
      window.scrollTo(0, 0);
    },
    loadHistory() { return Setlo.load(this, 'bills.php', { scope: 'history' }, (r) => { this.history = r.bills; }, 'historyLoading'); },
    monthShort(k) { return new Date(k + '-01T00:00').toLocaleDateString('en-PH', { month: 'short' }); },
    monthLong(k) { return new Date(k + '-01T00:00').toLocaleDateString('en-PH', { month: 'long', year: 'numeric' }); },
  },
});
</script>
<?php require __DIR__ . '/../partials/foot.php'; ?>
