<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_login();
$title = 'Review Items';
$nav = 'scan';
$billId = (int) ($_GET['bill'] ?? 0);
if (!$billId) {
    redirect('pages/scan-receipt');
}
$back = 'scan-receipt.php?bill=' . $billId;
$step = 2;
require __DIR__ . '/../partials/head.php';
?>
<div id="app" class="device device-narrow" v-cloak>

  <div class="app-hero px-5 pb-5 pt-8">
    <div class="flex items-center gap-3">
      <?php require __DIR__ . '/../partials/back.php'; ?>
      <div class="min-w-0">
        <h1 class="text-[19px] font-extrabold leading-tight tracking-tight">Review Extracted Items</h1>
        <p class="truncate text-[12px] font-medium text-brand-50/90">{{ bill ? bill.name : '' }}</p>
      </div>
    </div>
  </div>

  <?php require __DIR__ . '/../partials/steps.php'; ?>

  <spinner v-if="loading"></spinner>
  <div v-else class="flex-1 space-y-4 px-5 pb-4 pt-4">
    <!-- OCR summary -->
    <div class="tile flex items-center gap-3.5 p-3.5">
      <div class="h-[60px] w-[84px] shrink-0 space-y-1.5 rounded-xl border border-slate-200 bg-slate-50 px-2.5 py-2">
        <div class="paper-line"></div><div class="paper-line w-4/5"></div><div class="paper-line"></div><div class="paper-line w-3/5"></div><div class="paper-line"></div>
      </div>
      <div class="min-w-0 flex-1">
        <p class="text-[14px] font-extrabold leading-snug text-ink">{{ headline }}</p>
        <p class="mt-0.5 text-[12px] leading-snug text-slate-500">{{ rows.length }} items · {{ flaggedCount ? flaggedCount + ' needs review' : 'all confirmed' }}</p>
      </div>
      <button v-if="!receipts.length && bill.has_receipt" @click="showPhotos([])" class="h-10 shrink-0 rounded-xl border-[1.5px] border-brand-300 px-4 text-[13px] font-bold text-brand-700 hover:bg-brand-50">Photo</button>
    </div>

    <!-- Receipt currency: as the scanner read it (and why), correctable. Prices stay as printed. For a foreign receipt, the estimate in the settlement currency sits under it. -->
    <section class="tile p-4">
      <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-3">
        <div class="min-w-0 flex-1 basis-[13rem]">
          <p class="text-[14px] font-extrabold leading-tight text-ink">Receipt currency</p>
          <p class="mt-1 text-[12px] leading-snug text-slate-500">
            <template v-if="detected">Read as {{ detected.code }}<template v-if="detected.clues"> ({{ detected.clues }})</template>{{ detected.code !== receiptCur ? ' — corrected to ' + receiptCur : '' }}.</template>
            <template v-else-if="receipts.length">No currency printed — assumed {{ receiptCur }}.</template>
            <template v-else>Prices you type are in this currency.</template>
            <template v-if="receiptCur !== bill.currency"> Payments are settled in {{ bill.currency }}.</template>
          </p>
        </div>
        <div class="shrink-0"><currency-select compact :model-value="receiptCur" @update:model-value="setReceiptCurrency" :disabled="busy" label="Receipt currency"></currency-select></div>
      </div>

      <template v-if="foreign">
        <div v-if="bill.fx.missing" class="mt-3.5 rounded-xl border border-amber-200 bg-amber-50 px-3.5 py-2.5 text-[12.5px] leading-relaxed text-amber-800" role="alert">
          No exchange rate is available for {{ receiptCur }} → {{ bill.currency }} right now. It is fetched automatically — try again in a moment.
        </div>
        <div v-else class="mt-3.5 rounded-xl p-3.5" style="background:var(--tint)">
          <p class="text-[11px] font-bold uppercase tracking-wide text-slate-500">Estimated total in {{ bill.currency }}</p>
          <p class="mt-0.5 text-[24px] font-extrabold leading-tight tracking-tight text-ink">{{ peso(bill.fx.converted, bill.currency) }}</p>
          <p class="mt-1.5 text-[12px] leading-snug text-slate-500">
            {{ peso(bill.fx.native_total, receiptCur) }} on the receipt · {{ fmtRate(bill.fx.rate, receiptCur, bill.currency) }}<template v-if="bill.fx.source"> ({{ bill.fx.source }})</template>
          </p>
          <p class="mt-2 border-t border-slate-200/70 pt-2 text-[11.5px] leading-snug text-slate-400">Converted at the market rate, to the cent. It follows the last saved version of this bill.</p>
        </div>
      </template>
    </section>
    <div v-for="rc in receipts.filter((x) => x.currency_note)" :key="'cur' + rc.id" class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-[12.5px] leading-relaxed text-amber-800" role="alert">
      <b class="text-amber-900">{{ rc.number }}. {{ receiptLabel(rc) }}:</b> {{ rc.currency_note }}
    </div>

    <!-- Same purchase scanned twice -->
    <div v-for="rc in dupReceipts" :key="'dup' + rc.id" class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-[12.5px] leading-relaxed text-rose-800" role="alert">
      <b class="text-rose-900">{{ rc.number }}. {{ receiptLabel(rc) }}:</b> {{ rc.dup_note }}
      <div class="mt-2 flex gap-4">
        <button @click="removeReceipt(rc)" :disabled="busy" class="font-bold text-rose-900 underline underline-offset-2">Remove Receipt {{ rc.number }}</button>
        <button @click="keepReceipt(rc)" :disabled="busy" class="font-bold text-rose-700">Keep both</button>
      </div>
    </div>

    <div v-if="flaggedCount" class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-[12.5px] leading-relaxed text-amber-800">
      <b class="text-amber-900">Check {{ flaggedCount === 1 ? 'one item' : flaggedCount + ' items' }}.</b> Receipt codes were turned into plain names, and unclear reads and possible repeats are marked — confirm or correct the highlighted {{ flaggedCount === 1 ? 'row' : 'rows' }}.
      <button v-if="flaggedCount > 1" @click="resolveAll" class="mt-2 block font-bold text-amber-900 underline underline-offset-2">All look right</button>
    </div>

    <section v-for="g in groups" :key="g.key">
      <!-- Several separate receipts: each gets a title (suggested by the scan) that labels its items from here on -->
      <div v-if="g.receipt && receipts.length > 1" class="mb-2 px-1">
        <div class="flex items-center gap-2">
          <span class="shrink-0 text-[13px] font-extrabold text-slate-500">{{ g.receipt.number }}.</span>
          <input :value="g.receipt.title || ''" @change="saveTitle(g.receipt, $event)" @keydown.enter.prevent="$event.target.blur()" maxlength="60"
            :placeholder="g.receipt.store ? 'Title, e.g. ' + g.receipt.store : 'Add a title'" class="row-in !h-9 min-w-0 flex-1 font-bold" :aria-label="'Title of receipt ' + g.receipt.number" />
          <button v-if="g.receipt.photos.length" @click="showPhotos(g.receipt.photos)" class="shrink-0 text-[12px] font-bold text-brand-700">Photo{{ g.receipt.photos.length > 1 ? 's' : '' }}</button>
          <button @click="removeReceipt(g.receipt)" :disabled="busy" class="shrink-0 text-[12px] font-bold text-slate-400 hover:text-rose-500">Remove</button>
        </div>
        <p v-if="g.receipt.store || g.receipt.total !== null" class="mt-1 pl-5 text-[11.5px] text-slate-400">
          {{ [g.receipt.store, g.receipt.total !== null ? peso(g.receipt.total) : null].filter(Boolean).join(' · ') }}
        </p>
      </div>
      <div v-else-if="g.receipt" class="mb-2 flex items-center gap-2 px-1">
        <p class="min-w-0 flex-1 truncate text-[12px] font-extrabold tracking-[.04em] text-slate-500">
          RECEIPT {{ g.receipt.number }}<span v-if="g.receipt.store" class="font-semibold normal-case tracking-normal"> · {{ g.receipt.store }}</span><span v-if="g.receipt.total !== null" class="font-semibold tracking-normal"> · {{ peso(g.receipt.total) }}</span>
        </p>
        <button v-if="g.receipt.photos.length" @click="showPhotos(g.receipt.photos)" class="text-[12px] font-bold text-brand-700">Photo{{ g.receipt.photos.length > 1 ? 's' : '' }}</button>
        <button @click="removeReceipt(g.receipt)" :disabled="busy" class="text-[12px] font-bold text-slate-400 hover:text-rose-500">Remove</button>
      </div>
      <p v-else class="section-label">{{ g.label }}</p>
      <p v-if="g.receipt && !g.rows.length" class="tile px-4 py-3 text-[12.5px] text-slate-500">
        {{ g.receipt.ocr_status === 'failed' ? 'Couldn’t read this receipt' : 'No items found on this receipt' }} — add its items below with “+ Add missed item”, or remove it.
      </p>
      <div class="space-y-3">
        <div v-for="r in g.rows" :key="r.key" :ref="'row' + r.key" class="tile p-3" :class="{ flagged: r.needs_review, 'animate-nudge': r.nudge }">
          <div class="flex gap-2">
            <input v-model="r.name" @input="touch('name' + r.key)" @blur="touch('name' + r.key)" :data-field="'name' + r.key" :class="{ 'is-invalid': err('name' + r.key) }" class="row-in flex-1" placeholder="Item name" maxlength="120" aria-label="Item name" />
            <input :value="r.qty" @input="r.qty = filterInt($event.target.value); $event.target.value = r.qty; touch('qty' + r.key)" :data-field="'qty' + r.key" :class="{ 'is-invalid': err('qty' + r.key) }" class="row-in row-qty" inputmode="numeric" maxlength="3" aria-label="Quantity" />
            <input :value="r.unit_price" @input="r.unit_price = filterMoney($event.target.value); $event.target.value = r.unit_price; touch('price' + r.key)" :data-field="'price' + r.key" :class="{ 'is-invalid': err('price' + r.key) }" class="row-in w-[92px] text-right" inputmode="decimal" aria-label="Unit price" placeholder="0.00" />
          </div>
          <p v-if="rowErr(r)" class="field-error !mt-1">{{ rowErr(r) }}</p>
          <p v-if="r.details" class="mt-1.5 px-0.5 text-[11.5px] text-slate-500">Meal set · includes {{ r.details }}</p>
          <p v-if="r.renamedFrom" class="mt-1 px-0.5 text-[11px] text-slate-400">Printed: “{{ r.renamedFrom }}”</p>
          <p v-if="money(r.promo) > 0" class="mt-1.5 px-0.5 text-[11.5px] font-semibold text-emerald-700">
            <svg class="mb-px mr-0.5 inline h-3.5 w-3.5 align-middle" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12V4a1 1 0 011-1h8l9 9-9 9-9-9z"/><circle cx="7.5" cy="7.5" r="1.5"/></svg>Promo −{{ peso(money(r.promo)) }} <span class="font-medium text-slate-400">· printed {{ peso(lineTotal(r) + money(r.promo)) }} → {{ peso(lineTotal(r)) }} (price shown is after the promo)</span>
          </p>
          <div class="mt-2 flex items-center justify-between gap-2 px-0.5 text-[11.5px]">
            <span v-if="r.needs_review" class="font-bold text-amber-600">⚠ {{ flagText(r) }}</span>
            <span v-else class="flex items-center gap-1 font-bold text-brand-600">
              <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
              {{ r.fixed ? 'Confirmed' : r.id ? (r.source === 'ocr' ? 'High confidence' : 'Saved') : 'Added manually' }}
            </span>
            <span class="flex shrink-0 items-center gap-2 text-slate-400">
              <span class="flex flex-col items-end leading-tight">
                <!-- Receipt in another currency: what this line comes to in the settlement currency (estimate at the current rate) -->
                <span v-if="foreign && !bill.fx.missing" class="font-extrabold text-brand-700" title="At the bill's market rate">≈ {{ peso(converted(r), bill.currency) }}</span>
                <span>Line total {{ peso(lineTotal(r)) }}</span>
              </span>
              <button @click="remove(r)" class="font-bold text-slate-300 hover:text-rose-500" aria-label="Remove item">✕</button>
            </span>
          </div>
          <div v-if="r.needs_review && r.suggestion" class="mt-2.5 grid grid-cols-2 gap-2">
            <button @click="resolve(r, true)" class="h-9 rounded-xl bg-brand-600 text-[12px] font-bold text-white">Use “{{ r.suggestion }}”</button>
            <button @click="resolve(r, false)" class="h-9 rounded-xl border border-amber-300 bg-white text-[12px] font-bold text-amber-700">Keep as typed</button>
          </div>
          <div v-else-if="r.needs_review && r.dup_note" class="mt-2.5 grid grid-cols-2 gap-2">
            <button @click="resolve(r, false)" class="h-9 rounded-xl bg-brand-600 text-[12px] font-bold text-white">Keep it</button>
            <button @click="remove(r)" class="h-9 rounded-xl border border-amber-300 bg-white text-[12px] font-bold text-amber-700">Remove the repeat</button>
          </div>
          <div v-else-if="r.needs_review && money(r.promo) > 0" class="mt-2.5 grid grid-cols-2 gap-2">
            <button @click="resolve(r, false)" class="h-9 rounded-xl bg-brand-600 text-[12px] font-bold text-white">Keep promo on item</button>
            <button @click="promoToDiscount(r)" class="h-9 rounded-xl border border-amber-300 bg-white text-[12px] font-bold text-amber-700">Make it a bill discount</button>
          </div>
          <div v-else-if="r.needs_review" class="mt-2.5 grid gap-2" :class="r.renamedFrom ? 'grid-cols-2' : 'grid-cols-1'">
            <button @click="resolve(r, false)" class="h-9 rounded-xl bg-brand-600 text-[12px] font-bold text-white">Looks right</button>
            <button v-if="r.renamedFrom" @click="usePrinted(r)" class="h-9 rounded-xl border border-amber-300 bg-white text-[12px] font-bold text-amber-700">Use printed name</button>
          </div>
        </div>
      </div>
    </section>

    <div class="grid grid-cols-2 gap-3">
      <button @click="add" class="h-11 rounded-2xl border-[1.5px] border-dashed border-slate-300 text-[13px] font-bold text-slate-500 hover:border-brand-400 hover:text-brand-700">+ Add {{ rows.length ? 'missed' : 'an' }} item</button>
      <button @click="addReceipt" :disabled="busy" class="h-11 rounded-2xl border-[1.5px] border-dashed border-brand-300 text-[13px] font-bold text-brand-700 hover:bg-brand-50">+ Add another receipt</button>
    </div>

    <!-- Receipt totals -->
    <div class="tile space-y-2 p-4 text-[13px]">
      <p class="!mb-1 text-[14px] font-extrabold text-ink">Totals</p>
      <p v-if="receipts.length > 1" class="text-[11.5px] text-slate-400">Tax, service charge, discount and total are the {{ receipts.length }} receipts added together.</p>
      <div class="flex items-center justify-between"><span class="text-slate-500">Subtotal</span><span class="font-semibold text-slate-800">{{ peso(subtotal) }}</span></div>
      <label class="flex items-center justify-between"><span class="text-slate-500">Tax <span v-if="taxIncluded" class="text-[11px] font-semibold text-brand-600">(already in prices — not added)</span></span><input :value="tax" @input="tax = filterMoney($event.target.value); $event.target.value = tax; touch('tax')" data-field="tax" :class="{ 'is-invalid': err('tax') }" inputmode="decimal" class="row-in !h-9 w-24 text-right" aria-label="Tax" /></label>
      <label class="flex items-center justify-between"><span class="text-slate-500">Service charge</span><input :value="svc" @input="svc = filterMoney($event.target.value); $event.target.value = svc; touch('svc')" data-field="svc" :class="{ 'is-invalid': err('svc') }" inputmode="decimal" class="row-in !h-9 w-24 text-right" aria-label="Service charge" /></label>
      <label class="flex items-center justify-between"><span class="text-slate-500">Discount <span class="text-[11px] text-slate-400">(Senior/PWD, promo)</span></span><span class="flex items-center gap-1 text-slate-400">−<input :value="discount" @input="discount = filterMoney($event.target.value); $event.target.value = discount; touch('discount')" data-field="discount" :class="{ 'is-invalid': err('discount') }" inputmode="decimal" class="row-in !h-9 w-24 text-right text-emerald-700" aria-label="Discount" /></span></label>
      <p v-if="money(discount) > 0" class="text-[11.5px] text-slate-400">You'll choose who gets the discount on the next step.</p>
      <label class="mt-1 flex items-center justify-between border-t border-slate-100 pt-2 text-[14px]">
        <span class="font-extrabold text-ink">Receipt total <span class="text-[11px] font-medium text-slate-400">(as printed)</span></span>
        <input :value="receiptTotal" @input="receiptTotal = filterMoney($event.target.value); $event.target.value = receiptTotal; touch('receipt')" data-field="receipt" :class="{ 'is-invalid': err('receipt') }" inputmode="decimal" placeholder="optional" aria-label="Receipt total" class="row-in !h-9 w-28 text-right font-extrabold text-brand-700" />
      </label>
      <p v-if="receiptTotal !== '' && receiptTotal !== null" class="text-[12px] font-semibold" :class="diff === 0 ? 'text-brand-600' : 'text-rose-500'">
        {{ diff === 0 ? '✓ Matches the receipt total' : 'Off by ' + peso(Math.abs(diff)) + ' — check quantities and prices' }}
      </p>
      <!-- Round the bill to whole pesos? Every share then becomes whole pesos too (round_to_whole() in includes/domain.php) -->
      <div v-if="canRound" class="!mt-3 flex items-center justify-between gap-3 border-t border-slate-100 pt-3">
        <div class="min-w-0">
          <p class="text-[13.5px] font-extrabold text-ink">Round to whole {{ unitName }}</p>
          <p class="text-[11.5px] leading-snug text-slate-400">{{ roundHint }}</p>
        </div>
        <button type="button" role="switch" :aria-checked="roundTotal" class="switch switch-brand" @click="roundTotal = !roundTotal" :aria-label="'Round to whole ' + unitName"><span></span></button>
      </div>
    </div>

    <!-- 1. Amount tendered: the cash paid at the counter (read off the receipt when it printed one), against the total due, and the change -->
    <section class="tile p-4 text-[13px]">
      <p class="text-[14px] font-extrabold text-ink">Amount tendered</p>
      <p class="mt-0.5 text-[12px] leading-snug text-slate-500">What was paid at the cashier, in cash or by card, and the change that came back. Read from the receipt when it shows them; edit either one and the other follows. Who paid, below, follows this amount.</p>

      <div class="mt-3 space-y-2.5 rounded-xl p-3.5" style="background:var(--tint)">
        <div class="flex items-center justify-between"><span class="text-slate-500">Total due</span><span class="font-semibold text-ink">{{ peso(total, receiptCur) }}<span v-if="foreign && dockTotal !== null" class="ml-1.5 text-[11.5px] font-medium text-slate-400">≈ {{ peso(dockTotal, bill.currency) }}</span></span></div>
        <label class="flex items-center gap-3">
          <span class="min-w-0 flex-1 font-semibold text-ink">Amount tendered</span>
          <span class="text-slate-400">{{ currencySymbol(receiptCur) }}</span>
          <input :value="fieldText('tendered')" @focus="focusField('tendered')" @blur="blurField" @input="typeTendered($event)" data-field="tendered" :class="{ 'is-invalid': err('tendered') }"
                 :disabled="!rate" inputmode="decimal" placeholder="0.00" aria-label="Amount tendered" class="row-in w-32 text-right text-[15px] font-bold" />
        </label>
        <label class="flex items-center gap-3 border-t border-slate-200/70 pt-2.5">
          <span class="min-w-0 flex-1 font-extrabold text-ink">Change <span class="text-[11px] font-medium text-slate-400">(sukli)</span></span>
          <span class="text-slate-400">{{ currencySymbol(receiptCur) }}</span>
          <input :value="fieldText('change')" @focus="focusField('change')" @blur="blurField" @input="typeChange($event)" data-field="change"
                 :disabled="!rate" inputmode="decimal" placeholder="0.00" aria-label="Change" class="row-in w-32 text-right text-[15px] font-bold" :class="change > 0 ? '!text-brand-700' : ''" />
        </label>
      </div>
      <p v-if="err('tendered')" class="field-error">{{ err('tendered') }}</p>
      <p v-if="!rate" class="mt-2 text-[12px] text-amber-700">No exchange rate is available for {{ receiptCur }} right now, so these amounts can't be saved yet. Try again in a moment.</p>
      <p v-if="tenderedAmount > 0 && tenderedAmount < total - 0.005" class="mt-2 text-[12.5px] font-bold text-rose-500">Short by {{ peso(Math.round((total - tenderedAmount) * 100) / 100, receiptCur) }} — the amount tendered doesn't cover the total.</p>
    </section>

    <!-- 2. Who paid the bill: follows the amount tendered above, which is divided between the payers (one payer: just the person). -->
    <section class="tile p-4 text-[13px]">
      <p class="text-[14px] font-extrabold text-ink">Who paid the bill?</p>
      <p class="mt-0.5 text-[12px] leading-snug text-slate-500">
        <template v-if="payers.length > 1">The amount tendered above is divided evenly between the payers. If it wasn't even, change a person's amount — the amount tendered follows.</template>
        <template v-else>The person who paid the cashier. Change who paid on the Create Bill step.</template>
      </p>

      <div v-if="payers.length <= 1" class="mt-3 flex items-center gap-3 rounded-xl p-3" style="background:var(--tint)">
        <avatar v-if="soloPayer" :user="soloPayer" :size="32"></avatar>
        <span class="min-w-0 flex-1 truncate font-bold text-ink">{{ !soloPayer || soloPayer.id === meId ? 'You' : soloPayer.name }}</span>
        <span class="shrink-0 rounded-full bg-brand-600 px-2.5 py-1 text-[11px] font-bold text-white">Paid</span>
      </div>

      <template v-else>
        <div class="mt-3 space-y-2">
          <label v-for="m in payers" :key="m.id" class="flex items-center gap-3">
            <avatar :user="m" :size="28"></avatar>
            <span class="min-w-0 flex-1 truncate font-semibold">{{ m.id === meId ? 'You' : m.name }}</span>
            <!-- Short of the total: one tap puts the rest on this payer -->
            <button v-if="payShort > 0" type="button" @click.prevent="fillShort(m)" class="shrink-0 rounded-lg border border-brand-300 px-2 py-1 text-[11.5px] font-bold text-brand-700 hover:bg-brand-50"
                    :aria-label="'Add the ' + peso(payShort) + ' short to ' + m.name">+{{ peso(payShort) }}</button>
            <span class="text-slate-400">{{ currencySymbol(receiptCur) }}</span>
            <input :value="payDraft[m.id]" @focus="$event.target.select()" @input="payDraft[m.id] = filterMoney($event.target.value); $event.target.value = payDraft[m.id]; touch('pay' + m.id)" :data-field="'pay' + m.id"
                   :class="{ 'is-invalid': err('pay' + m.id) }" :disabled="!rate" inputmode="decimal" placeholder="0.00" class="row-in !h-10 w-28 text-right" :aria-label="'Amount paid by ' + m.name" />
          </label>
        </div>
        <p v-for="m in payers" :key="'pe' + m.id" v-show="err('pay' + m.id)" class="field-error">{{ m.first }}: {{ err('pay' + m.id) }}</p>
        <p class="mt-2 text-[12px] font-semibold" :class="payCovers ? 'text-brand-600' : 'text-slate-500'">
          {{ peso(payTotal, receiptCur) }} put in of {{ peso(total, receiptCur) }}{{ payTotal === 0 ? '' : payCovers ? ' ✓' : ' — ' + peso(Math.round((total - payTotal) * 100) / 100, receiptCur) + ' short' }}
        </p>
      </template>
    </section>

    <!-- 3. Who received the change: never assumed, chosen once there is some -->
    <section data-field="holder" class="tile p-4 text-[13px]" :class="change > 0 ? '!border-amber-200 !bg-amber-50/70' : ''">
      <p class="text-[14px] font-extrabold" :class="change > 0 ? 'text-amber-900' : 'text-ink'">Who received the change?</p>
      <template v-if="change > 0">
        <p class="mt-0.5 text-[12px] leading-snug text-amber-800/80">{{ peso(change, receiptCur) }} came back. Choose whoever the cashier handed it to. Setlo works out each person's part of it, and who passes what back, from this.</p>
        <change-holder-picker class="mt-2.5" :people="members" v-model="changeHolder" :me="meId" :paid="payAmounts"></change-holder-picker>
        <p v-if="holderAsked && !changeHolder" class="field-error">Choose who received the change.</p>
      </template>
      <p v-else class="mt-0.5 text-[12px] leading-snug text-slate-500">{{ tenderedAmount > 0 ? 'No change to hand back, so there is nothing to choose.' : 'Enter the amount tendered above. If it is more than the total, you will choose who received the change here.' }}</p>
    </section>
  </div>

  <div v-if="bill" class="dock">
    <div class="dock-inner">
      <div>
        <p class="text-[11px] font-medium text-slate-400">{{ rows.length }} items · {{ foreign ? 'est. total in ' + bill.currency : 'total' }}</p>
        <p class="text-[21px] font-extrabold leading-tight tracking-tight text-white">{{ dockTotal === null ? '—' : peso(dockTotal, bill.currency) }}</p>
      </div>
      <button @click="save" class="dock-btn" :disabled="busy">{{ busy ? 'Saving…' : 'Continue' }}</button>
    </div>
  </div>

  <?php require __DIR__ . '/../partials/nav.php'; ?>

  <div v-if="photos" class="fixed inset-0 z-50 flex flex-col items-center gap-3 overflow-y-auto bg-night/70 p-6" @click.self="photos = null">
    <img v-for="src in photos" :key="src" :src="src" alt="Receipt photo" class="max-h-[85vh] max-w-full rounded-2xl bg-white shadow-2xl" @click="photos = null" />
  </div>
