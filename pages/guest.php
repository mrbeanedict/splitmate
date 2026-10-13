<?php
// A guest's view of one bill, opened from the private link the Bill Creator copied for them: no account needed.
// Shows only their own share, the fees and discounts on it, and how the bill is moving towards settled. The mascot
// invites them to register, and registering (or signing in) from here takes their bill and payments along.
require __DIR__ . '/../includes/bootstrap.php';
$token = (string) ($_GET['t'] ?? '');
$title = 'Your share';
$bodyClass = 'guest-body';
$headExtra = ['assets/css/tour.css', 'assets/css/guest.css', 'assets/js/mascot-invite.js'];
require __DIR__ . '/../partials/head.php';
$mascot = fn (string $pose) => h(url('assets/mascot/' . $pose . '.png'));
?>
<div id="app" class="device device-wide device-solo" v-cloak>

  <div class="app-hero px-5 pb-16 pt-8">
    <img class="g-hero-mascot" src="<?= $mascot('thumbs') ?>" alt="" />
    <span class="g-hero-tag"><span v-html="icon('eye', 15)"></span> Guest view · no account needed</span>
    <template v-if="d">
      <p class="mt-3 text-[13px] font-medium text-brand-50/90">Hi {{ d.me.first }},</p>
      <h1 class="text-[22px] font-extrabold leading-tight tracking-tight">{{ d.bill.name }}</h1>
      <p class="mt-1 text-[13px] text-brand-50/90">Shared by {{ d.bill.creator }}</p>
    </template>
    <h1 v-else class="mt-3 text-[22px] font-extrabold leading-tight tracking-tight">Your bill</h1>
  </div>

  <div class="g-wrap">
    <!-- Loading -->
    <template v-if="loading"><div class="g-skel"></div><div class="g-skel"></div></template>

    <!-- Bad / used link -->
    <div v-else-if="error" class="g-card g-empty g-pop">
      <img src="<?= $mascot('think') ?>" alt="" />
      <h2>We can’t open this link</h2>
      <p class="g-muted">{{ error }}</p>
      <a href="login" class="btn btn-primary btn-sm">Sign in</a>
    </div>

    <template v-else-if="d">
      <div class="g-cols">
      <div class="g-col">
      <!-- What you owe -->
      <div class="g-card g-owe g-pop">
        <p class="g-label g-muted">{{ d.settlement && d.settlement.status === 'settled' ? 'You paid' : 'You owe' }}</p>
        <p class="g-amount" :class="{ 'is-rose': owe > 0 && !settled }">{{ peso(owe, d.bill.currency) }}</p>
        <span class="g-state" :class="state.c"><span v-html="icon(state.i, 15)"></span>{{ state.t }}</span>
        <div v-if="payTo && !settled" class="g-pay">
          <span class="g-ic" v-html="icon(payTo.method === 'Cash' ? 'cash' : 'phone', 20)"></span>
          <div class="min-w-0">
            <b>Pay {{ payTo.to }}</b>
            <span class="g-muted">{{ payTo.method }}<template v-if="payTo.account"> · {{ payTo.account }}</template></span>
          </div>
          <button v-if="payTo.account" type="button" @click="copy(payTo.account)">Copy</button>
        </div>
        <p v-if="!settled && (!d.settlement || d.settlement.status === 'pending')" class="g-small g-muted" style="margin:12px 0 0">
          Paid already? Let {{ d.bill.creator }} know and they’ll mark it as paid for you.
        </p>
      </div>

      <!-- Your order -->
      <div class="g-card g-pop">
        <h2>What you had</h2>
        <ul v-if="d.items.length" class="g-lines">
          <li v-for="(it, i) in d.items" :key="i">
            <span class="n">{{ it.qty > 1 ? it.qty + '× ' : '' }}{{ it.name }}<small v-if="it.shared">shared with {{ it.shared }} other{{ it.shared > 1 ? 's' : '' }}</small></span>
            <span class="a">{{ peso(it.amount, d.bill.receipt_currency || d.bill.currency) }}</span>
          </li>
          <li v-if="d.extras" class="minus" style="color:inherit"><span class="n">Your part of tax &amp; service<small v-if="d.bill.tax_included">Tax is already in the prices</small></span><span class="a" style="color:inherit">{{ peso(d.extras, d.bill.receipt_currency || d.bill.currency) }}</span></li>
          <li v-if="d.discount_share" class="minus"><span class="n">Discount<small v-if="d.me.discount_type !== 'none'">{{ d.me.discount_type === 'pwd' ? 'PWD' : 'Senior' }} discount applied</small></span><span class="a">− {{ peso(d.discount_share, d.bill.receipt_currency || d.bill.currency) }}</span></li>
          <li class="sum"><span class="n">Your share</span><span class="a">{{ peso(d.share, d.bill.currency) }}</span></li>
        </ul>
        <p v-else class="g-small g-muted">
          <template v-if="d.bill.split_mode === 'percent'">The bill is split by percentage{{ d.me.percent ? ' and your part is ' + d.me.percent + '%' : '' }}. Your share is {{ peso(d.share, d.bill.currency) }}.</template>
          <template v-else>No items have been assigned to you yet. Check back once {{ d.bill.creator }} finishes splitting.</template>
        </p>
      </div>

      </div>
      <div class="g-col g-sticky">
      <!-- Progress -->
      <div class="g-card g-pop">
        <h2>Where the bill is</h2>
        <div class="g-steps">
          <div v-for="(s, i) in steps" :key="i" class="g-step" :class="{ done: i < stage, on: i === stage }">
            <span class="g-dot"><span v-if="i < stage" v-html="icon('check', 14)"></span><template v-else>{{ i + 1 }}</template></span>{{ s }}
          </div>
        </div>
        <p v-if="d.progress.total" class="g-small g-muted" style="margin:12px 0 0">{{ d.progress.settled }} of {{ d.progress.total }} payments settled</p>
        <p class="g-small g-muted" style="margin:12px 0 6px">On this bill</p>
        <div class="g-people"><span v-for="(p, i) in d.people" :key="i" class="g-person" :class="{ you: p.you }"><i :style="{ background: p.color }">{{ p.initials }}</i>{{ p.you ? 'You' : p.name }}</span></div>
      </div>

      <!-- Invitation -->
      <div class="g-join g-pop">
        <img src="<?= $mascot('cheer') ?>" alt="" />
        <template v-if="d.viewer && !d.viewer.is_creator">
          <h2>Add this bill to your account</h2>
          <p>You’re signed in as {{ d.viewer.name }}. Link this bill so it shows up with your other bills.</p>
          <button type="button" class="g-btn" :disabled="claiming" @click="claim">{{ claiming ? 'Adding…' : 'Add to my account' }}</button>
        </template>
        <template v-else-if="!d.viewer">
          <h2>Keep this bill with a free account</h2>
          <p>Sign up and your share and payments stay with you, plus scanning, groups and more.</p>
          <a class="g-btn" :href="'register?claim=' + token">Create my free account →</a>
          <a class="g-btn ghost" :href="'login?claim=' + token">I already have an account</a>
        </template>
      </div>
      </div>
      </div>
    </template>
  </div>

  <!-- Sticky bar -->
  <div v-if="d && !d.viewer" class="g-bar"><div>
    <img src="<?= $mascot('wave') ?>" alt="" />
    <p>Register to unlock everything<small>Scan, pay, groups and history</small></p>
    <button type="button" @click="invite">Unlock</button>
  </div></div>
