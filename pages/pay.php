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
    <a href="my-settlements?tab=owe" class="btn btn-primary mt-5">Go to what I owe</a>
    <a href="dashboard" class="btn btn-soft mt-5">Go to dashboard</a>
  </div>

  <div v-else class="flex-1 space-y-4 px-5 pb-6 pt-5">
    <!-- Back from PayMongo -->
    <div v-if="confirming" class="tile p-5 text-center">
      <spinner></spinner>
      <p class="text-[14px] font-extrabold text-ink">Confirming your payment…</p>
      <p class="mt-1 text-[12px] text-slate-400">Checking with PayMongo. Please don’t close this page.</p>
    </div>
    <div v-else-if="done" class="pop-in tile !border-emerald-300 !bg-emerald-50 p-5 text-center">
      <p class="text-[44px]">✅</p>
      <p class="text-[19px] font-extrabold text-ink">All done!</p>
      <p class="mt-1 text-[13px] text-slate-600">You paid {{ p.first }} {{ peso(done.total, 'PHP') }} via {{ done.via }}. It’s counted automatically and {{ p.first }} has been told.</p>
      <div class="mt-3 divide-y divide-emerald-200 rounded-xl bg-white/70 px-3 text-left">
        <div v-for="d in done.debts" :key="d.payment_id" class="flex items-center justify-between gap-3 py-2.5">
          <div class="min-w-0">
            <p class="truncate text-[13px] font-semibold text-slate-700">{{ d.bill }}</p>
            <button type="button" @click="showReceipt(d.payment_id)" class="text-[11.5px] font-bold text-brand-700">View receipt</button>
          </div>
          <p class="shrink-0 text-[14px] font-extrabold text-emerald-700">{{ peso(d.amount, 'PHP') }}</p>
        </div>
      </div>
      <a href="my-settlements" class="btn btn-primary mt-4 w-full">Back to My Settlements</a>
    </div>
    <div v-else-if="testDone" class="pop-in tile !border-emerald-300 !bg-emerald-50 p-5 text-center">
      <p class="text-[44px]">✅</p>
      <p class="text-[19px] font-extrabold text-ink">Test payment complete</p>
      <p class="mt-1 text-[13px] text-slate-600">That’s how it looks when a friend pays you: {{ peso(testDone.amount, 'PHP') }} via {{ testDone.via }}<span v-if="testDone.detail"> ({{ testDone.detail }})</span>. Test mode: no real money moved.</p>
      <div class="mt-4 grid grid-cols-2 gap-2.5">
        <button type="button" @click="testPay" class="btn btn-outline" :disabled="busy">Try again</button>
        <a href="payment-methods" class="btn btn-primary">My payment methods</a>
      </div>
    </div>
    <div v-else-if="testNotYet" class="tile !border-amber-300 !bg-amber-50 p-4 text-center">
      <p class="text-[14px] font-extrabold text-ink">The test checkout wasn’t completed</p>
      <p class="mt-1 text-[12px] text-slate-600">{{ testNotYet }}</p>
      <button type="button" @click="checkTest" class="btn btn-primary btn-sm mt-3" :disabled="busy">Check again</button>
    </div>
    <div v-else-if="notYet" class="tile !border-amber-300 !bg-amber-50 p-4 text-center">
      <p class="text-[14px] font-extrabold text-ink">We haven’t received your payment yet</p>
      <p class="mt-1 text-[12px] text-slate-600">{{ notYet }}</p>
      <button type="button" @click="checkOnline(false)" class="btn btn-primary btn-sm mt-3" :disabled="busy">Check again</button>
    </div>

    <div v-if="!done" class="tile p-5 text-center">
      <div class="flex justify-center"><avatar :user="p" :size="64" ring></avatar></div>
      <p class="mt-3 text-[18px] font-extrabold text-ink">{{ p.name }}</p>
      <p v-if="isMe" class="mt-1 text-[13px] text-slate-500">This is how friends see your pay page.</p>

      <div class="mt-4 rounded-2xl bg-brand-50 px-4 py-3 text-left">
        <p class="flex items-center gap-2 text-[11px] font-bold uppercase tracking-wide text-brand-700"><img v-if="payLogo(p.payment_method)" class="pay-logo" :src="payLogo(p.payment_method)" alt="" width="24" height="24" />Pay via {{ p.payment_method }}</p>
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

        <!-- Pay everything in one PayMongo checkout; counted automatically once PayMongo confirms -->
        <template v-if="online.enabled && online.total >= online.min">
          <div class="mt-4 flex items-center gap-2">
            <p class="flex-1 text-[13px] font-extrabold text-ink">Pay online</p>
            <span v-if="online.test" class="pill pill-draft">Demo · test mode</span>
          </div>
          <button type="button" @click="payOnline" class="btn btn-primary mt-2 w-full" :disabled="busy || confirming">Pay {{ peso(online.total, 'PHP') }} with PayMongo</button>
          <p class="mt-1.5 text-center text-[11.5px] leading-snug text-slate-400">GCash, Maya, card or QR Ph. It’s counted automatically once PayMongo confirms, so {{ p.first }} doesn’t need to.</p>
          <p v-if="online.total < owePhp" class="mt-1.5 text-center text-[11.5px] leading-snug text-slate-400">{{ peso(owePhp - online.total, 'PHP') }} of this is waiting for {{ p.first }} to confirm or can’t be paid online. Settle it in My Settlements.</p>
          <button v-if="online.started" type="button" @click="checkOnline(false)" class="mt-2 w-full text-center text-[12.5px] font-bold text-brand-700" :disabled="busy || confirming">Already paid? Check payment status</button>
          <a href="my-settlements" class="mt-3 block text-center text-[12.5px] font-bold text-slate-500 hover:text-brand-700">Sent it another way? Mark as paid in My Settlements</a>
        </template>
        <template v-else>
          <p v-if="online.enabled && online.total > 0" class="mt-3 text-[12px] text-slate-400">Online payment needs at least {{ peso(online.min, 'PHP') }}. Send it to {{ p.first }} directly, then mark it paid.</p>
          <a href="my-settlements" class="btn btn-primary mt-4 w-full">Mark as paid in My Settlements</a>
        </template>
      </template>
    </div>

    <!-- "Sent" confirmation after paying someone through their QR -->
    <div v-if="sent" class="pop-in tile !border-emerald-300 !bg-emerald-50 p-5 text-center">
      <p class="text-[44px]">✅</p>
      <p class="text-[19px] font-extrabold text-ink">Sent!</p>
      <p class="mt-1 text-[13px] text-slate-600">You sent {{ p.first }} {{ peso(sent.amount, 'PHP') }} via {{ sent.via }}<span v-if="sent.note"> — “{{ sent.note }}”</span>. {{ p.first }} has been told.</p>
      <p v-for="d in sent.debts" :key="d.bill" class="mt-1 text-[12.5px] font-semibold text-emerald-700">{{ peso(d.amount, 'PHP') }} counted toward {{ d.bill }}<span v-if="d.left > 0"> · {{ peso(d.left, 'PHP') }} left</span><span v-else> · paid in full</span></p>
      <p v-if="sent.applied > 0 && sent.left_over > 0" class="mt-1 text-[12.5px] text-slate-500">The other {{ peso(sent.left_over, 'PHP') }} went to {{ p.first }}’s wallet.</p>
      <button type="button" class="btn btn-primary mt-4 w-full" @click="sent = null">Done</button>
    </div>
    <div v-else-if="sendNotYet" class="tile !border-amber-300 !bg-amber-50 p-4 text-center">
      <p class="text-[14px] font-extrabold text-ink">We haven’t received your payment yet</p>
      <p class="mt-1 text-[12px] text-slate-600">{{ sendNotYet }}</p>
      <button type="button" @click="checkSent" class="btn btn-primary btn-sm mt-3" :disabled="busy || confirming">Check again</button>
    </div>

    <!-- Send any amount, even with no debt -->
    <div v-if="!isMe && online.enabled && !sent" class="tile p-4">
      <div class="flex items-center gap-2">
        <p class="flex-1 text-[14px] font-extrabold text-ink">Send money to {{ p.first }}</p>
        <span v-if="online.test" class="pill pill-draft">Demo · test mode</span>
      </div>
      <p class="mt-0.5 text-[12px] text-slate-400">Any amount, even if you don’t owe anything. {{ owePhp > 0 ? 'It counts toward what you owe ' + p.first + ' first. ' : '' }}Paid online through PayMongo.</p>
      <div class="relative mt-3">
        <span class="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 font-bold text-slate-400">₱</span>
        <input v-model="amount" @input="amount = filterMoney($event.target.value); $event.target.value = amount" inputmode="decimal" placeholder="0.00" class="input-soft !pl-8 text-[16px] font-bold" :class="{ 'is-invalid': amountErr }" aria-label="Amount to send" />
      </div>
      <div class="mt-2 flex flex-wrap gap-2">
        <button v-for="v in [100, 200, 500]" :key="v" type="button" @click="amount = String(v)" class="rounded-full bg-slate-100 px-3 py-1 text-[12px] font-bold text-slate-600">₱{{ v }}</button>
        <button v-if="owePhp >= online.send.min" type="button" @click="amount = owePhp.toFixed(2)" class="rounded-full bg-brand-50 px-3 py-1 text-[12px] font-bold text-brand-700">What I owe {{ peso(owePhp, 'PHP') }}</button>
      </div>
      <p v-if="amountErr" class="field-error">{{ amountErr }}</p>
      <input v-model="note" maxlength="100" placeholder="Note (optional), e.g. lunch" class="input-soft mt-3" aria-label="Note" />
      <button type="button" @click="sendMoney" class="btn btn-primary mt-3 w-full" :disabled="busy || confirming || !sendOk">{{ sendOk ? 'Send ' + peso(sendCents / 100, 'PHP') + ' with PayMongo' : 'Enter an amount' }}</button>
      <p class="mt-1.5 text-center text-[11.5px] leading-snug text-slate-400">{{ peso(online.send.min, 'PHP') }} to {{ peso(online.send.max, 'PHP') }}.<span v-if="owePhp > 0"> Because you owe {{ p.first }}, what you send comes off what you owe first.</span></p>
      <button v-if="online.send.started" type="button" @click="checkSent" class="mt-2 w-full text-center text-[12.5px] font-bold text-brand-700" :disabled="busy || confirming">Already paid? Check payment status</button>
    </div>

    <!-- My own page: try what friends see with a PayMongo test checkout (test keys only) -->
    <div v-if="isMe && online.test_checkout" class="tile p-4 text-center">
      <div class="flex items-center justify-center gap-2">
        <p class="text-[14px] font-extrabold text-ink">Try PayMongo</p>
        <span class="pill pill-draft">Demo · test mode</span>
      </div>
      <p class="mt-1 text-[12px] leading-snug text-slate-500">Opens a PayMongo test checkout for {{ peso(online.min, 'PHP') }} so you can see what friends see. No real money moves.</p>
      <button type="button" @click="testPay" class="btn btn-primary mt-3 w-full" :disabled="busy || confirming">Continue with PayMongo</button>
    </div>
    <a v-if="isMe && !online.test_checkout" href="payment-methods" class="btn btn-outline w-full">Back to my payment methods</a>
  </div>

  <?php require __DIR__ . '/../partials/nav.php'; ?>
