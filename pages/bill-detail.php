<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_login();
$title = 'Bill Detail';
$nav = 'bills';
$billId = (int) ($_GET['bill'] ?? 0);
if (!$billId) {
    redirect('pages/my-bills');
}
$back = 'my-bills.php';
$headExtra = ['assets/js/summary.js', 'assets/js/replay.js', 'assets/css/print.css'];
require __DIR__ . '/../partials/head.php';
?>
<div id="app" class="device" v-cloak>

  <div class="app-hero px-5 pb-5 pt-8" :class="{ 'fun-hero': bill && bill.fun_mode }">
    <div class="flex items-center gap-3">
      <?php require __DIR__ . '/../partials/back.php'; ?>
      <div class="min-w-0 flex-1">
        <h1 class="flex items-center gap-2 text-[19px] font-extrabold leading-tight tracking-tight"><span class="truncate">{{ bill ? bill.name : '' }}</span><span v-if="bill && bill.fun_mode" class="fun-chip">🎉 Fun</span></h1>
        <p v-if="bill" class="text-[12px] font-medium text-brand-50/90">Created by {{ d.me.is_creator ? 'You' : creator.first }} · {{ fmtDate(bill.created_at) }}</p>
      </div>
      <status-pill v-if="bill" :status="bill.status"></status-pill>
    </div>
    <div class="seg mt-5">
      <button v-for="t in tabs" :key="t.key" :class="{ on: tab === t.key }" @click="tab = t.key">{{ t.label }}</button>
    </div>
  </div>

  <spinner v-if="loading"></spinner>
  <div v-else class="flex-1 space-y-4 px-5 pb-6 pt-4 lg:grid lg:grid-cols-[minmax(0,1fr)_300px] lg:items-start lg:gap-5 lg:space-y-0">
    <div class="space-y-4">

    <!-- Members & balances -->
    <template v-if="tab === 'members'">
      <div class="tile divide-y divide-slate-100">
        <div v-for="m in d.members" :key="m.id" class="flex items-center gap-3 p-3.5">
          <button @click="showPerson(m.id)" class="shrink-0 rounded-full" :aria-label="'About ' + m.name"><avatar :user="m" :size="36"></avatar></button>
          <div class="min-w-0 flex-1">
            <p class="text-sm font-semibold text-ink"><button @click="showPerson(m.id)" class="text-left hover:underline">{{ m.name }}</button> <status-badge :badge="m.badge"></status-badge> <span v-if="m.is_guest" class="pill pill-draft">Guest</span>
              <span v-if="m.id === me || m.id === bill.creator_id" class="text-[10px] font-medium text-brand-600">({{ [m.id === me ? 'You' : '', m.id === bill.creator_id ? 'Creator' : ''].filter(Boolean).join(' · ') }})</span>
            </p>
            <p class="text-xs text-slate-400">
              <template v-if="d.payments.paid[m.id]">Paid {{ peso(d.payments.paid[m.id]) }} · </template>share {{ peso(d.shares[m.id] || 0) }}<template v-if="d.share_estimates && d.share_estimates[m.id]"> ≈ {{ peso(d.share_estimates[m.id].amount, d.share_estimates[m.id].currency) }}</template><template v-if="m.is_guest"> · guest, no account</template>
            </p>
            <button v-if="m.is_guest && d.me.is_creator" type="button" @click="guestLink(m)" class="mt-1 text-[11.5px] font-bold text-brand-700 hover:underline"><svg class="ic" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" style="display:inline;margin-right:4px;vertical-align:-2px"><path stroke-linecap="round" stroke-linejoin="round" d="M10 14a4 4 0 005.7 0l3-3a4 4 0 00-5.7-5.7l-1 1M14 10a4 4 0 00-5.7 0l-3 3a4 4 0 005.7 5.7l1-1"/></svg>Share {{ m.first }}’s link</button>
          </div>
          <p v-if="(d.balances[m.id] || 0) > 0" class="text-right text-sm font-bold text-emerald-600">+{{ peso(d.balances[m.id]) }}<span class="block text-[10px] font-medium text-slate-400">gets back</span></p>
          <p v-else-if="(d.balances[m.id] || 0) < 0" class="text-right text-sm font-bold text-red-600">−{{ peso(-d.balances[m.id]) }}<span class="block text-[10px] font-medium text-slate-400">owes</span></p>
          <p v-else class="text-sm font-bold text-slate-400">{{ peso(0) }}</p>
          <button v-if="canEdit && m.id !== bill.creator_id && !d.payments.paid[m.id]" @click="removeMember(m)" class="ml-1 text-slate-300 hover:text-rose-500" :aria-label="'Remove ' + m.name">✕</button>
        </div>
      </div>

      <div v-if="canEdit" class="space-y-3">
        <!-- Invite by link / QR -->
        <div class="tile p-4">
          <div class="flex items-center justify-between gap-3">
            <div>
              <p class="text-[14px] font-extrabold text-ink">Invite link</p>
              <p class="text-[12px] text-slate-400">{{ bill.invite_code ? 'Anyone with this link can join until settling starts.' : 'Let friends join by link or QR — no searching needed.' }}</p>
            </div>
            <button v-if="!bill.invite_code" @click="invite('enable')" class="btn btn-primary btn-sm shrink-0" :disabled="busy">Create link</button>
          </div>
          <template v-if="bill.invite_code">
            <div class="mt-3 flex items-center gap-2 rounded-xl bg-slate-50 px-3 py-2">
              <span class="min-w-0 flex-1 truncate text-[12px] font-medium text-slate-600">{{ inviteUrl }}</span>
              <button @click="copyInvite" class="shrink-0 text-[12px] font-bold text-brand-700">{{ copied ? 'Copied ✓' : 'Copy' }}</button>
            </div>
            <div class="mt-3 flex items-center gap-4">
              <div class="h-28 w-28 shrink-0 rounded-xl border border-slate-200 bg-white p-1.5" v-html="inviteQr" role="img" aria-label="Invite QR code"></div>
              <div class="flex-1 space-y-1.5 text-[12px]">
                <p class="text-slate-500">Show this QR at the table — friends scan it with their phone camera.</p>
                <button v-if="canShare" @click="shareInvite" class="block font-bold text-brand-700">Share…</button>
                <button @click="invite('rotate')" class="block font-bold text-slate-500" :disabled="busy">New link</button>
                <button @click="invite('disable')" class="block font-bold text-rose-600" :disabled="busy">Turn off</button>
              </div>
            </div>
          </template>
        </div>
        <div class="input-wrap">
          <svg class="input-lead h-[18px] w-[18px] text-slate-400" fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="M20 20l-3.5-3.5"/></svg>
          <input v-model="search" @input="lookup" class="input-soft has-lead !bg-white" aria-label="Invite member" placeholder="Invite another member by name or email" />
        </div>
        <div v-if="results.length" class="tile divide-y divide-slate-100 overflow-hidden">
          <button v-for="u in results" :key="u.id" @click="addMember(u)" class="flex w-full items-center gap-3 px-3.5 py-2.5 text-left hover:bg-slate-50">
            <person-result :user="u"></person-result>
            <span class="text-[12px] font-bold text-brand-700">Add</span>
          </button>
        </div>
        <div class="flex gap-2">
          <input data-field="guest" data-filter="name" v-model="guestName" @input="touch('guest')" @keydown.enter.prevent="addGuest" :class="{ 'is-invalid': guestName && err('guest') }" class="input-soft !bg-white flex-1" maxlength="100" placeholder="Or add a guest (no account)" aria-label="Guest name" />
          <button @click="addGuest" class="btn btn-outline shrink-0" :disabled="busy || !guestName">Add guest</button>
        </div>
        <p v-if="guestName && err('guest')" class="field-error !mt-1">{{ err('guest') }}</p>
        <div class="tile p-4">
          <div class="flex items-center justify-between gap-3">
            <p class="text-[14px] font-extrabold text-ink">Who paid the bill?</p>
            <div class="seg seg-light w-44 !p-0.5">
              <button :class="{ on: !multiPay }" @click="useSinglePayer">One person</button>
              <button :class="{ on: multiPay }" @click="startMultiPay">Several</button>
            </div>
          </div>
          <div v-if="!multiPay" class="mt-3">
            <payer-picker :people="realMembers" :model-value="[bill.payer_id]" :me="me" :multiple="false" @update:model-value="setPayer($event[0])"></payer-picker>
            <p class="mt-1.5 text-[12px] text-slate-400">{{ payer.id === me ? 'You' : payer.name }} paid {{ peso(exactTotal) }}. Everyone else settles with this person.</p>
          </div>
          <template v-else>
            <div class="mt-3 space-y-2">
              <label v-for="m in payRows" :key="m.id" class="flex items-center gap-3">
                <avatar :user="m" :size="28"></avatar>
                <span class="flex-1 text-[13px] font-semibold">{{ m.id === me ? 'You' : m.name }}</span>
                <span class="text-slate-400">{{ currencySymbol(bill.currency) }}</span>
                <input :data-field="'pay' + m.id" :value="payDraft[m.id]" @input="payDraft[m.id] = filterMoney($event.target.value); $event.target.value = payDraft[m.id]; touch('pay' + m.id)" inputmode="decimal" placeholder="0.00" class="row-in !h-9 w-28 text-right" :class="{ 'is-invalid': err('pay' + m.id) }" :aria-label="'Amount paid by ' + m.name" />
              </label>
            </div>
            <p class="mt-2 text-[12px] font-semibold" :class="payDraftOk ? 'text-brand-600' : 'text-rose-500'">
              {{ peso(payDraftTotal) }} handed to the cashier of {{ peso(exactTotal) }} due{{ payDraftOk ? ' ✓' : ' — ' + peso(exactTotal - payDraftTotal) + ' short' }}
            </p>
            <!-- More than the bill was handed over: who the cashier gave the change to (never assumed) -->
            <div v-if="draftChange > 0" class="mt-3 rounded-2xl border border-amber-200 bg-amber-50/70 p-3">
              <p class="text-[13px] font-extrabold text-amber-900">{{ peso(draftChange) }} change — who received it?</p>
              <change-holder-picker class="mt-2" :people="d.members" v-model="holderDraft" :me="me" :paid="payDraftAmounts"></change-holder-picker>
            </div>
            <button type="button" @click="splitEvenly" class="mt-2 text-[12px] font-bold text-brand-700 hover:underline">Split evenly between {{ evenIds.length }} {{ evenIds.length === 1 ? 'person' : 'people' }}</button>
            <button @click="savePayments" class="btn btn-primary btn-sm mt-3 w-full" :disabled="busy">Save payments</button>
          </template>
        </div>
      </div>
    </template>

    <!-- Settlement plan -->
    <template v-if="tab === 'plan'">
      <!-- Rounded to whole pesos: what was charged, and who keeps or covers the difference -->
      <p v-if="bill.round_diff" class="rounded-xl bg-slate-50 px-3.5 py-2.5 text-[12px] text-slate-600">
        <b class="text-ink">Rounded to whole {{ bill.currency === 'PHP' ? 'pesos' : 'amounts' }}:</b> {{ peso(bill.exact_total) }} charged → {{ peso(d.total) }} shared.
        {{ payer.id === me ? 'You' : payer.first }} {{ bill.round_diff > 0 ? (payer.id === me ? 'keep' : 'keeps') : (payer.id === me ? 'cover' : 'covers') }} the {{ peso(Math.abs(bill.round_diff)) }}.
      </p>
      <!-- The change: in total, who holds it, and each person's part of it -->
      <template v-if="d.payments.change > 0">
        <change-summary :payments="d.payments" :shares="d.shares" :members="d.members" :me="me"></change-summary>
        <div v-if="canEdit && !d.payments.change_holder" class="tile p-4">
          <p class="text-[13.5px] font-extrabold text-ink">Who received the {{ peso(d.payments.change) }} change?</p>
          <p class="mt-0.5 text-[12px] text-slate-400">Whoever the cashier handed it to. Needed before the bill can start settling.</p>
          <change-holder-picker class="mt-2.5" :people="d.members" :model-value="null" :me="me" :paid="d.payments.paid" :disabled="busy" @update:model-value="setHolder"></change-holder-picker>
        </div>
        <button v-else-if="canEdit" type="button" class="-mt-1 px-1 text-[12px] font-bold text-brand-700 hover:underline" @click="setHolder(0)">Change who has it</button>
      </template>
      <div v-if="!d.settlements.length" class="tile p-4">
        <p class="text-sm font-bold text-ink">Proposed transfers</p>
        <p class="mt-0.5 text-[12px] text-slate-400">Final once the creator confirms the split. Uses the fewest payments possible.</p>
        <p v-if="d.unassigned_count" class="mt-3 text-[12px] font-semibold text-rose-500">Assign every item to see the plan.</p>
        <p v-else-if="d.payments.mismatch" class="mt-3 text-[12px] font-semibold text-rose-500">Payments entered ({{ peso(d.payments.total) }}) don't cover the bill total ({{ peso(exactTotal) }}).</p>
        <p v-else-if="d.payments.change > 0 && !d.payments.change_holder" class="mt-3 text-[12px] font-semibold text-amber-600">Choose who received the change to see the final plan.</p>
        <div v-else class="mt-3 space-y-2">
          <div v-for="(t, i) in d.plan" :key="i" class="flex items-center gap-2.5 rounded-xl bg-slate-50 px-3 py-2">
            <avatar :user="memberMap[t.from]" :size="28"></avatar>
            <svg class="h-4 w-4 text-slate-300" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
            <avatar :user="memberMap[t.to]" :size="28"></avatar>
            <span class="flex-1 text-[13px] text-slate-600">{{ memberMap[t.from].first }} pays {{ memberMap[t.to].first }}</span>
            <span class="text-[13px] font-bold">{{ peso(t.amount) }}</span>
          </div>
          <p v-if="!d.plan.length" class="text-[12px] text-slate-400">Nobody owes anyone.</p>
        </div>
      </div>
      <p v-if="d.settlements.length && bill.interest_rate" class="rounded-xl bg-amber-50 px-3.5 py-2.5 text-[12px] text-amber-800">
        <b>Installments on:</b> each partial payment adds {{ bill.interest_rate }}% of what's left.
      </p>
      <div v-for="s in d.settlements" :key="s.id" class="tile p-3.5">
        <a :href="'settlement-audit.php?id=' + s.id" class="flex items-center justify-between">
          <div class="flex items-center gap-2.5">
            <avatar :user="s.from" :size="32"></avatar>
            <svg class="h-4 w-4 text-slate-300" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
            <avatar :user="s.to" :size="32"></avatar>
            <p class="ml-1 text-sm font-semibold">{{ peso(s.amount) }}<span v-if="s.local" class="block text-[10.5px] font-medium text-slate-400">= {{ peso(s.local.amount, s.local.currency) }} for {{ s.from.first }}</span></p>
          </div>
          <status-pill :status="s.status"></status-pill>
        </a>
        <div v-if="s.paid_amount > 0 && s.status !== 'settled'" class="mt-2.5">
          <div class="progress !h-1.5"><span :style="{ width: Math.min(100, (100 * s.paid_amount) / s.amount) + '%' }"></span></div>
          <p class="mt-1 text-[11px] text-slate-500">{{ peso(s.paid_amount) }} paid · {{ peso(s.remaining) }} left<span v-if="s.interest_added > 0"> · incl. {{ peso(s.interest_added) }} interest</span></p>
        </div>
        <div v-if="canCover(s)" class="mt-2 text-right">
          <a :href="'my-settlements?cover=' + s.id" class="text-[12px] font-bold text-brand-700">Pay for {{ s.from.first }} →</a>
        </div>
      </div>
      <a v-if="d.settlements.length && bill.status !== 'closed'" href="my-settlements" class="block pt-1 text-center text-xs font-semibold text-brand-600">Manage in My Settlements →</a>
    </template>

    <!-- Items -->
    <template v-if="tab === 'items'">
      <div class="tile divide-y divide-slate-100">
        <template v-for="g in itemGroups" :key="g.key">
        <p v-if="g.label" class="bg-slate-50 px-3.5 py-2 text-[11.5px] font-extrabold tracking-[.04em] text-slate-500">{{ g.label }}</p>
        <div v-for="it in g.items" :key="it.id" class="flex items-center justify-between gap-3 p-3.5">
          <div class="min-w-0">
            <p class="text-sm font-medium">{{ it.name }}{{ it.qty > 1 ? ' ×' + it.qty : '' }}</p>
            <p class="text-xs text-slate-400">{{ it.who.length ? it.who.map((id) => memberMap[id].first).join(', ') : bill.split_mode === 'percent' ? 'Split by percentage' : bill.split_mode === 'game' ? 'Decided by a game' : 'Unassigned' }}</p>
          </div>
          <p class="shrink-0 text-sm font-semibold">{{ peso(it.line_total, rcur) }}</p>
        </div>
        </template>
        <p v-if="!d.items.length" class="p-4 text-center text-[13px] text-slate-400">No items yet.</p>
      </div>
      <a :href="(bill.status === 'closed' ? 'bill-breakdown.php' : 'bill-items.php') + '?bill=' + billId" class="btn btn-outline w-full">Open full itemalized view</a>
    </template>

    </div>

    <!-- Side column on desktop: totals, share, creator actions -->
    <div class="space-y-4 lg:sticky lg:top-6">
    <!-- Currency: the agreed settlement currency and, for a receipt in another one, the settlement rate -->
    <div id="currency" class="tile p-3.5 text-[13px]">
      <div class="flex items-center justify-between gap-2">
        <p class="font-extrabold text-ink">Currency</p>
        <span v-if="!canEdit" class="font-bold text-ink">{{ currencyLabel(bill.currency) }}</span>
      </div>
      <p class="mt-0.5 text-[11.5px] leading-snug text-slate-400">Everyone settles in {{ bill.currency }}{{ bill.currency === payer.currency ? ' — ' + (payer.id === me ? 'your' : payer.first + '’s') + ' currency, as the payer' : ' — agreed by the group' }}.</p>
      <div v-if="canEdit" class="mt-2"><currency-select :model-value="bill.currency" @update:model-value="setCurrency" :disabled="busy" label="Settlement currency"></currency-select></div>

      <template v-if="fx.foreign">
        <div class="mt-3 space-y-1 rounded-xl bg-slate-50 p-3">
          <div class="flex justify-between"><span class="text-slate-500">Receipt</span><b>{{ peso(fx.native_total, rcur) }}</b></div>
          <p v-if="fx.missing" class="text-[11px] font-semibold text-rose-500">No exchange rate yet — it is fetched automatically. Reload in a moment.</p>
          <div class="flex justify-between border-t border-slate-200 pt-1 font-extrabold text-ink"><span>Settles as</span><span>{{ fx.missing ? '—' : peso(d.total) }}</span></div>
          <p v-if="!fx.missing" class="text-[11px] leading-snug text-slate-400">Rate used: {{ fmtRate(fx.rate, rcur, bill.currency) }} · {{ fx.source }}{{ fx.at ? ' · ' + fmtDateTime(fx.at) : '' }}</p>
        </div>

      </template>

    </div>

    <div class="tile space-y-1 bg-slate-50 p-3.5 text-sm">
      <div v-if="bill.discount > 0" class="flex justify-between"><span class="text-slate-500">Receipt discount</span><span class="font-semibold text-emerald-600">−{{ peso(bill.discount, rcur) }}</span></div>
      <div v-if="fx.foreign" class="flex justify-between"><span class="text-slate-500">On the receipt</span><span class="font-semibold">{{ peso(d.native_total, rcur) }}</span></div>
      <div class="flex justify-between"><span class="text-slate-500">Bill total<template v-if="fx.foreign"> in {{ bill.currency }}</template></span><span class="font-semibold">{{ fx.missing ? 'no rate yet' : peso(d.total) }}</span></div>
      <div v-if="bill.receipt_total !== null" class="flex justify-between"><span class="text-slate-500">Receipt total (printed)</span><span class="font-semibold">{{ peso(bill.receipt_total, rcur) }}</span></div>
      <div v-if="bill.split_mode === 'percent'" class="flex justify-between"><span class="text-slate-500">Split</span>
        <span class="font-semibold" :class="bill.percent_total === 100 ? 'text-emerald-600' : 'text-rose-500'">By percentage{{ bill.percent_total === 100 ? ' ✓' : ' · ' + bill.percent_total + '% set' }}</span>
      </div>
      <div v-else-if="bill.split_mode === 'game'" class="flex justify-between"><span class="text-slate-500">Split</span>
        <span class="font-semibold text-fuchsia-600">{{ bill.game ? bill.game.title : '🎉 Fun Mode game' }}</span>
      </div>
      <div v-else class="flex justify-between"><span class="text-slate-500">Fully assigned</span>
        <span class="font-semibold" :class="d.unassigned_count ? 'text-rose-500' : 'text-emerald-600'">{{ d.unassigned_count ? peso(d.unassigned) + ' left' : 'Yes ✓' }}</span>
      </div>
    </div>

    <bill-replay v-if="d.items.length" :d="d"></bill-replay>
    <share-summary v-if="d.items.length" :d="d"></share-summary>

    <div v-if="d.me.can_assign && !bill.locked" class="grid grid-cols-2 gap-3">
      <a :href="'review-items.php?bill=' + billId" class="btn btn-outline">Edit items</a>
      <a :href="'assign-items.php?bill=' + billId" class="btn btn-primary">Assign items</a>
      <button v-if="d.me.is_creator" @click="remove" class="btn btn-ghost col-span-2 !text-rose-600">Delete this bill</button>
    </div>
    <!-- Settling but nobody has paid yet: the creator can still undo a mistake -->
    <div v-else-if="bill.locked && d.me.is_creator && !bill.money_moved" class="tile p-3.5">
      <p class="text-[13px] font-extrabold text-ink">Made a mistake?</p>
      <p class="mt-0.5 text-[12px] text-slate-500">Nobody has paid yet, so you can take the settlement plan back and fix the bill, or delete it. Everyone on it is told.</p>
      <div class="mt-3 grid grid-cols-2 gap-2">
        <button @click="reopen" class="btn btn-outline btn-sm" :disabled="busy">Fix the bill</button>
        <button @click="remove" class="btn btn-danger btn-sm" :disabled="busy">Delete bill</button>
      </div>
    </div>
    <div v-else-if="bill.locked && d.me.is_creator" class="rounded-xl border border-slate-200 bg-slate-50 p-3.5">
      <p class="text-xs text-slate-500"><b class="text-slate-700">Editing locked.</b> Payments have been made on this bill, so it stays as their record. {{ bill.status === 'closed' ? 'Archive it to clear it from your lists.' : 'Once everything is settled you can archive it.' }}</p>
    </div>
    <button v-if="bill.status === 'closed'" @click="toggleArchive" class="btn btn-ghost w-full" :disabled="busy">{{ bill.archived ? 'Unarchive this bill' : 'Archive this bill' }}</button>
    </div>
  </div>

  <?php require __DIR__ . '/../partials/nav.php'; ?>
