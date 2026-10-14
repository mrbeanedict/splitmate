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
        <label class="field-label" for="method">Preferred Payment Method</label>
        <select id="method" v-model="f.payment_method" class="input-soft cursor-pointer">
          <option>GCash</option><option>Maya</option><option>Bank Transfer</option><option>Cash</option>
        </select>
      </div>
      <div v-if="f.payment_method !== 'Cash'">
        <label class="field-label" for="account">{{ accountLabel }}</label>
        <input v-if="isGcash" id="account" data-field="payment_account" v-model="f.payment_account" @input="onGcashInput" @blur="touch('payment_account')"
               class="input-soft" :class="{ 'is-invalid': err('payment_account') }" inputmode="numeric" autocomplete="tel-national" placeholder="e.g. 09171234567" />
        <input v-else id="account" data-field="payment_account" v-model="f.payment_account" @input="touch('payment_account')" @blur="touch('payment_account')"
               class="input-soft" :class="{ 'is-invalid': err('payment_account') }" maxlength="60" :placeholder="accountPlaceholder" />
        <p v-if="err('payment_account')" class="field-error">{{ err('payment_account') }}</p>
        <p v-else-if="!f.payment_account.trim()" class="mt-1.5 text-[12px] text-amber-600">Add your number so friends know where to send money.</p>
      </div>
      <button class="btn-pill btn-pill-primary" :disabled="busy">Save payment method</button>
    </form>
      </div>
    </details>

    <details class="tile acc">
      <summary><span><span class="acc-title">Your Pay-me QR</span><span class="acc-sub">Share or download your payment QR</span></span><svg class="acc-chev" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6"/></svg></summary>
      <div class="acc-body">
        <div class="">
      <p class="mt-0.5 text-[12px] text-slate-400">Made for your account automatically. Friends scan it to open your pay page with your {{ p.payment_method }} details and what they owe you.</p>
      <div class="mt-4 flex flex-col items-center gap-4 sm:flex-row">
        <div class="h-40 w-40 shrink-0 rounded-2xl border border-slate-200 bg-white p-2 keep-white" v-html="qr" role="img" aria-label="Your Pay-me QR code"></div>
        <div class="w-full flex-1 space-y-2">
          <button @click="downloadQr" class="btn btn-primary w-full">Download QR</button>
          <button @click="shareLink" class="btn btn-outline w-full">{{ canShare ? 'Share link' : 'Copy link' }}</button>
          <a :href="payUrl" class="btn btn-ghost w-full">Preview my pay page</a>
          <button @click="rotate" class="w-full text-center text-[12px] font-semibold text-slate-400 hover:text-rose-600" :disabled="busy">Generate New QR Code</button>
        </div>
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