</div>

<script>
const TOKEN = <?= json_encode($token) ?>;
Setlo.mount({
  data: () => ({ loading: true, error: '', d: null, token: TOKEN, claiming: false, steps: ['Bill created', 'Settling up', 'All settled'] }),
  computed: {
    settled() { return !!(this.d.settlement && this.d.settlement.status === 'settled'); },
    owe() { const s = this.d.settlement; return s ? (s.status === 'settled' ? s.amount : s.remaining) : this.d.share; },
    payTo() { return this.d.settlement || this.d.preview; },
    stage() { return { closed: 2, settling: 1 }[this.d.bill.status] ?? 0; },
    state() {
      const s = this.d.settlement, st = this.d.bill.status;
      if (!s) {
        if (st === 'closed') return { i: 'check', t: this.d.share > 0 ? 'Bill closed' : 'Nothing to pay', c: 'ok' };
        return { i: 'clock', t: 'Estimate, still being set up', c: 'warn' };
      }
      return ({
        pending:  { i: 'wallet', t: 'Ready to pay', c: '' },
        awaiting: { i: 'clock', t: 'Waiting for confirmation', c: 'warn' },
        settled:  { i: 'party', t: 'All settled, thank you!', c: 'ok' },
        disputed: { i: 'warn', t: 'Being reviewed', c: 'bad' },
      })[s.status] || { i: '', t: s.status, c: '' };
    },
  },
  async mounted() {
    try {
      this.d = await api.get('guest.php', { t: TOKEN });
      if (!this.d.viewer) setTimeout(() => SetloInvite.once('guest-' + TOKEN.slice(0, 8), this.inviteOpts()), 3200);
    } catch (e) { this.error = e.message; }
    this.loading = false;
  },
  methods: {
    icon: SetloIcons.svg,
    inviteOpts() {
      return { variant: 'guest', name: this.d.me.name, creator: this.d.bill.creator, bill: this.d.bill.name, registerHref: 'register?claim=' + TOKEN, loginHref: 'login?claim=' + TOKEN };
    },
    invite() { SetloInvite.show(this.inviteOpts()); },
    async copy(text) {
      try { await navigator.clipboard.writeText(text); Setlo.toast('Copied', 'ok'); } catch (e) { Setlo.toast('Select the text and copy it manually.', 'warn'); }
    },
    async claim() {
      this.claiming = true;
      try { location.href = (await api.post('guest.php', { action: 'claim', t: TOKEN })).redirect; }
      catch (e) { Setlo.toast(e.message, 'error'); this.claiming = false; }
    },
  },
});
</script>
<?php require __DIR__ . '/../partials/foot.php'; ?>
