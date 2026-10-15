<?php
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/google.php';
$next = safe_next((string) ($_GET['next'] ?? ''));
// From a guest's private link (pages/guest.php): their bill and payments come along into the new account
$claim = claim_param();
$peek = $claim ? guest_peek($claim) : null;
if (current_user()) {
    redirect($claim ? 'pages/guest?t=' . $claim : ($next ? 'pages/' . $next : ''));
}
$bannerText = $peek ? 'Your share of <b>' . h($peek['bill']) . '</b> from ' . h($peek['creator']) . ' comes with you. Nothing to re-enter.' : '';
$title = 'Create Account';
$bodyClass = 'landing-body';
$headExtra = ['assets/fonts/caveat-latin.woff2', 'assets/css/auth-fit.css', 'assets/js/google-auth.js'];
require __DIR__ . '/../partials/head.php';
require __DIR__ . '/../partials/auth-landing-top.php';
?>
<div id="app" class="landing-card" v-cloak>
  <div class="flex flex-col items-center text-center">
    <h2 class="text-[22px] font-extrabold text-slate-800">Create your account</h2>
    <p class="mt-1 max-w-[280px] text-[14px] leading-snug text-slate-500">Start splitting bills with your barkada in seconds.</p>
  </div>

<?php if ($peek): ?>
  <div class="mt-5 flex items-start gap-3 rounded-2xl bg-brand-50 px-4 py-3 text-left" style="background:var(--tint)">
    <img src="<?= h(url('assets/mascot/thumbs.png')) ?>" alt="" class="h-12 w-auto shrink-0" />
    <p class="text-[13px] leading-snug text-slate-600"><?= $bannerText ?></p>
  </div>
