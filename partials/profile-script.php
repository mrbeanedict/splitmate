<?php // Shared by My Profile, Payment Methods and Account Settings (one Vue app each page) ?>
<script>
const PAGE = '<?= h($page) ?>';
Setlo.mount({
  mixins: [Setlo.validation],
  data: () => ({
    loading: true, busy: false, busyAch: false, p: null, money: null, ach: null, canShare: !!navigator.share,
    presets: [], picking: false,
    cardShowAcct: false, received: [], receivedLoaded: false, walletBalance: 0,
    theme: SetloTheme.get(), accents: SetloTheme.ACCENTS, hexDraft: SetloTheme.get().custom,
    // Line icons for the Mode and Background buttons (24×24, stroke = the button's text colour)
    icons: {
      light: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
      dark: '<path d="M21 12.8A9 9 0 1111.2 3a7 7 0 009.8 9.8z"/>',
      system: '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/>',
      calm: '<path d="M3 15c3-4 5-4 9 0s6 4 9 0"/><path d="M3 9c3-4 5-4 9 0s6 4 9 0"/>',
      badges: '<circle cx="12" cy="9" r="6"/><path d="M8.5 14.5L7 22l5-3 5 3-1.5-7.5"/>',
      plain: '<rect x="4" y="4" width="16" height="16" rx="3"/>',
      photo: '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="1.6"/><path d="M21 16l-5-5-9 9"/>',
    },
    modes: [{ key: 'light', label: 'Light' }, { key: 'dark', label: 'Dark' }, { key: 'system', label: 'System' }],
    backgrounds: [{ key: 'calm', label: 'Calm' }, { key: 'badges', label: 'Badges' }, { key: 'plain', label: 'Plain' }, { key: 'photo', label: 'Photo' }],
    calmStyles: [{ key: 'aurora', label: 'Aurora' }, { key: 'ocean', label: 'Ocean' }, { key: 'sunset', label: 'Sunset' }, { key: 'forest', label: 'Forest' }, { key: 'dusk', label: 'Dusk' }, { key: 'clouds', label: 'Clouds' }],
    payMethods: [{ key: 'GCash', label: 'GCash', hint: 'Send to your number' }, { key: 'Maya', label: 'Maya', hint: 'Send to your number' }, { key: 'Bank Transfer', label: 'Bank transfer', hint: 'Bank & account' }, { key: 'Cash', label: 'Cash', hint: 'Pay in person' }],
    busyBg: null,
    f: { full_name: '', payment_method: 'GCash', payment_account: '' },
    pw: { current: '', new: '', confirm: '' }, serverErrors: {},
    username: '', usernameErr: '', usernameSuggest: [],
  }),
  computed: {
    usernameLockedText() {
      const t = this.p && this.p.username_locked_until;
      if (!t) return '';
      const d = new Date(t.replace(' ', 'T'));
      return 'All 5 changes used. You can change it again on ' + d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) + '.';
    },
    usernameChanged() { return !!this.p && this.username.toLowerCase() !== (this.p.username || ''); },
    hexOk() { return SetloTheme.isHex(this.hexDraft); },
    customAdjusted() { return this.theme.accent === 'custom' && SetloTheme.scale(this.theme.custom).adjusted; },
    isGcash() { return this.f.payment_method === 'GCash'; },
    accountLabel() {
      if (this.isGcash) return 'GCash number';
      return this.f.payment_method === 'Bank Transfer' ? 'Bank & account number' : this.f.payment_method + ' number / name';
    },
    accountPlaceholder() { return this.f.payment_method === 'Bank Transfer' ? 'e.g. BPI 1234-5678-90 · Juan Dela Cruz' : 'e.g. 0917 123 4567 · Juan D.'; },
    bg() { return this.p?.background || { mode: 'calm', style: 'aurora', image: null }; },
    bgHint() {
      return {
        calm: 'Soft, slow-moving backdrops that rest your eyes. Pick a style below.',
        badges: 'Your shown badges float behind your pages; hidden ones stay out.',
        plain: 'Just the soft colour wash, nothing moving.',
        photo: 'Your photo, softened a little so your cards stay easy to read.',
      }[this.bg.mode];
    },
    payUrl() { return this.p ? Setlo.payLink(this.p.pay_code) : ''; },
    /** The QR points at this computer only (localhost), so a friend's phone can't open it. */
    localLink() { return !!this.p && Setlo.isLocalLink(this.payUrl); },
    qr() { return this.p ? Setlo.qrSvg(this.payUrl) : ''; },
    featuredBadge() {
      if (!this.ach) return this.p?.badge || null;
      const a = this.ach.badges.find((b) => b.code === this.ach.featured);
      return a ? { emoji: a.emoji, title: a.title } : null;
    },
  },
  async mounted() {
    await Setlo.load(this, 'profile.php', null, (r) => { this.set(r.profile); this.money = r.money || null; this.presets = r.presets || []; });
    if (PAGE === 'profile') Setlo.load(this, 'achievements.php', null, (r) => { this.ach = r; }, 'busyAch');
    if (PAGE === 'payments') this.loadReceived();
  },
  methods: {
    openBadge(a) { Setlo.showAchievement(a); },
    async toggleHidden(a) {
      const r = await Setlo.run(this, () => api.post('achievements.php', { action: 'hide', code: a.code, hidden: !a.hidden }), 'busyAch');
      if (r) { this.ach = r; window.SetloBackground?.refresh(r.badges); }
    },
    /** A new backdrop was saved: show it now and keep it in the profile. */
    applyBackground(r) {
      if (!r) return;
      this.p = { ...this.p, background: r.profile.background };
      window.SetloBackground?.set(r.profile.background);
    },
    payLogo(method) { return (window.SETLO_PAY_LOGOS || {})[method] || ''; },
    /** Arrow keys move the payment method choice, like any radio group. */
    methodKey(e) {
      const d = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 }[e.key];
      if (!d) return;
      e.preventDefault();
      const i = this.payMethods.findIndex((m) => m.key === this.f.payment_method);
      const next = this.payMethods[(i + d + this.payMethods.length) % this.payMethods.length];
      this.f.payment_method = next.key;
      this.$nextTick(() => document.querySelector('.pm-card[data-method="' + next.key + '"]')?.focus());
    },
    async setBackground(mode) {
      if (mode === this.bg.mode || this.busyBg) return;
      if (mode === 'photo' && !this.bg.image) { this.pickBackgroundPhoto(); return; } // no photo yet: pick one first
      this.applyBackground(await Setlo.run(this, () => api.post('profile.php', { action: 'set_background', mode }), 'busyBg'));
    },
    async setCalmStyle(style) {
      if (this.busyBg || (this.bg.mode === 'calm' && this.bg.style === style)) return;
      this.applyBackground(await Setlo.run(this, () => api.post('profile.php', { action: 'set_background', mode: 'calm', style }), 'busyBg'));
    },
    /** Ask first, then open the file picker / gallery for the background photo. */
    async pickBackgroundPhoto() {
      const ok = await Setlo.confirm({ title: 'Allow Setlo to open your photos?', text: 'Setlo needs your permission to open your gallery or files so you can pick a photo for your background. Only the photo you choose is used.', confirmText: 'Allow' });
      if (ok) this.$refs.bgFile.click();
    },
    async uploadBackground(e) {
      const file = e.target.files[0];
      e.target.value = '';
      if (!file) return;
      if (!file.type.startsWith('image/')) { Setlo.toast('Choose a photo.'); return; }
      this.busyBg = 'crop';
      try {
        const photo = await Setlo.cropPhoto(file, { shape: 'rect', aspect: 16 / 9, width: 1600, title: 'Crop your background' });
        if (!photo) return;
        this.busyBg = 'upload';
        const fd = new FormData();
        fd.append('action', 'upload_background');
        fd.append('photo', photo, 'background.jpg');
        this.applyBackground(await api.post('profile.php', fd));
        Setlo.toast('Background updated', 'ok');
      } catch (err) { Setlo.toast(err.message); }
      finally { this.busyBg = null; }
    },
    async removeBackground() {
      const ok = await Setlo.confirm({ title: 'Remove your photo?', text: 'Your background goes back to the floating badges.', confirmText: 'Remove' });
      if (ok) this.applyBackground(await Setlo.run(this, () => api.post('profile.php', { action: 'remove_background' }), 'busyBg'));
    },
    async feature(a) {
      const r = await Setlo.run(this, () => api.post('achievements.php', { action: 'feature', code: a.code === this.ach.featured ? '' : a.code }), 'busyAch');
      if (r) { this.ach = r; Setlo.toast(r.featured ? 'Title set' : 'Title removed', 'ok'); }
    },
    showPerson(id) { Setlo.showPerson(id); },
    async setCurrency(code) {
      const r = await Setlo.run(this, () => api.post('profile.php', { action: 'set_currency', currency: code }));
      if (!r) return;
      this.p = r.profile;
      Setlo.toast('Currency set to ' + code, 'ok');
      // Totals owed are shown in it: fetch them again
      try { this.money = (await api.get('profile.php')).money || null; } catch (e) { /* the old totals stay until the next visit */ }
    },
    setTheme(patch) { this.theme = SetloTheme.set(patch); },
    async setEmailReminders(on) {
      const r = await Setlo.run(this, () => api.post('profile.php', { action: 'email_reminders', on }));
      if (r) { this.p = r.profile; Setlo.toast(on ? 'Reminder emails are on' : 'Reminder emails are off', 'ok'); }
    },
    /** Typing a hex code: applies once it is a full #rrggbb (the "#" is added if left out). */
    onHex() {
      let v = this.hexDraft.trim();
      if (v && v[0] !== '#') v = '#' + v;
      this.hexDraft = v;
      if (SetloTheme.isHex(v)) this.setTheme({ custom: v });
    },
    /** A new picture was saved: show it, but keep any unsaved edits in the details form. */
    onPicture(profile) {
      this.p = profile;
      Setlo.toast('Picture updated', 'ok');
    },
    validators() {
      return {
        full_name: V.name(this.f.full_name, 'Full name'),
        payment_account: this.f.payment_method === 'Cash' ? '' : (this.isGcash ? V.gcash(this.f.payment_account) : V.account(this.f.payment_account)),
        username: V.username(this.username),
        pw_current: this.serverErrors.current || V.required(this.pw.current, 'Current password'),
        pw_new: this.serverErrors.new || V.password(this.pw.new) || (this.pw.new && this.pw.new === this.pw.current ? 'Choose a password different from your current one.' : ''),
        pw_confirm: V.match(this.pw.confirm, this.pw.new),
      };
    },
    set(p) {
      this.p = p;
      this.f = { full_name: p.name, payment_method: p.payment_method, payment_account: p.payment_account || '' };
      this.username = p.username || '';
    },
    // Digits only, so "0917 123 4567" or a pasted "0917-123-4567" becomes 09171234567.
    onGcashInput() {
      this.f.payment_account = this.f.payment_account.replace(/\D/g, '').slice(0, 11);
      this.touch('payment_account');
    },
    async saveProfile(fields = ['full_name', 'payment_account']) {
      if (!this.validateAll(fields)) return;
      const r = await Setlo.run(this, () => api.post('profile.php', { action: 'update', ...this.f, full_name: this.f.full_name.trim() }));
      if (r) { this.set(r.profile); Setlo.toast('Details saved', 'ok'); }
    },
    async saveUsername() {
      if (!this.validateAll(['username'])) return;
      this.usernameErr = '';
      try {
        this.busy = true;
        const r = await api.post('profile.php', { action: 'username', username: this.username });
        this.set(r.profile);
        this.usernameSuggest = [];
        Setlo.toast('Username changed to @' + r.profile.username, 'ok');
      } catch (e) {
        this.usernameErr = (e.data && e.data.fields && e.data.fields.username) || e.message;
        this.usernameSuggest = (e.data && e.data.suggestions) || [];
      } finally {
        this.busy = false;
      }
    },
    /** Money friends sent through my QR (Payment Methods page). */
    async loadReceived() {
      try { const r = await api.get('pay.php', { received: 1 }); this.received = r.received; this.walletBalance = r.balance || 0; } catch (e) { /* the list just stays empty */ }
      this.receivedLoaded = true;
    },
    /** My shareable "Pay me" card (PNG). The number is on it only if I ticked the box: the image can be sent to anyone. */
    cardImage() {
      return Setlo.qrCard({
        name: this.p.name, initials: this.p.initials, color: this.p.color, photo: this.p.photo, link: this.payUrl,
        method: this.p.payment_method, account: this.p.payment_account, showAccount: this.cardShowAcct && this.p.payment_method !== 'Cash',
      });
    },
    async downloadQr() {
      const { dataUrl } = await this.cardImage();
      const a = document.createElement('a');
      a.href = dataUrl;
      a.download = 'setlo-pay-' + this.p.first.toLowerCase() + '.png';
      a.click();
    },
    async copyLink() {
      try { await navigator.clipboard.writeText(this.payUrl); Setlo.toast('Link copied', 'ok'); }
      catch (e) { Setlo.showCopy('Your pay link', this.payUrl); }
    },
    /** Share the card as an image where the browser can (phones); otherwise download it and copy the link. */
    async shareLink() {
      const { blob } = await this.cardImage();
      const file = new File([blob], 'setlo-pay-' + this.p.first.toLowerCase() + '.png', { type: 'image/png' });
      if (navigator.canShare && navigator.canShare({ files: [file] })) {
        navigator.share({ files: [file], title: 'Pay ' + this.p.name + ' on Setlo', text: 'Pay me on Setlo: ' + this.payUrl }).catch(() => {});
        return;
      }
      if (navigator.share) {
        navigator.share({ title: 'Pay ' + this.p.name + ' on Setlo', url: this.payUrl }).catch(() => {});
        return;
      }
      await this.copyLink();
    },
    async rotate() {
      const ok = await Setlo.confirm({
        title: 'Get a new QR?', danger: true, confirmText: 'Yes, new QR',
        text: 'Your current QR and pay link will stop working. Anyone who saved the old one will need the new one.',
      });
      if (!ok) return;
      const r = await Setlo.run(this, () => api.post('profile.php', { action: 'rotate_pay_code' }));
      if (r) { this.set(r.profile); Setlo.toast('New QR created', 'ok'); }
    },
    async changePassword() {
      this.serverErrors = {};
      if (!this.validateAll(['pw_current', 'pw_new', 'pw_confirm'])) return;
      try {
        this.busy = true;
        await api.post('profile.php', { action: 'password', ...this.pw });
        this.pw = { current: '', new: '', confirm: '' };
        ['pw_current', 'pw_new', 'pw_confirm'].forEach((k) => { this.touched[k] = false; });
        Setlo.alert('Password updated', 'Use your new password next time you sign in.', 'success');
      } catch (e) {
        this.serverErrors = e.data.fields || {};
        Setlo.toast(e.message);
      } finally {
        this.busy = false;
      }
    },
  },
  watch: {
    'pw.current'() { this.serverErrors.current = ''; },
    'pw.new'() { this.serverErrors.new = ''; },
  },
});
</script>
