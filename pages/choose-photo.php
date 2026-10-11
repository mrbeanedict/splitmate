<?php
// First stop after creating an account: pick a profile picture (a preset, a gallery photo, or keep the initials).
// The same picker lives on the Profile page, so this can be skipped and changed any time.
require __DIR__ . '/../includes/bootstrap.php';
$user = require_login();
$next = safe_next((string) ($_GET['next'] ?? ''));
$title = 'Profile picture';
require __DIR__ . '/../partials/head.php';
?>
<div id="app" class="device device-narrow device-solo" v-cloak>

  <div class="app-hero px-5 pb-6 pt-8 text-center">
    <p class="text-[12px] font-bold uppercase tracking-[.12em] text-brand-50/90">Welcome to Setlo</p>
    <h1 class="mt-1 text-[22px] font-extrabold tracking-tight">Pick a profile picture</h1>
    <p class="mx-auto mt-1 max-w-xs text-[13px] text-brand-50/90">Friends see it next to your name on bills and payments.</p>
    <div class="mt-5 flex justify-center">
      <avatar v-if="p" :user="p" :size="96" ring></avatar>
      <span v-else class="h-24 w-24 rounded-full bg-white/20"></span>
    </div>
    <p v-if="p" class="mt-2 text-[15px] font-extrabold">{{ p.name }}</p>
  </div>

  <div class="flex-1 space-y-4 px-5 pb-8 pt-5">
    <spinner v-if="loading"></spinner>
    <div v-else-if="p" class="tile p-4">
      <avatar-picker :profile="p" :presets="presets" @saved="p = $event"></avatar-picker>
    </div>
    <!-- Home currency: bills you pay settle in it, and amounts in other currencies are estimated in it -->
    <div v-if="p" class="tile p-4">
      <p class="text-[15px] font-extrabold text-ink">Your currency</p>
      <p class="mt-0.5 text-[12px] text-slate-400">Bills you pay for are settled in it, and totals from other currencies are estimated in it.</p>
      <div class="mt-3"><currency-select :model-value="p.currency" @update:model-value="setCurrency" :disabled="savingCur" label="Your currency"></currency-select></div>
    </div>
    <a :href="done" class="btn-pill btn-pill-primary">{{ p && p.avatar ? 'Continue' : 'Skip for now' }}</a>
    <p class="text-center text-[12px] text-slate-400">You can change both any time in Profile.</p>
  </div>
</div>

<script>
Setlo.mount({
  data: () => ({ loading: true, savingCur: false, p: null, presets: [], done: <?= json_encode($next ?: 'dashboard') ?> }),
  async mounted() {
    await Setlo.load(this, 'profile.php', { avatars: 1 }, (r) => { this.p = r.profile; this.presets = r.presets; });
  },
  methods: {
    async setCurrency(code) {
      const r = await Setlo.run(this, () => api.post('profile.php', { action: 'set_currency', currency: code }), 'savingCur');
      if (r) { this.p = r.profile; Setlo.toast('Currency set to ' + code, 'ok'); }
    },
  },
});
</script>
<?php require __DIR__ . '/../partials/foot.php'; ?>