<?php endif; ?>
  <div ref="google" class="mt-6 flex min-h-[52px] justify-center"></div>
  <div class="my-5 flex items-center gap-3 text-[12px] text-slate-400"><span class="h-px flex-1 bg-slate-200"></span>or sign up with email<span class="h-px flex-1 bg-slate-200"></span></div>

  <form class="space-y-3" @submit.prevent="submit" novalidate>
    <div>
      <div class="relative">
        <label for="name" class="sr-only">Full name</label>
        <svg width="20" height="20" class="landing-input-icon" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path stroke-linecap="round" d="M4 20c1.5-3.5 4.5-5 8-5s6.5 1.5 8 5"/></svg>
        <input id="name" data-field="full_name" data-filter="name" v-model="f.full_name" @input="touch('full_name')" @blur="touch('full_name')" :class="{ 'is-invalid': err('full_name') }" autocomplete="name" class="landing-input" placeholder="Full name (e.g. Juan Dela Cruz)" maxlength="100" />
      </div>
      <p v-if="err('full_name')" class="field-error">{{ err('full_name') }}</p>
    </div>

    <div>
      <div class="relative">
        <label for="username" class="sr-only">Username</label>
        <svg width="20" height="20" class="landing-input-icon" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path stroke-linecap="round" d="M16 12v1.5a2.5 2.5 0 005 0V12a9 9 0 10-3.5 7.1"/></svg>
        <input id="username" data-field="username" v-model.trim="f.username" @input="onUsername" @blur="touch('username')" :class="{ 'is-invalid': err('username') }" maxlength="20" autocomplete="username" autocapitalize="none" spellcheck="false" class="landing-input" placeholder="Username (e.g. juan67)" />
      </div>
      <p v-if="err('username')" class="field-error">{{ err('username') }}</p>
      <p v-else-if="usernameOk" class="field-ok">✓ @{{ f.username.toLowerCase() }} is available</p>
      <p v-if="suggestions.length" class="mt-1.5 flex flex-wrap items-center gap-1.5 text-[12px] text-slate-500">Try:
        <button v-for="u in suggestions" :key="u" type="button" class="rounded-full bg-brand-50 px-2.5 py-1 font-semibold text-brand-700 hover:bg-brand-100" @click="pick(u)">{{ u }}</button>
      </p>
    </div>

    <div>
      <div class="relative">
        <label for="email" class="sr-only">Email</label>
        <svg width="20" height="20" class="landing-input-icon" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path stroke-linecap="round" stroke-linejoin="round" d="M3 7l9 6 9-6"/></svg>
        <input id="email" data-field="email" v-model.trim="f.email" @input="touch('email')" @blur="touch('email')" :class="{ 'is-invalid': err('email') }" maxlength="190" type="email" autocomplete="email" class="landing-input" placeholder="you@email.com" />
      </div>
      <p v-if="err('email')" class="field-error">{{ err('email') }}</p>
    </div>

    <div>
      <div class="relative">
        <label for="pw" class="sr-only">Password</label>
        <svg width="20" height="20" class="landing-input-icon" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><rect x="5" y="11" width="14" height="9" rx="2"/><path stroke-linecap="round" d="M8 11V8a4 4 0 018 0v3"/></svg>
        <input id="pw" data-field="password" v-model="f.password" @input="touch('password')" @blur="touch('password')" :class="{ 'is-invalid': err('password') }" maxlength="72" :type="showPw ? 'text' : 'password'" autocomplete="new-password" class="landing-input !pr-12" placeholder="Password (8+ with letters and numbers)" />
        <button type="button" class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-500 hover:text-brand-600" :class="{ '!text-brand-600': showPw }" @click="showPw = !showPw" :aria-label="showPw ? 'Hide passwords' : 'Show passwords'">
          <svg width="20" height="20" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2.04 12.32a1 1 0 010-.64C3.42 7.51 7.36 4.5 12 4.5s8.57 3.01 9.96 7.18a1 1 0 010 .64C20.58 16.49 16.64 19.5 12 19.5s-8.57-3.01-9.96-7.18z"/><circle cx="12" cy="12" r="3"/></svg>
        </button>
      </div>
      <p v-if="err('password')" class="field-error">{{ err('password') }}</p>
      <p v-else-if="f.password" class="field-ok">✓ Strong enough</p>
    </div>

    <div>
      <div class="relative">
        <label for="pw2" class="sr-only">Confirm password</label>
        <svg width="20" height="20" class="landing-input-icon" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><rect x="5" y="11" width="14" height="9" rx="2"/><path stroke-linecap="round" d="M8 11V8a4 4 0 018 0v3"/></svg>
        <input id="pw2" data-field="password_confirm" v-model="f.password_confirm" @input="touch('password_confirm')" @blur="touch('password_confirm')" :class="{ 'is-invalid': err('password_confirm') }" maxlength="72" :type="showPw ? 'text' : 'password'" autocomplete="new-password" class="landing-input" placeholder="Confirm password" />
      </div>
      <p v-if="err('password_confirm')" class="field-error">{{ err('password_confirm') }}</p>
    </div>

    <div class="flex items-start gap-2.5 pt-1 text-[13px] text-slate-500">
      <input id="agree" data-field="agree" v-model="f.agree" @change="touch('agree')" type="checkbox" class="mt-0.5 h-4 w-4 shrink-0 cursor-pointer accent-brand-600" />
      <span>I agree to the <a href="conditions_policy#terms" target="_blank" rel="noopener" class="font-bold text-brand-700 hover:underline">Terms &amp; Conditions</a> and <a href="conditions_policy#privacy" target="_blank" rel="noopener" class="font-bold text-brand-700 hover:underline">Privacy Policy</a></span>
    </div>
    <p v-if="err('agree')" class="field-error !mt-1">{{ err('agree') }}</p>
    <p v-if="error" class="rounded-xl bg-rose-50 px-3.5 py-2.5 text-[13px] font-semibold text-rose-600" role="alert">{{ error }}</p>

    <button type="submit" class="btn-pill btn-pill-primary !mt-5 !h-[54px]" :disabled="busy">
      {{ busy ? 'Creating account…' : 'Create Account' }}
      <svg v-if="!busy" width="20" height="20" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-6-6l6 6-6 6"/></svg>
    </button>
  </form>

  <div class="auth-alt">
    <p class="auth-alt-login"><span>Already have an account? <a href="login<?= $claim ? '?claim=' . h($claim) : ($next ? '?next=' . h(urlencode($next)) : '') ?>">Log in</a></span></p>
    <a href="try" class="auth-alt-guest">
      <span class="auth-alt-title"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path stroke-linecap="round" d="M4 20c1.5-3.5 4.5-5 8-5s6.5 1.5 8 5"/></svg>Sign in as guest</span>
      <span class="auth-alt-sub">Explore with limited features</span>
    </a>
  </div>
