<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_login();
$title = 'Payment Methods';
$nav = 'payments';
$back = 'dashboard.php';
$page = 'payments';
require __DIR__ . '/../partials/head.php';
?>
<div id="app" class="device" v-cloak>

  <div class="app-hero px-5 pb-5 pt-8">
    <div class="flex items-center gap-3">
      <?php require __DIR__ . '/../partials/back.php'; ?>
      <div class="min-w-0 flex-1">
        <h1 class="text-[21px] font-extrabold tracking-tight">Payment Methods</h1>
        <p class="text-[12px] font-medium text-brand-50/90">How friends pay you</p>
      </div>
    </div>
  </div>

  <spinner v-if="loading"></spinner>
  <div v-else class="flex-1 space-y-3 px-5 pb-6 pt-4">

    <details class="tile acc">
      <summary><span><span class="acc-title">How friends pay you</span><span class="acc-sub">GCash/Maya, bank or cash</span></span><svg class="acc-chev" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6"/></svg></summary>
      <div class="acc-body">
        <form class="space-y-4" @submit.prevent="saveProfile(['payment_account'])" novalidate>
      <div>
        <p class="mt-0.5 text-[12px] text-slate-400">Shown on your Pay-me page and on the “You owe” card of anyone who owes you.</p>
      </div>
      <div>
        <p class="field-label" id="method-label">Preferred Payment Method</p>
        <div class="pm-grid" role="radiogroup" aria-labelledby="method-label" @keydown="methodKey">
          <button v-for="m in payMethods" :key="m.key" type="button" role="radio" class="pm-card" :class="{ on: f.payment_method === m.key }"
                  :aria-checked="f.payment_method === m.key" :tabindex="f.payment_method === m.key ? 0 : -1" :data-method="m.key" @click="f.payment_method = m.key">
            <img class="pay-logo pay-logo-lg" :src="payLogo(m.key)" alt="" width="44" height="44" />
            <span class="pm-text"><b>{{ m.label }}</b><small>{{ m.hint }}</small></span>
            <span class="pm-check" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 13l4 4L19 7"/></svg></span>
          </button>
        </div>
      </div>
      <div v-if="f.payment_method !== 'Cash'">
        <label class="field-label" for="account">{{ accountLabel }}</label>
        <div class="pm-field">
          <img class="pay-logo pm-prefix" :src="payLogo(f.payment_method)" alt="" width="28" height="28" />
          <input v-if="isGcash" id="account" data-field="payment_account" v-model="f.payment_account" @input="onGcashInput" @blur="touch('payment_account')"
                 class="input-soft" :class="{ 'is-invalid': err('payment_account') }" inputmode="numeric" maxlength="11" pattern="09[0-9]{9}" autocomplete="tel-national" placeholder="e.g. 09171234567" />
          <input v-else id="account" data-field="payment_account" v-model="f.payment_account" @input="touch('payment_account')" @blur="touch('payment_account')"
                 class="input-soft" :class="{ 'is-invalid': err('payment_account') }" maxlength="60" :placeholder="accountPlaceholder" />
        </div>
        <p v-if="err('payment_account')" class="field-error">{{ err('payment_account') }}</p>
        <p v-else-if="!f.payment_account.trim()" class="mt-1.5 text-[12px] text-amber-600">Add your number so friends know where to send money.</p>
      </div>
      <div v-else class="pm-note">No account needed. Friends will see “Pay in cash when you meet”.</div>
      <div class="pm-preview" aria-live="polite">
        <p class="pm-preview-label">How friends will see it</p>
        <div class="pm-preview-card">
          <img class="pay-logo" :src="payLogo(f.payment_method)" alt="" width="36" height="36" />
          <div class="min-w-0 flex-1">
            <p class="pm-preview-via">Pay via {{ f.payment_method }}</p>
            <p class="pm-preview-acct">{{ f.payment_method === 'Cash' ? 'Pay in cash when you meet' : (f.payment_account.trim() || 'No account number added yet') }}</p>
          </div>
        </div>
      </div>
      <button class="btn-pill btn-pill-primary" :disabled="busy">Save payment method</button>
    </form>
      </div>
    </details>

    <details class="tile acc">
      <summary><span><span class="acc-title">Your Pay-me QR</span><span class="acc-sub">Share or download your payment QR</span></span><svg class="acc-chev" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6"/></svg></summary>
      <div class="acc-body">
        <div class="">
      <p class="mt-0.5 text-[12px] text-slate-400">Made for your account automatically. Friends scan it to open your pay page: they see your {{ p.payment_method }} details, can pay what they owe you, or send any amount.</p>
      <div class="mt-4 flex flex-col items-center gap-4 sm:flex-row">
        <div class="h-40 w-40 shrink-0 rounded-2xl border border-slate-200 bg-white p-2 keep-white" v-html="qr" role="img" aria-label="Your Pay-me QR code"></div>
        <div class="w-full flex-1 space-y-2">
          <button @click="downloadQr" class="btn btn-primary w-full">Download QR card</button>
          <button @click="shareLink" class="btn btn-outline w-full">{{ canShare ? 'Share QR card' : 'Copy link' }}</button>
          <a :href="payUrl" class="btn btn-ghost w-full">Preview my pay page</a>
          <button @click="rotate" class="w-full text-center text-[12px] font-semibold text-slate-400 hover:text-rose-600" :disabled="busy">Generate New QR Code</button>
        </div>
      </div>
      <label v-if="p.payment_method !== 'Cash' && p.payment_account" class="mt-3 flex items-center gap-2 text-[12.5px] text-slate-600">
        <input type="checkbox" v-model="cardShowAcct" class="h-4 w-4 accent-brand-600" /> Show my {{ p.payment_method }} number on the downloaded card
      </label>
      <div class="mt-3 rounded-xl bg-slate-50 px-3 py-2">
        <p class="text-[10.5px] font-bold uppercase tracking-wide text-slate-400">The QR opens</p>
        <div class="flex items-center gap-2">
          <p class="min-w-0 flex-1 break-all text-[12px] font-semibold text-slate-700">{{ payUrl }}</p>
          <button type="button" @click="copyLink" class="shrink-0 text-[12px] font-bold text-brand-700">Copy</button>
        </div>
      </div>
      <div v-if="localLink" class="mt-2 rounded-xl border border-amber-300 bg-amber-50 px-3 py-2.5 text-[12px] leading-snug text-amber-800">
        <b>Friends’ phones can’t open this link.</b> It points to this computer (localhost). Open Setlo using your Wi-Fi address (for example <b>http://192.168.x.x/setlo-splitmate</b>), or set <b>APP_URL</b> in config/local.php to your public address, then reload this page.
      </div>
    </div>
      </div>
    </details>

    <details class="tile acc">
      <summary><span><span class="acc-title">Received via your QR</span><span class="acc-sub">Money friends sent you</span></span><svg class="acc-chev" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6"/></svg></summary>
      <div class="acc-body">
        <p v-if="!received.length" class="text-[13px] text-slate-400">{{ receivedLoaded ? 'Nothing yet. When a friend sends money through your QR, it shows up here.' : 'Loading…' }}</p>
        <div v-else class="divide-y divide-slate-100">
          <div v-for="r in received" :key="r.id" class="flex items-center gap-3 py-2.5">
            <avatar :user="r.from" :size="34"></avatar>
            <div class="min-w-0 flex-1">
              <p class="truncate text-[13.5px] font-semibold text-ink">{{ r.from.name }}</p>
              <p class="truncate text-[11.5px] text-slate-400">{{ r.note ? '“' + r.note + '” · ' : '' }}{{ r.via }} · {{ timeAgo(r.at) }}</p>
            </div>
            <p class="shrink-0 text-[14px] font-extrabold text-emerald-600">+{{ peso(r.amount, r.currency) }}</p>
          </div>
        </div>
      </div>
    </details>

    <details class="tile acc">
      <summary><span><span class="acc-title">Currency</span><span class="acc-sub">What your bills settle in</span></span><svg class="acc-chev" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6"/></svg></summary>
      <div class="acc-body">
        <div class="">
      <p class="mt-0.5 text-[12px] text-slate-400">New bills you pay for settle in it unless the group agrees on another. Bills already started keep theirs.</p>
      <div class="mt-3"><currency-select :model-value="p.currency" @update:model-value="setCurrency" :disabled="busy" label="Your currency"></currency-select></div>
    </div>
      </div>
    </details>

  </div>

  <?php require __DIR__ . '/../partials/nav.php'; ?>
</div>

<?php require __DIR__ . '/../partials/profile-script.php'; ?>
<?php require __DIR__ . '/../partials/foot.php'; ?>