</div>

<script>
Setlo.mount({
  data: () => ({
    code: <?= json_encode($code) ?>, loading: true, busy: false, error: '', p: null, isMe: false, owe: [], oweTotals: {},
    online: { enabled: false, test: false, min: 20, total: 0, started: false, send: { min: 20, max: 50000, started: false } },
    confirming: false, done: null, notYet: '', testDone: null, testNotYet: '',
    amount: '', note: '', amountErr: '', sent: null, sendNotYet: '',
  }),
  computed: {
    /** What I owe in pesos, i.e. what the online checkout could cover at most. */
    owePhp() { return this.oweTotals.PHP || 0; },
    sendCents() { return Math.round((parseFloat(this.amount) || 0) * 100); },
    sendOk() { return this.sendCents >= this.online.send.min * 100 && this.sendCents <= this.online.send.max * 100; },
  },
  watch: {
    amount() { this.amountErr = ''; },
  },
  async mounted() {
    try {
      await this.load();
    } catch (e) {
      this.error = e.message;
    } finally {
      this.loading = false;
    }
    // Back from PayMongo's checkout page: the URL only says it was left, so ask PayMongo (never trust the URL).
    const url = new URL(location.href), back = url.searchParams.get('online'), sentBack = url.searchParams.get('sent'), testBack = url.searchParams.get('test');
    if ((!back && !sentBack && !testBack) || this.error) return;
    url.searchParams.delete('online');
    url.searchParams.delete('sent');
    url.searchParams.delete('test');
    history.replaceState(null, '', url.pathname + url.search);
    if (back === 'cancelled' || sentBack === 'cancelled' || testBack === 'cancelled') { Setlo.toast('Online payment cancelled. Nothing was charged.'); return; }
    if (testBack) { await this.checkTest(); return; }
    if (sentBack) await this.checkSent();
    else await this.checkOnline(true);
  },
  methods: {
    payLogo(method) { return (window.SETLO_PAY_LOGOS || {})[method] || ''; },
    async load() {
      const r = await api.get('pay.php', { u: this.code });
      this.p = r.person;
      this.isMe = r.is_me;
      this.owe = r.owe;
      this.oweTotals = r.owe_totals || {};
      this.online = r.online || this.online;
    },
    showReceipt(id) { Setlo.showReceipt(id); },
    filterMoney: Setlo.filterMoney,
    /** Send any amount (no debt) through a PayMongo checkout. */
    async sendMoney() {
      if (!this.sendOk) { this.amountErr = 'Send between ' + this.peso(this.online.send.min, 'PHP') + ' and ' + this.peso(this.online.send.max, 'PHP') + '.'; return; }
      try {
        const r = await Setlo.run(this, () => api.post('pay.php', { action: 'send_start', u: this.code, amount: this.sendCents / 100, note: this.note.trim() }));
        if (r) location.href = r.checkout_url;
      } catch (e) { this.amountErr = e.message; }
    },
    /** Ask PayMongo whether the money I sent was paid; if so "Sent" shows. */
    /** The owner's test checkout: ₱20.00 on PayMongo's test mode, to see what friends see. Nothing is recorded. */
    async testPay() {
      this.testDone = null;
      this.testNotYet = '';
      try {
        const r = await Setlo.run(this, () => api.post('pay.php', { action: 'test_start', u: this.code }));
        if (r) location.href = r.checkout_url;
      } catch (e) { Setlo.toast(e.message); }
    },
    async checkTest() {
      this.confirming = true;
      this.testNotYet = '';
      try {
        const r = await api.post('pay.php', { action: 'test_check', u: this.code });
        if (r.paid) this.testDone = r; else this.testNotYet = 'PayMongo hasn’t received a payment yet. If you paid, give it a few seconds and check again.';
      } catch (e) {
        if (e.status === 409) this.testNotYet = e.message; else Setlo.toast(e.message);
      } finally {
        this.confirming = false;
      }
    },
    async checkSent() {
      this.confirming = true;
      this.sendNotYet = '';
      try {
        this.sent = await api.post('pay.php', { action: 'send_check', u: this.code });
        this.amount = '';
        this.note = '';
        await this.load();
      } catch (e) {
        if (e.status === 409) this.sendNotYet = e.message;
        else Setlo.toast(e.message);
      } finally {
        this.confirming = false;
      }
    },
    /** All my debts to this person that can be paid online go into one PayMongo checkout. */
    async payOnline() {
      const r = await Setlo.run(this, () => api.post('pay.php', { action: 'pay_online', u: this.code }));
      if (r) location.href = r.checkout_url;
    },
    /** Ask PayMongo whether the checkout was paid; if so everything it covered is counted and "All done" shows. */
    async checkOnline(auto) {
      this.confirming = true;
      this.notYet = '';
      try {
        const r = await api.post('pay.php', { action: 'check_online', u: this.code });
        this.done = r;
        await this.load();
      } catch (e) {
        if (e.status === 409) this.notYet = e.message;
        else Setlo.toast(e.message);
      } finally {
        this.confirming = false;
      }
    },
    async copy() {
      try { await navigator.clipboard.writeText(this.p.payment_account); Setlo.toast('Copied ' + this.p.payment_method + ' number', 'ok'); }
      catch (e) { Setlo.showCopy('Copy the number', this.p.payment_account); }
    },
  },
});
</script>
<?php require __DIR__ . '/../partials/foot.php'; ?>
