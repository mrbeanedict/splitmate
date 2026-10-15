<?php
// Guest mode ("Sign in as guest"), no account: add friends, enter items, assign them, and see each person's share and who pays whom.
// Everything stays in this browser tab (assets/js/splitdemo.js); locked features invite visitors to register.
require __DIR__ . '/../includes/bootstrap.php';
$title = 'Guest mode';
$bodyClass = 'guest-body';
$headExtra = ['assets/css/tour.css', 'assets/css/guest.css', 'assets/js/splitdemo.js', 'assets/js/mascot-invite.js'];
require __DIR__ . '/../partials/head.php';
$mascot = fn (string $pose) => h(url('assets/mascot/' . $pose . '.png'));
?>
<div id="app" class="device device-wide device-solo" v-cloak>

  <div class="app-hero px-5 pb-16 pt-8">
    <a class="g-back" href="<?= h(url('')) ?>"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 6l-6 6 6 6"/></svg>Back to home</a>
    <img class="g-hero-mascot" src="<?= $mascot('wave') ?>" alt="" />
    <span class="g-hero-tag"><span v-html="icon('user', 15)"></span> Guest mode · no account needed</span>
    <h1 class="mt-3 text-[24px] font-extrabold leading-tight tracking-tight">Split a bill in a minute</h1>
    <p class="mt-1 max-w-sm text-[13px] text-brand-50/90">Add your friends, enter what everyone ordered, and see who pays whom. Nothing is saved, so closing this tab clears it.</p>
    <div class="t-hero-actions">
      <button type="button" class="t-btn" @click="useSample"><span v-html="icon('receipt', 17)"></span> Load a sample receipt</button>
      <button type="button" class="t-btn ghost" @click="reset">Start fresh</button>
    </div>
  </div>

  <div class="g-wrap">
  <div class="g-cols">
  <div class="g-col">
    <!-- 1. People -->
    <section class="g-card g-pop">
      <h2><span class="t-num">1</span> Who’s eating?</h2>
      <div class="t-chips">
        <span v-for="p in s.people" :key="p.id" class="t-chip">
          <avatar :user="person(p)" :size="26"></avatar>
          <input class="t-chip-in" v-model="p.name" maxlength="30" :aria-label="'Name of person ' + p.id" :style="{ width: Math.max(3, p.name.length + 1) + 'ch' }" />
          <button v-if="s.people.length > 2" type="button" class="t-x" @click="removePerson(p)" :aria-label="'Remove ' + p.name" v-html="icon('x', 14)"></button>
        </span>
      </div>
      <form class="t-add" @submit.prevent="addPerson">
        <input class="t-in" v-model="newPerson" maxlength="30" placeholder="Add a friend’s name" />
        <button class="t-add-btn" :disabled="!newPerson.trim() || s.people.length >= 12">Add</button>
      </form>
      <label class="t-field"><span>Who paid the bill?</span>
        <select class="t-in" v-model.number="s.payer"><option v-for="p in s.people" :key="p.id" :value="p.id">{{ p.name || 'Person ' + p.id }}</option></select>
      </label>
    </section>

    <!-- 2. Items (looks like a receipt) -->
    <section class="g-card t-receipt g-pop">
      <div class="t-head">
        <h2><span class="t-num">2</span> What did everyone order?</h2>
        <button v-if="s.items.length" type="button" class="t-split-all" @click="splitAll">Split everything equally</button>
      </div>
      <!-- Free receipt scan: a taste of the real scanner (api/guest-scan.php, 5 per guest) -->
      <div class="t-scan">
        <div class="t-scan-btns">
          <button type="button" class="t-scan-btn" :disabled="scan.busy || scan.left === 0" @click="takePhoto">
            <span v-html="icon('scan', 18)"></span>{{ scan.busy ? 'Reading your receipt…' : 'Take a photo' }}
          </button>
          <button type="button" class="t-scan-btn alt" :disabled="scan.busy || scan.left === 0" @click="$refs.scanFile.click()">Upload a photo</button>
        </div>
        <input ref="scanFile" type="file" accept="image/jpeg,image/png,image/webp" multiple hidden @change="pickScan" />
        <p class="t-scan-left" :class="{ out: scan.left === 0 }">{{ scan.left === null ? 'Try the receipt scanner free' : scan.left === 0 ? 'No free scans left' : scan.left + ' of ' + scan.limit + ' free scans left' }}</p>
      </div>
      <p v-if="scan.message" class="t-scan-msg" :class="scan.check === 'mismatch' ? 'warn' : scan.check === 'match' ? 'ok' : ''" role="status">{{ scan.message }}</p>
      <div v-if="scan.total !== null" class="t-scan-total"><span>Total on the receipt</span><b>{{ peso(scan.total) }}</b></div>
      <p v-if="s.sample" class="t-paper-head">KUYA BONG’S GRILL<small>Table 5 · sample receipt</small></p>
      <p v-if="!s.items.length" class="g-small g-muted" style="margin:0 0 10px">Add the dishes below, or load the sample receipt up top. Then tap the names under each dish to say who had it.</p>
      <div v-for="(it, i) in s.items" :key="i" class="t-item">
        <div class="t-item-top">
          <input class="t-in t-name" v-model="it.name" maxlength="60" placeholder="Item" aria-label="Item name" />
          <input class="t-in t-qty" type="number" min="1" max="99" v-model.number="it.qty" aria-label="Quantity" />
          <span class="g-muted">×</span>
          <input class="t-in t-price" type="number" min="0" step="0.01" inputmode="decimal" v-model.number="it.price" aria-label="Price each" />
          <button type="button" class="t-x" @click="removeItem(i)" :aria-label="'Remove ' + it.name" v-html="icon('x', 14)"></button>
        </div>
        <div class="t-assign">
          <button v-for="p in s.people" :key="p.id" type="button" class="t-pill" :class="{ on: it.who.includes(p.id) }" @click="toggle(it, p.id)">
            <i :style="{ background: color(p.id) }">{{ initials(p.name) }}</i>{{ p.name }}
          </button>
          <button type="button" class="t-pill all" :class="{ on: it.who.length === s.people.length }" @click="everyone(it)">Everyone</button>
        </div>
        <p v-if="it.printed" class="t-printed">Receipt says “{{ it.printed }}”</p>
        <p class="t-lineamt">{{ it.qty }} × {{ money(cents(it.price)) }} = <b>{{ money(lineCents(it)) }}</b> · {{ it.who.length ? 'split ' + it.who.length + ' way' + (it.who.length > 1 ? 's' : '') : 'nobody yet' }}</p>
      </div>
      <form class="t-add t-add-item" @submit.prevent="addItem">
        <input class="t-in t-qty" type="number" min="1" max="99" step="1" inputmode="numeric" v-model.number="newItem.qty" placeholder="Qty" aria-label="New item quantity" />
        <input class="t-in" v-model="newItem.name" maxlength="60" placeholder="Dish or drink" aria-label="New item name" />
        <input class="t-in t-price" type="number" min="0" step="0.01" inputmode="decimal" v-model.number="newItem.price" placeholder="₱ each" aria-label="New item price each" />
        <button class="t-add-btn" :disabled="!newItem.name.trim() || !(newItem.price > 0)">Add</button>
      </form>
    </section>

    <!-- 3. Extras -->
    <section class="g-card g-pop">
      <h2><span class="t-num">3</span> Service, tax &amp; discounts</h2>
      <div class="t-grid3">
        <label class="t-field"><span>Service charge</span><input class="t-in" type="number" min="0" step="0.01" inputmode="decimal" v-model.number="s.service" placeholder="0.00" /></label>
        <label class="t-field"><span>Tax (VAT)</span><input class="t-in" type="number" min="0" step="0.01" inputmode="decimal" v-model.number="s.tax" placeholder="0.00" /></label>
        <label class="t-field"><span>Discount</span><input class="t-in" type="number" min="0" step="0.01" inputmode="decimal" v-model.number="s.discount" placeholder="0.00" /></label>
      </div>
      <label class="t-check"><input type="checkbox" v-model="s.taxIncluded" /> Tax is already inside the prices (most receipts in the Philippines)</label>
      <p class="g-small g-muted" style="margin:8px 0 0">Mark someone as Senior or PWD below and the discount goes to them first.</p>
    </section>

    <!-- Locked features -->
    <section class="g-card g-pop">
      <h2><span class="g-h-ic" v-html="icon('lock', 18)"></span> With a free account</h2>
      <div class="t-locked">
        <button v-for="f in locks" :key="f.t" type="button" @click="locked(f.ask)"><span v-html="icon(f.i, 24)"></span>{{ f.t }}<small v-html="icon('lock', 13)"></small></button>
      </div>
    </section>
  </div>
  <div class="g-col g-sticky">
    <!-- Results -->
    <section class="g-card g-pop">
      <h2><span class="t-num" v-html="icon('check', 13)"></span> Everyone’s share</h2>
      <p v-if="c.unassignedCount" class="t-warn"><span v-html="icon('warn', 16)"></span> {{ c.unassignedCount }} item{{ c.unassignedCount > 1 ? 's have' : ' has' }} nobody yet ({{ money(c.unassigned) }}). Tap names above to assign.</p>
      <ul class="t-shares">
        <li v-for="p in s.people" :key="p.id">
          <avatar :user="person(p)" :size="38"></avatar>
          <div class="t-who">
            <b>{{ p.name }}<span v-if="p.id === s.payer" class="t-tag">paid</span></b>
            <small>Items {{ money(c.itemShares[p.id]) }}<template v-if="c.extrasBy[p.id]"> · extras {{ money(c.extrasBy[p.id]) }}</template><template v-if="c.discountBy[p.id]"> · discount −{{ money(c.discountBy[p.id]) }}</template></small>
            <select class="t-disc" v-model="p.discount" :aria-label="'Discount for ' + p.name"><option value="none">No discount</option><option value="senior">Senior</option><option value="pwd">PWD</option></select>
          </div>
          <span class="t-amt">{{ money(c.shares[p.id]) }}</span>
        </li>
      </ul>
      <div class="t-total"><span>Bill total</span><b>{{ money(c.total) }}</b></div>
    </section>

    <section class="g-card g-pop">
      <h2><span class="g-h-ic" v-html="icon('wallet', 18)"></span> Who pays whom</h2>
      <ul v-if="transfers.length" class="t-plan">
        <li v-for="(t, i) in transfers" :key="i"><b>{{ name(t.from) }}</b><span class="t-arrow">→</span><b>{{ name(t.to) }}</b><em>{{ money(t.amount) }}</em></li>
      </ul>
      <p v-else class="g-small g-muted" style="margin:0">{{ c.unassignedCount ? 'Assign every item first and the payments appear here.' : s.items.length ? 'Nobody owes anyone anything.' : 'Add some items to see the payments.' }}</p>
      <p v-if="transfers.length" class="g-small g-muted" style="margin:10px 0 0">{{ name(s.payer) }} paid the bill, so everyone else pays them back, with as few transfers as possible.</p>
    </section>

  </div>
  </div>
  </div>

  <div class="g-bar"><div>
    <img src="<?= $mascot('wave') ?>" alt="" />
    <p>Like it? Make it yours<small>Free account · keeps your bills</small></p>
    <button type="button" @click="invite">Sign up</button>
  </div></div>
