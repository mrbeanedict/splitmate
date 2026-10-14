<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_login();
$title = 'Account Settings';
$nav = 'settings';
$back = 'dashboard.php';
$page = 'settings';
require __DIR__ . '/../partials/head.php';
?>
<div id="app" class="device" v-cloak>

  <div class="app-hero px-5 pb-5 pt-8">
    <div class="flex items-center gap-3">
      <?php require __DIR__ . '/../partials/back.php'; ?>
      <div class="min-w-0 flex-1">
        <h1 class="text-[21px] font-extrabold tracking-tight">Account Settings</h1>
        <p class="text-[12px] font-medium text-brand-50/90">Security and preferences</p>
      </div>
    </div>
  </div>

  <spinner v-if="loading"></spinner>
  <div v-else class="flex-1 space-y-3 px-5 pb-6 pt-4">

    <details class="tile acc">
      <summary><span><span class="acc-title">Change password</span><span class="acc-sub">Keep your account secure</span></span><svg class="acc-chev" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6"/></svg></summary>
      <div class="acc-body">
        <form class="space-y-3" @submit.prevent="changePassword" novalidate>
      <div>
        <input data-field="pw_current" v-model="pw.current" @input="touch('pw_current')" type="password" autocomplete="current-password"
               class="input-soft" :class="{ 'is-invalid': err('pw_current') }" placeholder="Current password" aria-label="Current password" />
        <p v-if="err('pw_current')" class="field-error">{{ err('pw_current') }}</p>
      </div>
      <div>
        <input data-field="pw_new" v-model="pw.new" @input="touch('pw_new')" type="password" autocomplete="new-password"
               class="input-soft" :class="{ 'is-invalid': err('pw_new') }" placeholder="New password" aria-label="New password" />
        <p v-if="err('pw_new')" class="field-error">{{ err('pw_new') }}</p>
        <p v-else-if="pw.new" class="field-ok">✓ Strong enough</p>
        <p v-else class="mt-1.5 text-[12px] text-slate-400">At least 8 characters, with a letter and a number.</p>
      </div>
      <div>
        <input data-field="pw_confirm" v-model="pw.confirm" @input="touch('pw_confirm')" type="password" autocomplete="new-password"
               class="input-soft" :class="{ 'is-invalid': err('pw_confirm') }" placeholder="Confirm new password" aria-label="Confirm new password" />
        <p v-if="err('pw_confirm')" class="field-error">{{ err('pw_confirm') }}</p>
      </div>
      <button class="btn-pill btn-pill-soft" :disabled="busy">Update password</button>
    </form>
      </div>
    </details>

    <details class="tile acc">
      <summary><span><span class="acc-title">Appearance</span><span class="acc-sub">Color, light or dark, and background</span></span><svg class="acc-chev" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6"/></svg></summary>
      <div class="acc-body">
        <div id="account" class="">
      <p class="mt-0.5 text-[12px] text-slate-400">Pick a color and a light or dark look. Saved on this device.</p>
      <p class="section-label mt-4">COLOR</p>
      <div class="flex flex-wrap gap-3" role="radiogroup" aria-label="Color theme">
        <button v-for="(a, key) in accents" :key="key" type="button" role="radio" :aria-checked="theme.accent === key" @click="setTheme({ accent: key })"
                class="flex flex-col items-center gap-1.5 text-[11px] font-bold" :class="theme.accent === key ? 'text-ink' : 'text-slate-400'">
          <span class="flex h-11 w-11 items-center justify-center rounded-full text-white transition" :style="{ background: a.color, boxShadow: theme.accent === key ? '0 0 0 3px rgb(var(--surface)), 0 0 0 5px ' + a.color : 'none' }">
            <svg v-if="theme.accent === key" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
          </span>
          {{ a.label }}
        </button>
        <button type="button" role="radio" :aria-checked="theme.accent === 'custom'" @click="setTheme({ accent: 'custom' })"
                class="flex flex-col items-center gap-1.5 text-[11px] font-bold" :class="theme.accent === 'custom' ? 'text-ink' : 'text-slate-400'">
          <span class="flex h-11 w-11 items-center justify-center rounded-full text-white transition"
                :style="{ background: theme.accent === 'custom' ? theme.custom : 'conic-gradient(#ef4444, #f59e0b, #22c55e, #06b6d4, #6366f1, #d946ef, #ef4444)', boxShadow: theme.accent === 'custom' ? '0 0 0 3px rgb(var(--surface)), 0 0 0 5px ' + theme.custom : 'none' }">
            <svg v-if="theme.accent === 'custom'" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
          </span>
          Custom
        </button>
      </div>
      <div v-if="theme.accent === 'custom'" class="mt-3 rounded-2xl bg-slate-50 p-3">
        <div class="flex items-center gap-3">
          <input type="color" :value="theme.custom" @input="setTheme({ custom: $event.target.value }); hexDraft = theme.custom" class="h-11 w-14 shrink-0 cursor-pointer rounded-xl border-0 bg-transparent p-0" aria-label="Pick a color" />
          <input v-model="hexDraft" @input="onHex" maxlength="7" spellcheck="false" autocapitalize="off" class="row-in w-28 font-mono uppercase" :class="{ 'is-invalid': hexDraft && !hexOk }" aria-label="Color hex code" placeholder="#7C3AED" />
          <button type="button" class="ml-auto text-[12px] font-bold text-slate-400 hover:text-ink" @click="setTheme({ accent: 'green' })">Reset to green</button>
        </div>
        <p v-if="customAdjusted" class="mt-2 text-[11.5px] text-slate-500">Made a little darker so buttons and text stay easy to read.</p>
      </div>
      <p class="section-label mt-5">MODE</p>
      <div class="seg seg-light" role="radiogroup" aria-label="Light or dark mode">
        <button v-for="m in modes" :key="m.key" type="button" role="radio" :aria-checked="theme.mode === m.key" :class="{ on: theme.mode === m.key }" @click="setTheme({ mode: m.key })"><svg class="seg-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="display:inline-block;width:15px;height:15px;margin:-3px 6px 0 0;vertical-align:middle" v-html="icons[m.key]"></svg>{{ m.label }}</button>
      </div>
      <!-- Backdrop behind the pages: saved to the account (api/profile.php), applied live (assets/js/badge-field.js) -->
      <p class="section-label mt-5">BACKGROUND</p>
      <div class="seg seg-light" role="radiogroup" aria-label="Page background">
        <button v-for="b in backgrounds" :key="b.key" type="button" role="radio" :aria-checked="bg.mode === b.key" :class="{ on: bg.mode === b.key }"
                :disabled="!!busyBg" @click="setBackground(b.key)"><svg class="seg-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="display:inline-block;width:15px;height:15px;margin:-3px 6px 0 0;vertical-align:middle" v-html="icons[b.key]"></svg>{{ b.label }}</button>
      </div>
      <p class="mt-2 text-[11.5px] text-slate-400">{{ bgHint }} Saved to your account.</p>
      <div v-if="bg.mode === 'photo' && bg.image" class="mt-3 flex items-center gap-3.5 rounded-2xl border border-slate-200 bg-slate-50 p-2.5">
        <img :src="bg.image" alt="Your background photo" class="aspect-video w-28 shrink-0 rounded-xl object-cover shadow-sm ring-1 ring-black/5" />
        <div class="min-w-0 flex-1">
          <p class="truncate text-[13px] font-bold text-ink">{{ busyBg === 'upload' ? 'Uploading…' : 'Your photo' }}</p>
          <p class="truncate text-[11.5px] text-slate-400">Shown softly behind your pages</p>
          <div class="mt-2 flex flex-wrap gap-2">
            <button type="button" class="inline-flex items-center gap-1.5 rounded-full bg-brand-50 px-3 py-1.5 text-[12px] font-bold text-brand-700 transition hover:bg-brand-100 disabled:opacity-50"
                    :disabled="!!busyBg" @click="pickBackgroundPhoto">
              <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.6-4.6a2 2 0 012.8 0L16 16m-2-2l1.6-1.6a2 2 0 012.8 0L20 14M14 8h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
              Change
            </button>
            <button type="button" class="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-[12px] font-bold text-slate-500 transition hover:bg-slate-100 hover:text-rose-600 disabled:opacity-50"
                    :disabled="!!busyBg" @click="removeBackground">
              <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 7h12M10 11v6m4-6v6M9 7l1-3h4l1 3m-9 0l1 13h10l1-13"/></svg>
              Remove
            </button>
          </div>
        </div>
      </div>
      <input ref="bgFile" type="file" accept="image/*" class="hidden" @change="uploadBackground" />
    </div>
      </div>
    </details>

  </div>

  <?php require __DIR__ . '/../partials/nav.php'; ?>
</div>

<?php require __DIR__ . '/../partials/profile-script.php'; ?>
<?php require __DIR__ . '/../partials/foot.php'; ?>