</div>

<script>
Setlo.mount({
  mixins: [Setlo.validation],
  data: () => ({
    billId: <?= $billId ?>, me: <?= (int) $user['id'] ?>,
    loading: true, busy: false, bill: null, d: { me: {}, members: [], items: [], settlements: [], shares: {}, balances: {}, plan: [], payments: { paid: {}, multi: false } },
    tab: 'members', tabs: [{ key: 'members', label: 'Members' }, { key: 'plan', label: 'Settlement' }, { key: 'items', label: 'Items' }],
    search: '', results: [], timer: null, guestName: '', multiPay: false, payDraft: {}, holderDraft: null, copied: false, canShare: !!navigator.share,
  }),
  computed: {
    /** How the receipt's currency becomes the settlement currency (bills.php bill.fx); rcur: the receipt's currency. */
    fx() { return (this.bill && this.bill.fx) || { foreign: false, rounding_options: {} }; },
    rcur() { return this.bill ? this.bill.receipt_currency || this.bill.currency : 'PHP'; },
    /** Items under their receipt's title when the bill has several receipts (see Setlo.groupByReceipt). */
    itemGroups() { return Setlo.groupByReceipt(this.d.items, this.d.receipts); },
    memberMap() { return Object.fromEntries(this.d.members.map((m) => [m.id, m])); },
    creator() { return this.memberMap[this.bill.creator_id] || {}; },
    payer() { return this.memberMap[this.bill.payer_id] || {}; },
    inviteUrl() {
      return this.bill && this.bill.invite_code ? new URL('join.php?code=' + this.bill.invite_code, location.href).href : '';
    },
    inviteQr() {
      if (!this.inviteUrl || !window.qrcode) return '';
      const qr = qrcode(0, 'M');
      qr.addData(this.inviteUrl);
      qr.make();
      return qr.createSvgTag({ cellSize: 4, margin: 0, scalable: true });
    },
    realMembers() { return this.d.members.filter((m) => !m.is_guest); },
    /** Who paid, as named when the bill was created (or everyone, if it was never several). */
    evenIds() { return this.d.payments.multi ? this.d.payments.payer_ids : this.realMembers.map((m) => m.id); },
    /** The people who paid first, then everyone else, for the amount rows. */
    payRows() {
      const first = this.d.payments.multi ? this.d.payments.payer_ids : [];
      return [...this.realMembers].sort((a, b) => (first.includes(b.id) - first.includes(a.id)));
    },
    payDraftTotal() { return Math.round(Object.values(this.payDraft).reduce((s, v) => s + (parseFloat(v) || 0), 0) * 100) / 100; },
    payDraftAmounts() { return Object.fromEntries(Object.entries(this.payDraft).map(([k, v]) => [k, parseFloat(v) || 0])); },
    /** What was really charged (before any "round to whole pesos"): what the payments have to cover. */
    exactTotal() { return this.bill ? this.bill.exact_total ?? this.d.total : 0; },
    /** What was paid at the cashier (amount tendered): the payers divide this, not just the bill total. Never less than the total. */
    paidBase() {
      const t = this.d.payments.tendered != null ? this.d.payments.tendered : (this.d.payments.multi ? this.d.payments.total : 0);
      return Math.max(this.exactTotal, t || 0);
    },
    /** The amounts are the cash handed to the cashier (amount tendered): they only have to cover the total. */
    payDraftOk() { return this.payDraftTotal >= this.exactTotal - 0.005; },
    draftChange() { return this.payDraftOk ? Math.round((this.payDraftTotal - this.exactTotal) * 100) / 100 : 0; },
    canEdit() { return this.d.me.is_creator && !this.bill.locked; },
    /** Another member's open debt that I could pay for them (guests' debts are the creator's own to pay). */
    canCover() {
      return (s) => s.from.id !== this.me && s.to.id !== this.me && ['pending', 'awaiting'].includes(s.status) && s.remaining > 0
        && !(s.from.is_guest && this.d.me.is_creator);
    },
    owedToPayer() {
      return this.d.members.filter((m) => m.id !== this.bill.payer_id).reduce((s, m) => s + (this.d.shares[m.id] || 0), 0);
    },
  },
  async mounted() {
    await Setlo.load(this, 'bills.php', { id: this.billId }, (r) => {
      if (r.bill.kind === 'loan') { location.replace('utang'); return; } // an utang has its own page
      this.fill(r);
      if (this.bill.locked) this.tab = 'plan';
    });
  },
  methods: {
    /** Show a bills.php reply; amounts default to the bill's settlement currency, receipt amounts pass rcur. */
    fill(r) {
      Setlo.useCurrency(r.bill.currency);
      this.d = r;
      this.bill = r.bill;
      this.multiPay = r.payments.multi;
    },
    async setCurrency(code) {
      if (code === this.bill.currency) return;
      const ok = await Setlo.confirm({
        title: 'Settle in ' + code + '?', confirmText: 'Use ' + code,
        text: 'Everyone will pay their share in ' + code + '. Several payers’ amounts typed in ' + this.bill.currency + ' are cleared.',
      });
      if (!ok) return;
      await Setlo.run(this, async () => { await api.post('bills.php', { action: 'set_currency', bill_id: this.billId, currency: code }); await this.load(); Setlo.toast('Settles in ' + code, 'ok'); });
    },
    showPerson(id) { Setlo.showPerson(id); },
    async invite(mode) {
      if (mode === 'rotate' && !(await Setlo.confirm({ title: 'Create a new invite link?', text: 'The current link and QR will stop working.', confirmText: 'New link', danger: true }))) return;
      if (mode === 'disable' && !(await Setlo.confirm({ title: 'Turn off the invite link?', text: 'Nobody new can join with it until you create a link again.', confirmText: 'Turn off', danger: true }))) return;
      const r = await Setlo.run(this, () => api.post('bills.php', { action: 'invite', mode, bill_id: this.billId }));
      if (r) this.bill.invite_code = r.invite_code;
    },
    async copyInvite() {
      try {
        await navigator.clipboard.writeText(this.inviteUrl);
      } catch (e) {
        Setlo.showCopy('Invite link', this.inviteUrl);
      }
      this.copied = true;
      setTimeout(() => { this.copied = false; }, 1800);
    },
    shareInvite() {
      navigator.share({ title: 'Join ' + this.bill.name + ' on Setlo', url: this.inviteUrl }).catch(() => {});
    },
    async load() {
      this.fill(await api.get('bills.php', { id: this.billId }));
    },
    lookup() {
      clearTimeout(this.timer);
      const q = this.search.trim();
      if (q.length < 2) { this.results = []; return; }
      this.timer = setTimeout(async () => {
        const ids = this.d.members.map((m) => m.id);
        try { this.results = (await api.get('users.php', { q })).users.filter((u) => !ids.includes(u.id)); } catch (e) { Setlo.toast(e.message); }
      }, 250);
    },
    useSinglePayer() {
      this.multiPay = false;
      if (this.d.payments.multi) this.setPayer(this.bill.payer_id);
    },
    startMultiPay() {
      this.multiPay = true;
      this.payDraft = Object.fromEntries(this.realMembers.map((m) => [m.id, this.d.payments.multi && this.d.payments.paid[m.id] ? this.d.payments.paid[m.id].toFixed(2) : '']));
      this.holderDraft = this.d.payments.change_holder ?? null;
      // Nothing entered yet and an amount tendered is known (scanned or typed on Review): the payers divide that amount
      if (this.d.payments.tendered != null && !Object.values(this.payDraft).some((v) => parseFloat(v) > 0)) this.splitEvenly();
    },
    /** Fill the amounts with an equal split of the amount tendered (any odd centavos go to the first people). */
    splitEvenly() {
      const ids = this.evenIds;
      const cents = Math.round(this.paidBase * 100);
      const base = Math.floor(cents / ids.length);
      const extra = cents - base * ids.length;
      this.payDraft = Object.fromEntries(this.realMembers.map((m) => [m.id, ids.includes(m.id) ? ((base + (ids.indexOf(m.id) < extra ? 1 : 0)) / 100).toFixed(2) : '']));
    },
    /** Who received the change (0: pick again). */
    async setHolder(id) {
      await Setlo.run(this, async () => { await api.post('bills.php', { action: 'set_change_holder', bill_id: this.billId, user_id: id }); await this.load(); });
    },
    async savePayments() {
      if (!this.validateAll(this.realMembers.map((m) => 'pay' + m.id))) return;
      if (!this.payDraftOk) { Setlo.toast('The amounts must cover at least ' + this.peso(this.exactTotal) + '.'); return; }
      if (this.draftChange > 0 && !this.holderDraft) { Setlo.toast('Choose who received the change.', 'warn'); return; }
      const payments = Object.fromEntries(Object.entries(this.payDraft).filter(([, v]) => parseFloat(v) > 0).map(([k, v]) => [k, parseFloat(v)]));
      await Setlo.run(this, async () => {
        await api.post('bills.php', { action: 'set_payments', bill_id: this.billId, payments, change_holder_id: this.draftChange > 0 ? this.holderDraft : 0 });
        await this.load();
        Setlo.toast('Payments saved', 'ok');
      });
    },
    validators() {
      const v = { guest: V.name(this.guestName, 'Guest name') };
      if (this.multiPay) for (const m of this.realMembers) v['pay' + m.id] = V.money(this.payDraft[m.id], { label: 'Amount' });
      return v;
    },
    /** A private link that opens this guest's share without an account (pages/guest.php): shared, or shown to copy. */
    async guestLink(m) {
      try {
        const r = await api.post('bills.php', { action: 'guest_link', bill_id: this.billId, user_id: m.id });
        const text = `Hi ${m.first}! Here's your share of "${this.bill.name}" on Setlo. No account needed:`;
        if (navigator.share) {
          try { await navigator.share({ title: 'Your share on Setlo', text, url: r.link }); return; }
          catch (e) { if (e.name === 'AbortError') return; }
        }
        Setlo.showCopy(`${m.first}’s private link`, r.link, 'Send this to them. They can open it without an account and will only see their own share.');
      } catch (e) { Setlo.toast(e.message, 'error'); }
    },
    async addGuest() {
      this.touch('guest');
      if (this.vErrors.guest) { Setlo.toast(this.vErrors.guest, 'warn'); return; }
      const name = this.guestName.trim();
      this.guestName = '';
      this.touched.guest = false;
      await Setlo.run(this, async () => { await api.post('bills.php', { action: 'add_guest', bill_id: this.billId, name }); await this.load(); });
    },
    async addMember(u) {
      this.results = this.results.filter((r) => r.id !== u.id);
      await Setlo.run(this, async () => { await api.post('bills.php', { action: 'add_member', bill_id: this.billId, user_id: u.id }); await this.load(); });
    },
    async removeMember(m) {
      if (!(await Setlo.confirm({ title: 'Remove ' + m.name + '?', text: 'Their item assignments on this bill will be cleared.', confirmText: 'Remove', danger: true }))) return;
      await Setlo.run(this, async () => { await api.post('bills.php', { action: 'remove_member', bill_id: this.billId, user_id: m.id }); await this.load(); });
    },
    async setPayer(id) {
      const before = this.bill.currency;
      await Setlo.run(this, async () => { await api.post('bills.php', { action: 'set_payer', bill_id: this.billId, user_id: Number(id) }); await this.load(); });
      // The agreed currency follows the payer's own
      if (this.bill.currency !== before) Setlo.toast('Now settles in ' + this.bill.currency + ', ' + (this.payer.id === this.me ? 'your' : this.payer.first + '’s') + ' currency', 'info');
    },
    async remove() {
      const text = this.bill.locked ? 'The bill and its settlement plan are removed for everyone, and they’re told. This cannot be undone.' : 'It will be removed for everyone. This cannot be undone.';
      if (!(await Setlo.confirm({ title: 'Delete this bill?', text, confirmText: 'Delete bill', danger: true }))) return;
      const r = await Setlo.run(this, () => api.post('bills.php', { action: 'delete', bill_id: this.billId }));
      if (r) location.href = r.redirect;
    },
    async reopen() {
      if (!(await Setlo.confirm({ title: 'Take back the settlement plan?', text: 'The payments asked for are removed and the bill opens for editing again. Everyone on it is told.', confirmText: 'Fix the bill' }))) return;
      const r = await Setlo.run(this, () => api.post('bills.php', { action: 'reopen', bill_id: this.billId }));
      if (r) location.href = r.redirect;
    },
    toggleArchive() { return Setlo.toggleArchive(this.bill); },
  },
});
</script>
<?php require __DIR__ . '/../partials/foot.php'; ?>
