<?php
// Opened by scanning someone's personal "Pay me" QR: pay.php?u=CODE
require __DIR__ . '/../includes/bootstrap.php';

$code = preg_replace('/[^A-Za-z0-9]/', '', (string) ($_GET['u'] ?? ''));
$user = current_user();
if (!$user) {
    redirect('pages/login?next=' . urlencode('pay?u=' . $code));
}
if ($user['role'] === 'admin') {
    redirect('pages/admin-dashboard');
}
$title = 'Pay';
$nav = 'settle';
$back = 'my-settlements.php';
require __DIR__ . '/../partials/head.php';
?>
<div id="app" class="device device-narrow" v-cloak>
  <div class="app-hero px-5 pb-6 pt-8">
    <div class="flex items-center gap-3">
      <?php require __DIR__ . '/../partials/back.php'; ?>
      <h1 class="flex-1 text-[21px] font-extrabold tracking-tight">{{ isMe ? 'Your Pay-me QR' : 'Pay a friend' }}</h1>
    </div>
  </div>

  <spinner v-if="loading"></spinner>

  <div v-else-if="error" class="flex-1 px-5 py-10 text-center">
    <p class="text-[16px] font-extrabold text-ink">QR not valid</p>
    <p class="mt-1 text-[13px] text-slate-500">{{ error }}</p>
    <a href="dashboard" class="btn btn-primary mt-5">Go to dashboard</a>
  </div>

  <div v-else class="flex-1 space-y-4 px-5 pb-6 pt-5">
    <div class="tile p-5 text-center">
      <div class="flex justify-center"><avatar :user="p" :size="64" ring></avatar></div>
      <p class="mt-3 text-[18px] font-extrabold text-ink">{{ p.name }}</p>
      <p v-if="isMe" class="mt-1 text-[13px] text-slate-500">This is how friends see your pay page.</p>

      <div class="mt-4 rounded-2xl bg-brand-50 px-4 py-3 text-left">
        <p class="text-[11px] font-bold uppercase tracking-wide text-brand-700">Pay via {{ p.payment_method }}</p>
        <div class="mt-1 flex items-center justify-between gap-3">
          <p class="break-all text-[16px] font-extrabold text-ink">{{ p.payment_account || (p.payment_method === 'Cash' ? 'Pay in cash when you meet' : 'No account number added yet') }}</p>
          <button v-if="p.payment_account" @click="copy" class="btn btn-outline btn-sm shrink-0">Copy</button>
        </div>
      </div>
      <p v-if="p.payment_method !== 'Cash' && p.payment_account" class="mt-3 text-[12px] text-slate-500">
        Open {{ p.payment_method }}, send the amount to this number, then mark it as paid in Setlo so {{ p.first }} can confirm.
      </p>
    </div>

    <div v-if="!isMe" class="tile p-4">
      <p class="text-[14px] font-extrabold text-ink">What you owe {{ p.first }}</p>
      <p v-if="!owe.length" class="mt-2 text-[13px] text-slate-400">Nothing right now — you're all settled up. 🎉</p>
      <template v-else>
        <div class="mt-3 divide-y divide-slate-100">
          <div v-for="s in owe" :key="s.id" class="flex items-center justify-between gap-3 py-2.5">
            <div class="min-w-0">
              <p class="truncate text-[13px] font-semibold text-slate-700">{{ s.bill_name }}</p>
              <status-pill :status="s.status"></status-pill>
            </div>
            <p class="shrink-0 text-[14px] font-extrabold text-ink">{{ peso(s.amount, s.currency) }}</p>
          </div>
        </div>
        <div class="mt-3 flex items-center justify-between border-t border-slate-100 pt-3">
          <span class="text-[13px] font-bold text-slate-600">Total</span>
          <span class="text-[17px] font-extrabold text-rose-500">{{ Object.entries(oweTotals).map(([c, v]) => peso(v, c)).join(" + ") }}</span>
        </div>
        <a href="my-settlements" class="btn btn-primary mt-4 w-full">Mark as paid in My Settlements</a>
      </template>
    </div>

    <a v-if="isMe" href="profile" class="btn btn-outline w-full">Back to my profile</a>
  </div>

  <?php require __DIR__ . '/../partials/nav.php'; ?>
</div>

<script>
Setlo.mount({
  data: () => ({ code: <?= json_encode($code) ?>, loading: true, error: '', p: null, isMe: false, owe: [], oweTotals: {} }),
  async mounted() {
    try {
      const r = await api.get('pay.php', { u: this.code });
      this.p = r.person;
      this.isMe = r.is_me;
      this.owe = r.owe;
      this.oweTotals = r.owe_totals || {};
    } catch (e) {
      this.error = e.message;
    } finally {
      this.loading = false;
    }
  },
  methods: {
    async copy() {
      try { await navigator.clipboard.writeText(this.p.payment_account); Setlo.toast('Copied ' + this.p.payment_method + ' number', 'ok'); }
      catch (e) { Setlo.showCopy('Copy the number', this.p.payment_account); }
    },
  },
});
</script>
<?php require __DIR__ . '/../partials/foot.php'; ?>
