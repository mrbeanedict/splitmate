<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_login();
$title = 'My Profile';
$nav = 'profile';
$back = 'dashboard.php';
$page = 'profile';
require __DIR__ . '/../partials/head.php';
?>
<div id="app" class="device" v-cloak>

  <div class="app-hero px-5 pb-5 pt-8">
    <div class="flex items-center gap-3">
      <?php require __DIR__ . '/../partials/back.php'; ?>
      <h1 class="flex-1 text-[21px] font-extrabold tracking-tight">My Profile</h1>
    </div>
    <div v-if="p" class="mt-5 flex items-center gap-3.5">
      <button @click="picking = true" class="relative shrink-0 rounded-full" aria-label="Change profile picture">
        <avatar :user="p" :size="60" ring></avatar>
        <span class="absolute -bottom-0.5 -right-0.5 flex h-6 w-6 items-center justify-center rounded-full bg-white text-brand-700 shadow ring-2 ring-brand-500/30">
          <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 8a2 2 0 012-2h1.5l1.2-1.8A1 1 0 019.5 4h5a1 1 0 01.8.4L16.5 6H18a2 2 0 012 2v9a2 2 0 01-2 2H6a2 2 0 01-2-2V8z"/><circle cx="12" cy="12.5" r="3.2"/></svg>
        </span>
      </button>
      <div class="min-w-0">
        <p class="truncate text-[17px] font-extrabold">{{ p.name }}</p>
        <p class="truncate text-[12px] text-brand-50/90">{{ p.email }}</p>
        <button @click="picking = true" class="mt-0.5 text-[12px] font-bold text-white underline decoration-white/40 underline-offset-2">Change picture</button>
        <div v-if="featuredBadge" class="mt-1.5 flex"><status-badge :badge="featuredBadge" class="!bg-white/95"></status-badge></div>
      </div>
    </div>
  </div>


  <spinner v-if="loading"></spinner>
  <div v-else class="flex-1 space-y-3 px-5 pb-6 pt-4">

    <!-- Balances: always visible, above the personal details -->
    <div class="tile p-4" v-if="money">
      <div class="grid grid-cols-2 gap-3">
        <div class="rounded-2xl bg-emerald-50 px-3.5 py-3">
          <p class="text-[11px] font-bold uppercase tracking-wide text-emerald-700">Owed to you</p>
          <p class="mt-0.5 text-[20px] font-extrabold tabular-nums text-emerald-800">{{ peso(money.owed_to_me, money.currency) }}</p>
          <p class="text-[11.5px] text-emerald-700/80">{{ money.people.length ? 'from ' + money.people.length + (money.people.length > 1 ? ' people' : ' person') : 'nobody owes you' }}</p>
        </div>
        <div class="rounded-2xl bg-slate-50 px-3.5 py-3">
          <p class="text-[11px] font-bold uppercase tracking-wide text-slate-500">You owe</p>
          <p class="mt-0.5 text-[20px] font-extrabold tabular-nums text-ink">{{ peso(money.i_owe, money.currency) }}</p>
          <a href="my-settlements" class="text-[11.5px] font-semibold text-brand-700">Settle up →</a>
        </div>
      </div>
      <p v-if="money.estimated" class="mt-2 text-[11.5px] text-slate-400">≈ Includes debts in other currencies, estimated in {{ money.currency }} at today's rate.</p>
      <p v-if="money.installments" class="mt-3 rounded-xl bg-amber-50 px-3.5 py-2.5 text-[12.5px] text-amber-800">
        <b>{{ peso(money.installment_owed, money.currency) }}</b> still unpaid on {{ money.installments }} installment{{ money.installments > 1 ? 's' : '' }} owed to you.
      </p>
      <div v-if="money.people.length" class="mt-3 divide-y divide-slate-100">
        <button v-for="u in money.people" :key="u.id" @click="showPerson(u.id)" class="flex w-full items-center gap-3 py-2.5 text-left">
          <avatar :user="u" :size="32"></avatar>
          <span class="min-w-0 flex-1">
            <span class="block truncate text-[13.5px] font-semibold text-ink">{{ u.name }}</span>
            <span v-if="u.installments" class="text-[11.5px] font-semibold text-amber-700">{{ u.installments }} unfinished installment{{ u.installments > 1 ? 's' : '' }} · {{ peso(u.installment_owed, money.currency) }}</span>
          </span>
          <span class="text-[14px] font-extrabold tabular-nums text-ink">{{ peso(u.owed, money.currency) }}</span>
          <svg class="h-4 w-4 text-slate-300" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
        </button>
      </div>
    </div>

    <details class="tile acc">
      <summary><span><span class="acc-title">Personal details</span><span class="acc-sub">Name and account information</span></span><svg class="acc-chev" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6"/></svg></summary>
      <div class="acc-body">
    <form class="space-y-4" @submit.prevent="saveProfile(['full_name'])" novalidate>
      <p class="text-[12px] text-slate-400">The name friends see on bills and settlements.</p>
      <div>
        <label class="field-label" for="name">Full Name</label>
        <input id="name" data-field="full_name" v-model="f.full_name" @input="touch('full_name')" @blur="touch('full_name')"
               class="input-soft" :class="{ 'is-invalid': err('full_name') }" maxlength="100" autocomplete="name" />
        <p v-if="err('full_name')" class="field-error">{{ err('full_name') }}</p>
      </div>
      <button class="btn-pill btn-pill-primary" :disabled="busy">Save name</button>
    </form>

    <form class="mt-5 space-y-4 border-t border-slate-100 pt-4" @submit.prevent="saveUsername" novalidate>
      <div>
        <label class="field-label" for="username">Username</label>
        <div class="relative">
          <span class="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400">@</span>
          <input id="username" data-field="username" v-model.trim="username" @input="touch('username'); usernameErr = ''" @blur="touch('username')"
                 class="input-soft !pl-8" :class="{ 'is-invalid': err('username') || usernameErr }" maxlength="20" autocomplete="username" autocapitalize="none" spellcheck="false" />
        </div>
        <p v-if="err('username') || usernameErr" class="field-error">{{ err('username') || usernameErr }}</p>
        <p v-else-if="usernameLockedText" class="mt-1.5 text-[12px] font-semibold text-amber-600">{{ usernameLockedText }}</p>
        <p v-else class="mt-1.5 text-[12px] text-slate-400">3–20 letters, numbers or underscores. {{ p.username_changes_left }} of 5 changes left, then a 7-day wait.</p>
        <div v-if="usernameSuggest.length" class="mt-2 flex flex-wrap gap-2">
          <button v-for="u in usernameSuggest" :key="u" type="button" class="rounded-full bg-brand-50 px-3 py-1 text-[12px] font-bold text-brand-700" @click="username = u; usernameSuggest = []; usernameErr = ''">@{{ u }}</button>
        </div>
      </div>
      <button class="btn-pill btn-pill-primary" :disabled="busy || !usernameChanged || !!usernameLockedText">Save username</button>
    </form>

    <div class="mt-5 border-t border-slate-100 pt-4">
      <label class="field-label" for="email">Email</label>
      <input id="email" class="input-soft" :value="p.email" readonly disabled />
      <p class="mt-1.5 text-[12px] text-slate-400">Your email can’t be changed here.</p>
    </div>
      </div>
    </details>

    <details class="tile acc" v-if="ach">
      <summary><span><span class="acc-title">Achievements</span><span class="acc-sub">Badges you have unlocked</span></span><svg class="acc-chev" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6"/></svg></summary>
      <div class="acc-body">
        <div class="">
      <div class="flex items-baseline justify-between gap-2">
        <p class="text-[12px] font-semibold text-slate-400">{{ ach.badges.length }} of {{ ach.total }} unlocked</p>
      </div>
      <p v-if="!ach.badges.length" class="mt-2 text-[12.5px] text-slate-400">Settle a bill to start earning badges.</p>
      <template v-else>
        <p class="mt-0.5 text-[12px] text-slate-400">Tap a badge to see it again. ★ makes it the title next to your name; hidden badges only you can see.</p>
        <div class="mt-3 grid grid-cols-2 gap-2">
          <div v-for="a in ach.badges" :key="a.code" class="flex items-center gap-1 rounded-2xl border px-2.5 py-2"
               :class="a.hidden ? 'border-dashed border-slate-200 bg-slate-50 opacity-60' : a.code === ach.featured ? 'border-violet-300 bg-violet-50' : 'border-slate-200 bg-white'">
            <button @click="openBadge(a)" class="flex min-w-0 flex-1 items-center gap-1.5 text-left" :aria-label="'Open ' + a.title">
              <span class="text-[20px] leading-none">{{ a.emoji }}</span>
              <span class="min-w-0 truncate text-[12.5px] font-bold text-ink">{{ a.title }}</span>
            </button>
            <button v-if="!a.hidden" @click="feature(a)" :disabled="busyAch" class="shrink-0 text-[15px] leading-none"
                    :class="a.code === ach.featured ? 'text-violet-600' : 'text-slate-300 hover:text-violet-500'"
                    :aria-label="a.code === ach.featured ? 'Stop using as title' : 'Use as title'" :title="a.code === ach.featured ? 'Your title' : 'Use as title'">★</button>
            <button @click="toggleHidden(a)" :disabled="busyAch" class="shrink-0 p-0.5 text-slate-400 hover:text-ink" :aria-label="a.hidden ? 'Show ' + a.title : 'Hide ' + a.title" :title="a.hidden ? 'Hidden — tap to show' : 'Shown — tap to hide'">
              <svg v-if="a.hidden" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3l18 18M10.6 10.6a2 2 0 002.8 2.8M9.9 5.1A9.8 9.8 0 0112 5c4.5 0 8.3 2.9 9.5 7a10 10 0 01-2.8 4.2M6.6 6.6A10 10 0 002.5 12c1.2 4.1 5 7 9.5 7a9.8 9.8 0 005.4-1.6"/></svg>
              <svg v-else class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2.5 12C3.7 7.9 7.5 5 12 5s8.3 2.9 9.5 7c-1.2 4.1-5 7-9.5 7s-8.3-2.9-9.5-7z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
        </div>
      </template>
    </div>
      </div>
    </details>


  </div>

  <?php require __DIR__ . '/../partials/nav.php'; ?>
  <!-- Profile picture: a preset, a gallery photo, or initials (same picker as the first-run page) -->
  <div v-if="picking && p" class="sheet-backdrop" @click.self="picking = false">
    <div class="sheet" role="dialog" aria-labelledby="pic-title">
      <div class="sheet-grip"></div>
      <div class="mb-4 flex items-center justify-between">
        <h2 id="pic-title" class="text-[18px] font-extrabold text-ink">Profile picture</h2>
        <button @click="picking = false" class="flex h-8 w-8 items-center justify-center rounded-full bg-slate-100 text-slate-500" aria-label="Close">✕</button>
      </div>
      <div class="mb-4 flex justify-center"><avatar :user="p" :size="88"></avatar></div>
      <avatar-picker :profile="p" :presets="presets" @saved="onPicture"></avatar-picker>
      <button @click="picking = false" class="btn-pill btn-pill-primary mt-5">Done</button>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../partials/profile-script.php'; ?>
<?php require __DIR__ . '/../partials/foot.php'; ?>