</div>

<script>
const SD = SplitDemo;
const SHARE_DELAY = 9000;
Setlo.mount({
  data: () => ({
    s: SD.load() || SD.blank(), newPerson: '', newItem: { name: '', qty: 1, price: '' }, nudged: false,
    scan: { busy: false, left: null, limit: 5, message: '', check: '', total: null },
    locks: [
      { i: 'save', t: 'Save this bill', ask: 'save this bill' }, { i: 'scan', t: 'Unlimited scans', ask: 'scan as many receipts as you like' }, { i: 'users', t: 'Invite friends', ask: 'invite your friends' },
      { i: 'phone', t: 'Pay with GCash or Maya', ask: 'pay with GCash or Maya' }, { i: 'clock', t: 'See your history', ask: 'see your bill history' }, { i: 'trophy', t: 'Earn badges, play games', ask: 'earn badges and play Fun Mode' },
    ],
  }),
  computed: {
    c() { return SD.calc(this.s); },
    transfers() { return SD.plan(this.s, this.c); },
    engaged() { return this.s.people.length > 1 && this.s.items.some((i) => i.who.length); },
  },
  watch: {
    s: { deep: true, handler(v) { SD.save(v); } },
    engaged(v) { if (v) this.nudgeLater(); },
  },
  mounted() {
    if (this.engaged) this.nudgeLater();
    api.get('guest-scan.php').then((r) => { this.scan.left = r.left; this.scan.limit = r.limit; }).catch(() => {});
  },
  methods: {
    cents: SD.cents,
    /** Scan up to 3 photos of one receipt, then fill the split with what was read (items, prices, extras). */
    pickScan(e) {
      const files = [...e.target.files];
      e.target.value = '';
      return this.scanPhotos(files);
    },
    /** Photo mode: the live camera (or the phone's camera app), same scan and same free-scan count as an upload. */
    async takePhoto() {
      if (this.scan.busy) return;
      this.scanPhotos(await Setlo.captureCamera({ max: 3, title: 'Photo of your receipt' }));
    },
    async scanPhotos(all) {
      const files = all.slice(0, 3);
      if (!files.length || this.scan.busy) return;
      if (this.s.items.length && !(await Setlo.confirm({ title: 'Replace your items?', text: 'The scanned receipt will take the place of the items you have now.', confirmText: 'Replace' }))) return;
      this.scan.busy = true;
      this.scan.message = '';
      this.scan.total = null;
      try {
        const fd = new FormData();
        for (const f of files) {
          if (!/^image\/(jpeg|png|webp)$/.test(f.type)) throw new Error(f.name + ' isn’t a JPG, PNG or WebP photo.');
          const small = await Setlo.compressImage(f);
          fd.append('images[]', small, f.name || 'receipt.jpg');
        }
        const r = await api.post('guest-scan.php', fd);
        this.scan.left = r.left;
        this.scan.message = r.message;
        this.scan.check = r.total_check || '';
        if (!r.found) return;
        this.fillFromScan(r);
        this.scan.total = r.total;
        if (r.currency && r.currency !== 'PHP') this.scan.message += ' The receipt looks like ' + r.currency + '; guest mode shows amounts in pesos.';
      } catch (err) {
        this.scan.check = 'warn';
        this.scan.message = err.message;
        if (err.data && typeof err.data.left === 'number') this.scan.left = err.data.left;
        if (err.data && err.data.code === 'limit') this.locked('scan as many receipts as you like');
      } finally {
        this.scan.busy = false;
      }
    },
    fillFromScan(r) {
      this.s.sample = false;
      this.s.items = r.items.map((it) => ({ name: it.name, printed: it.printed_name !== it.name ? it.printed_name : '', qty: it.qty, price: it.price, who: [] }));
      this.s.tax = r.tax || 0;
      this.s.service = r.service || 0;
      this.s.discount = r.discount || 0;
      this.s.taxIncluded = !!r.tax_included;
    },
    icon: SetloIcons.svg,
    initials: SD.initials,
    color(id) { return SD.COLORS[(id - 1) % SD.COLORS.length]; },
    person(p) { return { name: p.name, initials: SD.initials(p.name), color: this.color(p.id), photo: null }; },
    name(id) { const p = this.s.people.find((x) => x.id === id); return p ? p.name || 'Person ' + id : '?'; },
    money(c) { return this.peso((c || 0) / 100); },
    lineCents(it) { return Math.max(1, Math.trunc(Number(it.qty) || 1)) * SD.cents(it.price); },
    addPerson() {
      const name = this.newPerson.trim();
      if (!name || this.s.people.length >= 12) return;
      this.s.people.push({ id: this.s.nextId++, name, discount: 'none' });
      this.newPerson = '';
    },
    removePerson(p) {
      this.s.people = this.s.people.filter((x) => x.id !== p.id);
      this.s.items.forEach((it) => { it.who = it.who.filter((id) => id !== p.id); });
      if (this.s.payer === p.id) this.s.payer = this.s.people[0].id;
    },
    addItem() {
      if (!this.newItem.name.trim() || !(this.newItem.price > 0)) return;
      const qty = Math.min(99, Math.max(1, Math.trunc(Number(this.newItem.qty) || 1))); // blank or bad quantity counts as 1
      this.s.items.push({ name: this.newItem.name.trim(), qty, price: this.newItem.price, who: [] });
      this.newItem = { name: '', qty: 1, price: '' };
    },
    removeItem(i) { this.s.items.splice(i, 1); },
    toggle(it, id) { it.who = it.who.includes(id) ? it.who.filter((x) => x !== id) : [...it.who, id]; },
    /** Every dish shared by everyone. */
    async splitAll() {
      if (this.s.items.some((it) => it.who.length) && !(await Setlo.confirm({ title: 'Split everything equally?', text: 'Every item will be shared by everyone, replacing who you picked for each one.', confirmText: 'Split equally' }))) return;
      const all = this.s.people.map((p) => p.id);
      this.s.items.forEach((it) => { it.who = [...all]; });
    },
    everyone(it) { it.who = it.who.length === this.s.people.length ? [] : this.s.people.map((p) => p.id); },
    useSample() { this.s = SD.sample(); Setlo.toast('Sample receipt loaded. Try changing who had what.', 'ok'); },
    reset() { this.s = SD.blank(); this.nudged = false; },
    /** The mascot's invitation, once per tab: a few seconds after the first dish gets assigned. */
    nudgeLater() {
      if (this.nudged) return;
      this.nudged = true;
      setTimeout(() => SetloInvite.once('demo', { variant: 'demo', registerHref: 'register?from=demo' }), SHARE_DELAY);
    },
    invite() { SetloInvite.show({ variant: 'demo', registerHref: 'register?from=demo' }); },
    locked(feature) { SetloInvite.show({ variant: 'locked', feature, registerHref: 'register?from=demo' }); },
  },
});
</script>
<?php require __DIR__ . '/../partials/foot.php'; ?>
