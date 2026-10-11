<?php
require __DIR__ . '/../includes/bootstrap.php';
$admin = require_admin();
$title = 'Dispute Queue';
$subtitle = "Setlo's dispute path is intentionally simple: view context, and nudge both sides to resolve.";
$adminNav = 'disputes';
$loginPage = 'admin-login';
require __DIR__ . '/../partials/head.php';
require __DIR__ . '/../partials/admin-top.php';
?>
<div id="app" class="space-y-4 p-5 md:p-8" v-cloak>
  <spinner v-if="loading"></spinner>
  <div v-else-if="!items.length" class="card p-8 text-center text-sm text-slate-400">No open disputes. 🎉</div>

  <div v-for="s in items" :key="s.id" class="card border-red-200 p-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div class="flex items-center gap-3">
        <avatar :user="s.from" :size="36"></avatar>
        <svg class="h-4 w-4 text-slate-300" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
        <avatar :user="s.to" :size="36"></avatar>
        <div>
          <p class="text-sm font-semibold">{{ s.from.name }} → {{ s.to.name }}</p>
          <p class="text-xs text-slate-400">{{ s.bill_name }} · {{ peso(s.amount, s.currency) }}</p>
        </div>
      </div>
      <status-pill status="disputed"></status-pill>
    </div>
    <div class="mt-3 rounded-lg bg-red-50 px-3.5 py-2.5">
      <p class="text-xs text-red-700"><b>{{ s.to.first }}'s reason:</b> “{{ s.dispute_reason }}”</p>
    </div>
    <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
      <p class="text-[11px] text-slate-400">Marked paid {{ s.paid_at ? fmtDateTime(s.paid_at) : '—' }} · Disputed {{ fmtDateTime(s.disputed_at) }}</p>
      <div class="flex gap-2">
        <a :href="'admin-bills.php'" class="btn btn-outline btn-sm">View Bills</a>
        <button @click="message(s)" class="btn btn-ghost btn-sm" :disabled="busy || s.messaged">{{ s.messaged ? 'Messaged ✓' : 'Message Both' }}</button>
      </div>
    </div>
  </div>

  <div class="card bg-slate-50 p-4">
    <p class="text-xs text-slate-500"><b class="text-slate-700">Acknowledged limitation:</b> Setlo does not move funds or auto-resolve disputes — the app manager can only view context and prompt both parties; final resolution happens between them (the sender reopens the payment, the receiver confirms).</p>
  </div>
</div>

<script>
Setlo.mount({
  data: () => ({ loading: true, busy: false, items: [] }),
  async mounted() {
    await Setlo.load(this, 'admin.php', { view: 'disputes' }, (r) => { this.items = r.disputes; });
  },
  methods: {
    async message(s) {
      const text = await Setlo.promptText({
        title: 'Message both parties',
        text: 'Sent to ' + s.from.first + ' and ' + s.to.first + '. Leave blank to send the standard follow-up.',
        placeholder: 'e.g. Please share the GCash reference number so this can be confirmed.',
        validate: (v) => (v.length > 280 ? 'Keep it to 280 characters or fewer.' : (/[<>]/.test(v) ? 'Please don’t use < or >.' : '')),
      });
      if (text === null) return;
      const r = await Setlo.run(this, () => api.post('admin.php', { action: 'message_both', id: s.id, message: text }));
      if (r) { s.messaged = true; Setlo.toast('Both parties notified', 'ok'); }
    },
  },
});
</script>
<?php require __DIR__ . '/../partials/admin-bottom.php'; ?>
<?php require __DIR__ . '/../partials/foot.php'; ?>