</div>

<?php require __DIR__ . '/../partials/auth-landing-bottom.php'; ?>
<script>
const NEXT = <?= json_encode($next) ?>;
const CLAIM = <?= json_encode($claim) ?>;
const CLAIM_NAME = <?= json_encode($peek['guest'] ?? '') ?>;
const GOOGLE_CLIENT_ID = <?= json_encode(google_client_id()) ?>;

Setlo.mount({
  mixins: [Setlo.validation],
  data: () => ({
    f: { full_name: CLAIM_NAME, username: '', email: '', password: '', password_confirm: '', agree: false },
    serverErrors: {}, error: '', showPw: false, busy: false,
    suggestions: [], usernameOk: false, checkTimer: null,
  }),
  mounted() {
    SetloGoogle.mount(this.$refs.google, {
      clientId: GOOGLE_CLIENT_ID,
      label: 'Sign up with Google',
      text: 'signup_with',
      claim: CLAIM,
      onSuccess: (r) => { location.href = Setlo.afterSignUp(r, r.claimed || NEXT); },
      onError: (message) => { this.error = message; },
    });
  },
  watch: { 'f.email'() { this.serverErrors.email = ''; } },
  methods: {
    // Ask the server whether the username is free; when it isn't (or the box is empty), it hands back free alternatives.
    async checkUsername() {
      const typed = this.f.username;
      if (V.username(typed)) { this.usernameOk = false; return; }
      try {
        const r = await api.post('auth.php', { action: 'username_check', username: typed, name: this.f.full_name });
        if (typed !== this.f.username) return; // typed on since
        this.usernameOk = r.available;
        this.serverErrors.username = r.available ? '' : r.error;
        this.suggestions = r.suggestions;
      } catch (e) { /* the sign-up itself re-checks */ }
    },
    onUsername() {
      this.f.username = this.f.username.toLowerCase().replace(/[^a-z0-9_]/g, '');
      this.serverErrors.username = ''; this.usernameOk = false; this.suggestions = [];
      this.touch('username');
      clearTimeout(this.checkTimer);
      this.checkTimer = setTimeout(() => this.checkUsername(), 400);
    },
    pick(u) { this.f.username = u; this.suggestions = []; this.serverErrors.username = ''; this.touch('username'); this.checkUsername(); },
    validators() {
      return {
        full_name: V.name(this.f.full_name, 'Full name'),
        username: this.serverErrors.username || V.username(this.f.username),
        email: this.serverErrors.email || V.email(this.f.email),
        password: V.password(this.f.password),
        password_confirm: V.match(this.f.password_confirm, this.f.password),
        agree: this.f.agree ? '' : 'Please accept the terms to continue.',
      };
    },
    async submit() {
      this.error = '';
      if (!this.validateAll()) return;

      this.busy = true;
      try {
        const r = await api.post('auth.php', { action: 'register', ...this.f, full_name: this.f.full_name.trim(), claim: CLAIM });
        location.href = Setlo.afterSignUp(r, r.claimed || NEXT);
      } catch (e) {
        this.serverErrors = e.data.fields || {};
        this.suggestions = e.data.suggestions || [];
        if (!e.data.fields) this.error = e.message;
        else Setlo.toast(Object.values(e.data.fields)[0], 'warn');
        this.busy = false;
      }
    },
  },
}, '#app');
</script>
<?php require __DIR__ . '/../partials/foot.php'; ?>
