<?php
require __DIR__ . '/../includes/bootstrap.php';
$admin = require_admin();
$title = 'Platform Overview';
$subtitle = 'Snapshot as of ' . date('M j, Y, g:i A');
$adminNav = 'overview';
$loginPage = 'admin-login';
require __DIR__ . '/../partials/head.php';
require __DIR__ . '/../partials/admin-top.php';
?>
<div id="app" class="space-y-6 p-5 md:p-8" v-cloak>
  <spinner v-if="loading"></spinner>
  <template v-else>
    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
      <div class="card p-4">
        <p class="text-xs text-slate-500">Total Users</p>
        <p class="mt-1 text-2xl font-bold text-ink">{{ d.users.toLocaleString() }}</p>
        <p class="mt-1 text-[11px] text-emerald-600">▲ {{ d.users_week }} this week</p>
      </div>
      <div class="card p-4">
        <p class="text-xs text-slate-500">Open Bills</p>
        <p class="mt-1 text-2xl font-bold text-ink">{{ (d.bills.draft || 0) + (d.bills.active || 0) + (d.bills.settling || 0) }}</p>
        <p class="mt-1 text-[11px] text-slate-400">{{ d.bills.settling || 0 }} in Settling · {{ d.bills.closed || 0 }} closed</p>
      </div>
      <div class="card p-4">
        <p class="text-xs text-slate-500">Settled Volume (30d)</p>
        <p class="mt-1 text-2xl font-bold text-ink">{{ pesoShort(d.settled_volume) }}</p>
        <p class="mt-1 text-[11px] text-slate-400">receiver-confirmed only</p>
      </div>
      <div class="card p-4" :class="{ '!border-red-200': d.settlements.disputed }">
        <p class="text-xs text-slate-500">Open Disputes</p>
        <p class="mt-1 text-2xl font-bold" :class="d.settlements.disputed ? 'text-red-600' : 'text-ink'">{{ d.settlements.disputed || 0 }}</p>
        <a href="admin-disputes" class="mt-1 inline-block text-[11px] font-semibold text-red-600">Review now →</a>
      </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
      <div class="card p-5 lg:col-span-2">
        <div class="mb-3 flex items-center justify-between">
          <h2 class="text-sm font-bold text-slate-800">Settlement Status Breakdown</h2>
          <span class="text-xs text-slate-400">All time · {{ settleTotal }} settlements</span>
        </div>
        <div class="space-y-3">
          <div v-for="row in breakdown" :key="row.key">
            <div class="mb-1 flex justify-between text-xs"><span class="font-medium text-slate-600">{{ row.label }}</span><span class="text-slate-400">{{ d.settlements[row.key] || 0 }}</span></div>
            <div class="h-2 rounded-full bg-slate-100"><div class="h-2 rounded-full" :class="row.color" :style="{ width: pct(d.settlements[row.key]) + '%' }"></div></div>
          </div>
        </div>
      </div>
      <div class="card p-5">
        <h2 class="mb-3 text-sm font-bold text-slate-800">OCR Health</h2>
        <div class="mb-3 flex items-center gap-3">
          <div class="flex h-14 w-14 items-center justify-center rounded-full border-4 border-brand-500 text-sm font-bold text-brand-700">{{ accuracy === null ? '—' : accuracy + '%' }}</div>
          <div>
            <p class="text-sm font-semibold text-slate-800">Auto-read accuracy</p>
            <p class="text-xs text-slate-400">Based on {{ d.ocr_items }} scanned line items</p>
          </div>
        </div>
        <p class="text-xs text-slate-500">{{ d.ocr_corrected }} of {{ d.ocr_items }} OCR line items needed manual correction on the Review screen.</p>
      </div>
    </div>

    <div class="card overflow-x-auto p-5">
      <div class="mb-3 flex items-center justify-between">
        <h2 class="text-sm font-bold text-slate-800">Recent Bills Across the Platform</h2>
        <a href="admin-bills" class="text-xs font-semibold text-brand-600">View all →</a>
      </div>
      <table class="w-full min-w-[520px] text-sm">
        <thead><tr class="border-b border-slate-100 text-left text-xs text-slate-400">
          <th class="pb-2 font-medium">Bill</th><th class="pb-2 font-medium">Creator</th><th class="pb-2 font-medium">Members</th><th class="pb-2 font-medium">Total</th><th class="pb-2 font-medium">Status</th>
        </tr></thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-for="b in d.recent" :key="b.id">
            <td class="py-2.5 font-medium">{{ b.name }}</td><td class="text-slate-500">{{ b.creator }}</td><td class="text-slate-500">{{ b.members }}</td>
            <td class="font-medium">{{ peso(b.total, b.currency) }}</td><td><status-pill :status="b.status"></status-pill></td>
          </tr>
        </tbody>
      </table>
    </div>
  </template>
</div>

<script>
Setlo.mount({
  data: () => ({
    loading: true, d: {},
    breakdown: [
      { key: 'settled', label: 'Settled', color: 'bg-emerald-500' },
      { key: 'awaiting', label: 'Awaiting Confirmation', color: 'bg-blue-500' },
      { key: 'pending', label: 'Pending', color: 'bg-amber-400' },
      { key: 'disputed', label: 'Disputed', color: 'bg-red-500' },
    ],
  }),
  computed: {
    settleTotal() { return Object.values(this.d.settlements || {}).reduce((a, b) => a + b, 0); },
    accuracy() { return this.d.ocr_items ? Math.round(100 * (1 - this.d.ocr_corrected / this.d.ocr_items)) : null; },
  },
  async mounted() { await Setlo.load(this, 'admin.php', { view: 'overview' }, (r) => { this.d = r; }); },
  methods: { pct(n) { return this.settleTotal ? Math.max(n ? 1 : 0, Math.round((100 * (n || 0)) / this.settleTotal)) : 0; } },
});
</script>
<?php require __DIR__ . '/../partials/admin-bottom.php'; ?>
<?php require __DIR__ . '/../partials/foot.php'; ?>