</div>

<script>
const money = (v) => Math.round((parseFloat(String(v).replace(/,/g, '')) || 0) * 100) / 100;
const cents = (v) => Math.round(money(v) * 100);
/** The printed text, when the scan gave the item a different plain name (mirrors the check in includes/ocr.php). */
const nameKey = (s) => String(s).toLowerCase().replace(/[^a-z0-9]/g, '');
const renamedFrom = (it) => (it.printed_name && nameKey(it.printed_name) !== nameKey(it.name) ? it.printed_name : null);
const PHOTO_URL = '<?= h(url('api/receipts.php')) ?>';
let keySeq = 0;

Setlo.mount({
  mixins: [Setlo.validation],
  data: () => ({
    billId: <?= $billId ?>, loading: true, busy: false, photos: null, shown: null,
    bill: null, receipts: [], rows: [], tax: '0.00', svc: '0.00', discount: '0.00', receiptTotal: '',
    meId: <?= (int) $user['id'] ?>, payers: [], payDraft: {},
    members: [], tendered: '', focused: null, typed: '', roundTotal: false, changeHolder: null, serverExact: 0, holderAsked: false,
  }),
  computed: {
    /** The whole amount in the settlement currency, before any rounding: as typed here, or (another currency) as last saved. */
    settleExact() { return this.foreign ? this.serverExact : this.total; },
    canRound() { return !!this.bill && (Setlo.currencies[this.bill.currency] || {}).decimals !== 0; },
    rounding() { return this.canRound && this.roundTotal; },
    unitName() { return this.bill.currency === 'PHP' ? 'pesos' : 'amounts'; },
    /** Nearest whole unit, as round_to_whole() does: { total, diff } in the settlement currency. */
    rounded() {
      const c = cents(this.settleExact);
      const r = Math.floor((c + 50) / 100) * 100;
      return { total: r / 100, diff: (r - c) / 100 };
    },
    roundHint() {
      const cur = this.bill.currency;
      const d = this.rounded.diff;
      if (!d) return 'It’s already a whole amount.';
      const by = (d > 0 ? '+' : '−') + this.peso(Math.abs(d), cur);
      const payer = this.members.find((m) => m.id === this.bill.payer_id);
      const who = payer ? (payer.id === this.meId ? 'you' : payer.first) : 'the payer';
      return this.roundTotal
        ? `${this.peso(this.settleExact, cur)} → ${this.peso(this.rounded.total, cur)} (${by}). Every share becomes whole ${this.unitName}; ${who} ${d > 0 ? 'keeps' : 'covers'} the ${this.peso(Math.abs(d), cur)}.`
        : `Keep ${this.peso(this.settleExact, cur)} exactly, or round to ${this.peso(this.rounded.total, cur)} (${by}) so everyone pays whole ${this.unitName}.`;
    },
    /** The one person who paid, when only one did (the main payer of the bill). */
    soloPayer() { return this.members.find((m) => m.id === this.bill.payer_id) || null; },
    payAmounts() {
      if (this.payers.length <= 1) return { [this.bill.payer_id]: this.tenderedAmount };
      return Object.fromEntries(this.payers.map((m) => [m.id, money(this.payDraft[m.id] || 0)]));
    },
    /** Handed over beyond what was charged (several payers only): the change somebody received. */
    /** What one unit of the receipt's currency is in the settlement currency (1 when they are the same; 0 = no rate right now). */
    rate() { return this.foreign ? (this.bill.fx.missing ? 0 : this.bill.fx.rate) : 1; },
    change() { return Math.max(0, Math.round((this.tenderedAmount - this.total) * 100) / 100); },
    /** What was handed to the cashier: the payers' amounts added up, or the one payer's entered amount (0 = not entered). */
    tenderedAmount() { return this.payers.length > 1 ? this.payTotal : money(this.tendered); },
    /** The floating total, always in the settlement currency (an estimate while the receipt is in another one). */
    dockTotal() {
      if (this.foreign) return this.bill.fx.missing ? null : Math.round(this.total * this.bill.fx.rate * 100) / 100;
      return this.rounding ? this.rounded.total : this.total;
    },
    flaggedCount() { return this.rows.filter((r) => r.needs_review).length; },
    subtotal() { return this.rows.reduce((s, r) => s + this.lineTotal(r), 0); },
    /** Mirrors tax_included() in includes/domain.php: tax already in the prices if leaving it out is what matches the printed total. */
    taxIncluded() {
      const tax = cents(this.tax);
      if (tax <= 0 || this.receiptTotal === '' || this.receiptTotal === null) return false;
      const printed = cents(this.receiptTotal);
      const withoutTax = cents(this.subtotal) + cents(this.svc) - cents(this.discount);
      const offWithout = Math.abs(withoutTax - printed);
      return offWithout <= 100 && offWithout < Math.abs(withoutTax + tax - printed);
    },
    total() { return Math.round((this.subtotal + (this.taxIncluded ? 0 : money(this.tax)) + money(this.svc) - money(this.discount)) * 100) / 100; },
    diff() { return Math.round((this.total - money(this.receiptTotal)) * 100) / 100; },
    headline() {
      if (this.receipts.length > 1) return this.receipts.length + ' receipts scanned';
      if (this.bill.ocr_status === 'ok') return 'OCR scan complete';
      if (this.bill.ocr_status === 'failed') return 'Photo saved — enter items';
      return 'Manual entry';
    },
    dupReceipts() { return this.receipts.filter((rc) => rc.dup_note); },
    payTotal() { return Math.round(this.payers.reduce((s, m) => s + money(this.payDraft[m.id] || 0), 0) * 100) / 100; },
    /** The amounts handed over only have to cover the bill total (whoever received the change is asked below). */
    payCovers() { return this.payTotal > 0 && this.payTotal >= this.total - 0.005; },
    /** How much the payers' amounts are short of the bill total (0 when covered, or when the receipt is in another currency). */
    payShort() { return Math.max(0, Math.round((this.total - this.payTotal) * 100) / 100); },
    foreign() { return !!(this.bill && this.bill.fx && this.bill.fx.foreign); },
    receiptCur() { return this.bill.receipt_currency || this.bill.currency || 'PHP'; },
    /** What the scanner read on the first receipt that printed a currency: { code, clues }. */
    detected() {
      const rc = this.receipts.find((x) => x.currency_detected);
      return rc ? { code: rc.currency_detected, clues: rc.currency_clues } : null;
    },
    /** Items under their receipt; typed-in rows (and everything, when nothing was scanned) in one plain list. */
    groups() {
      if (!this.receipts.length) return [{ key: 'all', receipt: null, label: this.bill.ocr_status === 'ok' ? 'EXTRACTED ITEMS' : 'ITEMS', rows: this.rows }];
      const ids = this.receipts.map((rc) => rc.id);
      const out = this.receipts.map((rc) => ({ key: 'r' + rc.id, receipt: rc, rows: this.rows.filter((r) => r.receipt_id === rc.id) }));
      const loose = this.rows.filter((r) => !ids.includes(r.receipt_id));
      if (loose.length) out.push({ key: 'manual', receipt: null, label: 'ADDED MANUALLY', rows: loose });
      return out;
    },
  },
  async mounted() {
    await Setlo.load(this, 'bills.php', { id: this.billId }, (r, fresh) => {
      if (!r.me.can_assign || r.bill.locked) {
        location.replace('bill-items?bill=' + this.billId);
        return;
      }
      if (fresh && this.shown !== null && this.shown !== this.formState()) return; // already typing: keep their work
      this.fill(r);
      if (fresh && r.bill.group_suggest) this.askSaveGroup(r);
    });
  },
  methods: {
    money,
    fill(r) {
      Setlo.useCurrency(r.bill.receipt_currency || r.bill.currency); // everything here is as printed on the receipt
      this.bill = r.bill;
      this.receipts = r.receipts || []; // a cached reply from before receipts existed has none
      this.rows = r.items.map((it) => ({ ...it, key: ++keySeq, unit_price: it.unit_price.toFixed(2), fixed: false, nudge: false, renamedFrom: renamedFrom(it) }));
      this.tax = r.bill.tax.toFixed(2);
      this.svc = r.bill.service_charge.toFixed(2);
      this.discount = r.bill.discount.toFixed(2);
      this.receiptTotal = r.bill.receipt_total === null ? '' : r.bill.receipt_total.toFixed(2);
      // Ticked as payers on the Create Bill step (only shown when there are several)
      const payerIds = r.payments.multi ? r.payments.payer_ids : [];
      this.payers = r.members.filter((m) => payerIds.includes(m.id));
      const toNative = (v) => (this.rate ? (Math.round((v / this.rate) * 100) / 100).toFixed(2) : v.toFixed(2));
      this.payDraft = Object.fromEntries(this.payers.map((m) => [m.id, r.payments.paid[m.id] ? toNative(r.payments.paid[m.id]) : '0.00']));
      this.members = r.members;
      this.tendered = !r.payments.multi && r.payments.tendered != null ? toNative(Number(r.payments.tendered)) : '';
      // The cash read off the receipt, in the receipt's own currency: what was saved on the bill (same currency), else what the
      // receipts themselves say (another currency, where it is converted only when saved).
      const scannedSum = this.receipts.reduce((t, x) => t + (x.tendered || 0), 0);
      const scanned = r.payments.tendered != null ? Number(toNative(Number(r.payments.tendered))) : Math.round(scannedSum * 100) / 100;
      if (scanned > 0) {
        if (!r.payments.multi) {
          if (!this.tendered) this.tendered = scanned.toFixed(2);
        } else if (!this.payers.some((m) => money(this.payDraft[m.id]) > 0)) {
          this.setTendered(scanned.toFixed(2)); // several payers: divided between them
        }
      }
      this.roundTotal = !!r.bill.round_total;
      this.changeHolder = r.payments.change_holder ?? null;
      this.serverExact = r.bill.exact_total ?? r.total;
      if (!this.rows.length && !this.receipts.length) this.add();
      this.shown = this.formState();
    },
    async reload() { this.fill(await api.get('bills.php', { id: this.billId })); },
    formState() {
      return JSON.stringify([this.rows.map(({ key, ...row }) => row), this.tax, this.svc, this.discount, this.receiptTotal, this.payDraft, this.tendered, this.roundTotal, this.changeHolder]);
    },
    flagText(r) {
      if (r.suggestion) return 'Looks like “' + r.suggestion + '”?';
      if (r.dup_note) return r.dup_note;
      if (money(r.promo) > 0) return 'Promo found under this item — already taken off its price';
      return r.renamedFrom ? 'Renamed from the receipt code — is this right?' : 'Please double-check this item';
    },
    showPhotos(ids) {
      const base = PHOTO_URL + '?bill_id=' + this.billId;
      this.photos = ids.length ? ids.map((id) => base + '&photo_id=' + id) : [base];
    },
    lineTotal(r) { return Math.round((Number(r.qty) || 0) * money(r.unit_price) * 100) / 100; },
    /** A line's total in the settlement currency at the bill's current rate (display only; the bill's own conversion is on Bill Detail). */
    converted(r) { return Math.round(this.lineTotal(r) * this.bill.fx.rate * 100) / 100; },
    resolve(r, useSuggestion) {
      if (useSuggestion) r.name = r.suggestion;
      r.needs_review = false;
      r.fixed = true;
    },
    /** The promo was for the whole receipt: the item goes back to its printed price and the amount to the bill discount. */
    promoToDiscount(r) {
      const promo = money(r.promo);
      const qty = Number(r.qty) || 1;
      r.unit_price = ((this.lineTotal(r) + promo) / qty).toFixed(2);
      r.promo = 0;
      this.discount = (money(this.discount) + promo).toFixed(2);
      this.resolve(r, false);
    },
    usePrinted(r) {
      r.name = r.renamedFrom;
      r.renamedFrom = null;
      this.resolve(r, false);
    },
    resolveAll() {
      for (const r of this.rows) if (r.needs_review) this.resolve(r, false);
    },
    rowEl(r) {
      const el = this.$refs['row' + r.key];
      return Array.isArray(el) ? el[0] : el;
    },
    add() {
      const r = { id: null, receipt_id: null, key: ++keySeq, name: '', qty: 1, unit_price: '', promo: 0, needs_review: false, source: 'manual', fixed: false, nudge: false };
      this.rows.push(r);
      this.$nextTick(() => this.rowEl(r)?.querySelector('input')?.focus());
    },
    remove(r) { this.rows.splice(this.rows.indexOf(r), 1); },
    flash(r, msg) {
      this.rowEl(r)?.scrollIntoView({ behavior: 'smooth', block: 'center' });
      r.nudge = false;
      this.$nextTick(() => { r.nudge = true; setTimeout(() => { r.nudge = false; }, 700); });
      Setlo.toast(msg, 'warn');
    },
    validators() {
      const v = {};
      for (const r of this.rows) {
        v['name' + r.key] = V.required(r.name, 'Item name') || V.maxLen(r.name, 120, 'Item name') || (/[<>]/.test(r.name) ? 'Item name can’t contain < or >.' : '');
        v['qty' + r.key] = V.int(r.qty, 1, 999, 'Quantity');
        v['price' + r.key] = V.money(r.unit_price, { required: true, label: 'Price' });
      }
      v.tax = V.money(this.tax, { label: 'Tax' });
      v.svc = V.money(this.svc, { label: 'Service charge' });
      v.discount = V.money(this.discount, { label: 'Discount' }) || (money(this.discount) > this.subtotal ? 'The discount can’t be more than the items subtotal.' : '');
      v.receipt = V.money(this.receiptTotal, { label: 'Receipt total' });
      v.tendered = V.money(this.tendered, { label: 'Amount tendered' });
      for (const m of this.payers) v['pay' + m.id] = V.money(this.payDraft[m.id], { label: 'Amount' });
      return v;
    },
    rowErr(r) { return this.err('name' + r.key) || this.err('qty' + r.key) || this.err('price' + r.key); },
    payload() {
      return {
        bill_id: this.billId,
        items: this.rows.map((x) => ({ id: x.id, name: x.name.trim(), qty: Number(x.qty), unit_price: money(x.unit_price), promo: money(x.promo || 0), needs_review: !!x.needs_review })),
        tax: money(this.tax), service_charge: money(this.svc), discount: money(this.discount),
        receipt_total: this.receiptTotal === '' ? null : money(this.receiptTotal),
        ...(this.payers.length > 1 ? { payments: this.settlePayments() } : { amount_tendered: this.toSettle(money(this.tendered)) }),
        change_holder_id: this.change > 0 ? this.changeHolder || 0 : 0,
        round_total: this.roundTotal,
      };
    },
    /**
     * Save edits without leaving (open flags stay open), before an action that reloads or leaves the page.
     * Resolves false when the form has errors to fix first.
     */
    async saveDraft() {
      if (this.formState() === this.shown || !this.rows.length) return true;
      if (!this.validateAll()) return false;
      await api.post('items.php', this.payload());
      return true;
    },
    async addReceipt() {
      const ok = await Setlo.run(this, () => this.saveDraft());
      if (ok) location.href = 'scan-receipt?bill=' + this.billId + '&add=1';
    },
    async removeReceipt(rc) {
      const yes = await Setlo.confirm({
        title: `Remove ${this.receiptLabel(rc)}?`, danger: true, confirmText: 'Remove',
        text: `Its items, photo and amounts (tax, service charge, total) come off this bill.`,
      });
      if (!yes) return;
      const done = await Setlo.run(this, async () => {
        if (!(await this.saveDraft())) return false;
        await api.post('receipts.php', { action: 'remove_receipt', bill_id: this.billId, receipt_id: rc.id });
        await this.reload();
        return true;
      });
      if (done) Setlo.toast(`${this.receiptLabel(rc)} removed`, 'ok');
    },
    async saveTitle(rc, e) {
      const title = e.target.value.trim().replace(/\s+/g, ' ');
      if (title === (rc.title || '')) return;
      if (/[<>]/.test(title)) { Setlo.toast('The title can’t contain < or >.', 'warn'); e.target.value = rc.title || ''; return; }
      try {
        const r = await api.post('receipts.php', { action: 'set_title', bill_id: this.billId, receipt_id: rc.id, title });
        this.receipts = r.receipts;
      } catch (err) {
        Setlo.toast(err.message);
        e.target.value = rc.title || '';
      }
    },
    /**
     * After a scan, once per bill: save these people as a group (so the next bill starts from it), with Fun Mode on
     * or off. Fun Mode on makes Continue go to a game that decides who pays. Guests aren't added (no account).
     */
    async askSaveGroup(r) {
      const key = 'setlo-group-asked-' + this.billId;
      try { if (localStorage.getItem(key)) return; localStorage.setItem(key, '1'); } catch (e) { /* storage blocked: may ask again */ }
      const people = r.members.filter((m) => !m.is_guest);
      const guests = r.members.length - people.length;
      const names = people.map((m) => (m.id === r.me.id ? 'You' : m.first));
      const list = names.length > 1 ? names.slice(0, -1).join(', ') + ' & ' + names[names.length - 1] : names[0];
      const suggested = people.map((m) => m.first).join(', ').slice(0, 60);
      const esc = Setlo.escapeHtml;
      const res = await Swal.fire({
        customClass: { popup: 'setlo-swal' }, reverseButtons: true,
        confirmButtonColor: window.SetloTheme ? SetloTheme.color(600) : '#0d9488', cancelButtonColor: '#94a3b8',
        icon: 'question', title: 'Save as a group?',
        html: `<p class="text-[13.5px] text-slate-500">${esc(list)} — next time, start a bill from the group instead of adding everyone again.${guests ? ' Guests aren’t added (no account).' : ''}</p>
          <input id="grp-name" class="swal2-input" maxlength="60" value="${esc(suggested)}" placeholder="Group name, e.g. Barkada" aria-label="Group name" />
          <label class="mx-auto mt-3 flex max-w-[22rem] cursor-pointer items-start gap-2.5 rounded-2xl bg-fuchsia-50 px-3.5 py-3 text-left text-[13px] leading-snug text-fuchsia-900">
            <input id="grp-fun" type="checkbox" class="mt-0.5 h-4 w-4 shrink-0 accent-fuchsia-600" />
            <span><b>🎉 Turn on Fun Mode</b><br /><span class="text-fuchsia-700">After the review, a quick game (Receipt Race, Roulette, Mystery Card…) decides who pays. You can always skip it.</span></span>
          </label>`,
        showCancelButton: true, confirmButtonText: 'Save group', cancelButtonText: 'Not now', focusConfirm: false,
        preConfirm: async () => {
          const name = document.getElementById('grp-name').value.trim().replace(/\s+/g, ' ');
          if (name.length < 2 || /[<>]/.test(name)) { Swal.showValidationMessage('Name it in 2–60 characters, no < or >.'); return false; }
          try {
            return await api.post('bills.php', { action: 'save_as_group', bill_id: this.billId, name, fun_mode: document.getElementById('grp-fun').checked });
          } catch (e) { Swal.showValidationMessage(e.message); return false; }
        },
      });
      if (!res.isConfirmed || !res.value) return;
      this.bill.fun_mode = res.value.fun_mode;
      Setlo.toast(res.value.fun_mode ? 'Group saved · Fun Mode on 🎉 — Continue starts the game' : 'Group saved', 'ok');
    },
    /** Put what's still short of the total on this payer's amount. */
    // Amount tendered and change are both typed by hand when needed: they share one state, so editing either updates the other.
    // While a field is focused it shows exactly what was typed (no reformatting under the cursor).
    fieldText(which) {
      if (this.focused === which) return this.typed;
      if (which === 'tendered') return this.payers.length > 1 ? (this.payTotal > 0 ? this.payTotal.toFixed(2) : '') : this.tendered;
      return this.tenderedAmount > 0 ? this.change.toFixed(2) : '';
    },
    focusField(which) { this.focused = which; this.typed = this.fieldText(which); },
    blurField() { this.focused = null; },
    /** Set what was paid at the cashier. Several payers: it is divided evenly between them (centavos to the first ones). */
    setTendered(v) {
      if (this.payers.length <= 1) { this.tendered = v; return; }
      const n = this.payers.length;
      const total = v === '' ? 0 : cents(v);
      this.payers.forEach((m, i) => {
        const part = Math.floor(total / n) + (i < total % n ? 1 : 0);
        this.payDraft[m.id] = (part / 100).toFixed(2);
      });
    },
    typeTendered(e) {
      this.typed = Setlo.filterMoney(e.target.value);
      e.target.value = this.typed;
      this.setTendered(this.typed);
      this.touch('tendered');
    },
    typeChange(e) {
      this.typed = Setlo.filterMoney(e.target.value);
      e.target.value = this.typed;
      // tendered = total due + change (the change is what came back from the cash handed over)
      this.setTendered(this.typed === '' ? '' : (Math.round((this.total + money(this.typed)) * 100) / 100).toFixed(2));
    },
    /** An amount in the receipt's currency, in the settlement currency (what is stored). */
    toSettle(v) { return Math.round(v * this.rate * 100) / 100; },
    settlePayments() { return Object.fromEntries(Object.entries(this.payAmounts).map(([id, v]) => [id, this.toSettle(v)])); },
    fillShort(m) {
      this.payDraft[m.id] = (money(this.payDraft[m.id] || 0) + this.payShort).toFixed(2);
      this.touch('pay' + m.id);
    },
    /** Correct the receipt currency (edits so far are saved first; prices are kept as typed). */
    async setReceiptCurrency(code) {
      if (code === this.receiptCur) return;
      const done = await Setlo.run(this, async () => {
        if (!(await this.saveDraft())) return false;
        await api.post('receipts.php', { action: 'set_currency', bill_id: this.billId, currency: code });
        await this.reload();
        return true;
      });
      if (done) Setlo.toast('Receipt currency: ' + code, 'ok');
    },
    async keepReceipt(rc) {
      const r = await Setlo.run(this, () => api.post('receipts.php', { action: 'keep_receipt', bill_id: this.billId, receipt_id: rc.id }));
      if (r) this.receipts = r.receipts;
    },
    async save() {
      const flagged = this.rows.find((r) => r.needs_review);
      if (flagged) return this.flash(flagged, 'Confirm the highlighted item first');
      if (!this.rows.length) return Setlo.toast('Add at least one item.', 'warn');
      if (!this.validateAll()) return;
      // Change is never assumed to be with anyone: ask before moving on (adding a receipt may still change it)
      if (this.change > 0 && !this.changeHolder) {
        this.holderAsked = true;
        document.querySelector('[data-field="holder"]')?.scrollIntoView({ block: 'center', behavior: 'smooth' });
        Setlo.toast('Choose who received the change.', 'warn');
        return;
      }

      const r = await Setlo.run(this, () => api.post('items.php', this.payload()));
      if (r) location.href = r.redirect;
    },
  },
});
</script>
<?php require __DIR__ . '/../partials/foot.php'; ?>
