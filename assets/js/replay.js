// Bill replay: an animated walk-through of where the money went on one bill. Who handed cash to the cashier, how
// each item and charge was split, everyone's fair share, the balance that leaves, and who pays whom. Members sit
// round the receipt by their profile picture and amounts fly between them. Built from a bills.php?id= reply only.
(function (global) {
  const cents = (v) => Math.round(Number(v || 0) * 100);
  // Items get a step each up to this many; the rest are shared out in one step.
  const MAX_ITEM_STEPS = 10;
  const CHAPTERS = { intro: 'The bill', pay: '1 · Who paid', split: '2 · Who had what', share: '3 · Fair shares', balance: '4 · Who’s up, who owes', settle: '5 · Settling up', done: 'Done' };
  const STATUS_NOTE = { settled: ' · ✓ paid and confirmed', awaiting: ' · paid, waiting for confirmation', pending: ' · not paid yet', disputed: ' · disputed' };

  /** split_evenly() in includes/domain.php: equal parts, any odd centavos to the lowest ids. */
  function splitEvenly(total, ids) {
    const s = [...ids].sort((a, b) => a - b);
    const base = Math.floor(total / s.length);
    const rem = total - base * s.length;
    return Object.fromEntries(s.map((id, i) => [id, base + (i < rem ? 1 : 0)]));
  }

  /**
   * The replay as a list of scenes: { phase, title, math, flights: [{ from, to, amount, cur, tone, dashed, minus }],
   * after: the state once the scene's money has landed }. 'c' is the receipt in the middle. All amounts in cents.
   */
  function build(d) {
    const S = global.Setlo;
    const bill = d.bill;
    const cur = bill.currency;
    const rcur = bill.receipt_currency || cur;
    const ids = d.members.map((m) => m.id);
    const idSet = new Set(ids);
    const byId = Object.fromEntries(d.members.map((m) => [m.id, m]));
    const meId = d.me ? d.me.id : 0;
    const who = (id) => (id === meId ? 'You' : byId[id] ? byId[id].first : 'Someone');
    const pays = (id) => who(id) + (id === meId ? ' pay ' : ' pays ');
    const fmt = (v, k) => S.peso(v / 100, k || cur);
    const names = (list) => (list.length <= 2 ? list.map(who).join(' & ') : list.slice(0, -1).map(who).join(', ') + ' & ' + who(list[list.length - 1]));
    const each = (parts) => { const v = Object.values(parts); return Math.min(...v) === Math.max(...v) ? '' : 'about '; };
    const total = cents(d.total);
    const shares = Object.fromEntries(ids.map((id) => [id, cents(d.shares[id])]));
    const balances = Object.fromEntries(ids.map((id) => [id, cents(d.balances[id])]));

    const st = { phase: 'intro', paid: {}, tally: {}, tallyCur: rcur, bal: {}, center: { label: 'Bill total', amount: total, cur } };
    const scenes = [];
    const push = (s) => scenes.push({ flights: [], ...s, phase: st.phase, after: JSON.parse(JSON.stringify(st)) });

    push({ title: bill.name, math: fmt(total) + ' for ' + ids.length + (ids.length === 1 ? ' person' : ' people') + '. Here’s where the money went, step by step.' });

    // 1. Cash handed to the cashier, biggest first; any change goes back as settlement_plan() decides
    const paid = {};
    for (const [k, v] of Object.entries((d.payments && d.payments.paid) || {})) if (idSet.has(+k) && cents(v) > 0) paid[+k] = cents(v);
    const payers = Object.keys(paid).map(Number).sort((a, b) => paid[b] - paid[a] || a - b);
    st.phase = 'pay';
    st.center = { label: 'Handed to cashier', amount: 0, cur };
    let handed = 0;
    for (const id of payers) {
      handed += paid[id];
      st.paid[id] = paid[id];
      st.center.amount = handed;
      push(payers.length === 1
        ? { title: who(id) + ' paid the bill', math: who(id) + ' handed ' + fmt(paid[id]) + ' to the cashier — the whole bill, up front.', flights: [{ from: id, to: 'c', amount: paid[id], cur }] }
        : { title: who(id) + ' chipped in', math: fmt(paid[id]) + ' to the cashier · ' + fmt(handed) + ' of ' + fmt(total) + ' so far', flights: [{ from: id, to: 'c', amount: paid[id], cur }] });
    }
    const change = cents(d.payments && d.payments.change);
    if (change > 0 && payers.length) {
      const holder = paid[bill.payer_id] >= change ? bill.payer_id : payers[0];
      st.paid[holder] -= change;
      st.center.amount = handed - change;
      push({ title: 'Change comes back', math: fmt(handed) + ' handed − ' + fmt(total) + ' bill = ' + fmt(change) + ' change to ' + who(holder), flights: [{ from: 'c', to: holder, amount: change, cur, tone: 'ok' }] });
    }

    // 2. What each person had, in the receipt's currency
    st.phase = 'split';
    st.tallyCur = rcur;
    ids.forEach((id) => { st.tally[id] = 0; });
    if (bill.split_mode === 'percent' || bill.split_mode === 'game') {
      st.center = { label: 'Left to split', amount: 0, cur: rcur };
      ids.forEach((id) => { st.tally[id] = cents(d.native_shares && d.native_shares[id]); });
      const math = bill.split_mode === 'percent'
        ? d.members.filter((m) => m.percent).map((m) => who(m.id) + ' ' + m.percent + '%').join(' · ') + ' of ' + fmt(cents(d.native_total), rcur)
        : (bill.game && bill.game.title ? bill.game.title : 'A Fun Mode game') + ' decided who pays what of the ' + fmt(cents(d.native_total), rcur) + '.';
      push({ title: bill.split_mode === 'percent' ? 'Split by percentage' : 'The game decided', math, flights: ids.filter((id) => st.tally[id] > 0).map((id) => ({ from: 'c', to: id, amount: st.tally[id], cur: rcur })) });
    } else {
      let left = cents(d.subtotal);
      st.center = { label: 'Left to split', amount: left, cur: rcur };
      const share = (it) => {
        const w = it.who.filter((id) => idSet.has(id));
        return { line: cents(it.line_total), w, parts: w.length ? splitEvenly(cents(it.line_total), w) : {} };
      };
      const stepped = d.items.length > MAX_ITEM_STEPS ? d.items.slice(0, MAX_ITEM_STEPS - 1) : d.items;
      for (const it of stepped) {
        const { line, w, parts } = share(it);
        const title = it.name + (it.qty > 1 ? ' ×' + it.qty : '');
        if (!w.length) {
          push({ title, math: fmt(line, rcur) + ' · nobody is assigned to it yet', warn: true });
          continue;
        }
        w.forEach((id) => { st.tally[id] += parts[id]; });
        left -= line;
        st.center.amount = left;
        const one = Math.min(...Object.values(parts));
        const math = w.length === 1
          ? fmt(line, rcur) + ' — all ' + (w[0] === meId ? 'yours' : who(w[0]) + '’s')
          : fmt(line, rcur) + ' ÷ ' + w.length + (w.length === ids.length ? ' (everyone)' : ' (' + names(w) + ')') + ' = ' + each(parts) + fmt(one, rcur) + ' each';
        push({ title, math, flights: w.map((id) => ({ from: 'c', to: id, amount: parts[id], cur: rcur })) });
      }
      const rest = d.items.slice(stepped.length);
      if (rest.length) {
        const add = {};
        let sum = 0;
        let skipped = 0;
        for (const it of rest) {
          const { line, w, parts } = share(it);
          if (!w.length) { skipped++; continue; }
          w.forEach((id) => { add[id] = (add[id] || 0) + parts[id]; st.tally[id] += parts[id]; });
          sum += line;
        }
        left -= sum;
        st.center.amount = left;
        push({ title: rest.length + ' more items', math: fmt(sum, rcur) + ' shared the same way, each item to whoever had it' + (skipped ? ' · ' + skipped + ' not assigned yet' : ''), warn: !!skipped,
          flights: Object.keys(add).map(Number).filter((id) => add[id] > 0).map((id) => ({ from: 'c', to: id, amount: add[id], cur: rcur })) });
      }
      const extras = cents(d.extras);
      if (extras > 0 && ids.length) {
        const parts = splitEvenly(extras, ids);
        ids.forEach((id) => { st.tally[id] += parts[id]; });
        push({ title: bill.tax_included ? 'Service charge' : 'Tax + service charge', math: fmt(extras, rcur) + ' ÷ ' + ids.length + ' people = ' + each(parts) + fmt(Math.min(...Object.values(parts)), rcur) + ' each — everyone shares it equally',
          flights: ids.map((id) => ({ from: 'c', to: id, amount: parts[id], cur: rcur })) });
      }
      const disc = cents(bill.discount);
      if (disc > 0) {
        const by = Object.fromEntries(ids.map((id) => [id, cents(d.discount_by && d.discount_by[id])]));
        ids.forEach((id) => { st.tally[id] -= by[id]; });
        const flagged = d.members.filter((m) => m.discount_type && m.discount_type !== 'none').map((m) => m.id);
        push({ title: 'Discount', math: fmt(disc, rcur) + ' off — ' + (flagged.length ? names(flagged) + ' (Senior/PWD) first, the rest shared by what each ordered' : 'shared in proportion to what each person ordered'),
          flights: ids.filter((id) => by[id] > 0).map((id) => ({ from: 'c', to: id, amount: by[id], cur: rcur, tone: 'ok', minus: true })) });
      }
    }

    const fx = bill.fx || {};
    if (fx.foreign) {
      st.tallyCur = cur;
      ids.forEach((id) => { st.tally[id] = shares[id]; });
      st.center = { label: 'Bill total', amount: total, cur };
      push({ title: 'Converted to ' + cur, math: fx.missing
        ? 'There’s no exchange rate yet, so the shares can’t be worked out in ' + cur + '.'
        : fmt(cents(d.native_total), rcur) + ' at ' + S.fmtRate(fx.rate, rcur, cur) + (fx.fees > 0 ? ' + ' + fmt(cents(fx.fees)) + ' fees (shared equally)' : '') + ' = ' + fmt(total), warn: !!fx.missing });
    }

    // 3. Fair shares, and whether they add up to the bill
    st.phase = 'share';
    st.tallyCur = cur;
    ids.forEach((id) => { st.tally[id] = shares[id]; });
    st.center = { label: 'Bill total', amount: total, cur };
    const shareSum = ids.reduce((s, id) => s + shares[id], 0);
    const ledger = ids.map((id) => ({ id, paid: balances[id] + shares[id], share: shares[id], bal: balances[id] }));
    let blocker = '';
    if (d.unassigned_count) blocker = fmt(cents(d.unassigned), rcur) + ' of items isn’t assigned to anyone yet. Assign every item to finish the split.';
    else if (bill.split_mode === 'percent' && bill.percent_total !== 100) blocker = 'The percentages add up to ' + bill.percent_total + '%, not 100%.';
    else if (fx.missing) blocker = 'Add an exchange rate to finish the split.';
    else if (d.payments && d.payments.mismatch) blocker = 'The cash handed over (' + fmt(cents(d.payments.total)) + ') doesn’t cover the ' + fmt(total) + ' bill.';
    push({ title: 'Everyone’s fair share', ledger: blocker ? null : ledger, warn: !!blocker, math: blocker || (ids.length <= 4
      ? ids.map((id) => fmt(shares[id])).join(' + ') + ' = ' + fmt(shareSum) + (shareSum === total ? ' ✓ the whole bill' : '')
      : ids.length + ' shares add up to ' + fmt(shareSum) + (shareSum === total ? ' ✓ the whole bill' : '')) });
    if (blocker) return { scenes, ledger, cur };

    // 4. Paid − share: who is out of pocket and who still owes
    st.phase = 'balance';
    ids.forEach((id) => { st.bal[id] = balances[id]; });
    const ups = ids.filter((id) => balances[id] > 0).sort((a, b) => balances[b] - balances[a]);
    const downs = ids.filter((id) => balances[id] < 0);
    push({ title: 'Paid − share = balance', ledger,
      math: ups.length
        ? who(ups[0]) + ' paid ' + fmt(ledger.find((l) => l.id === ups[0]).paid) + ' but only had ' + fmt(shares[ups[0]]) + ', so ' + (ups[0] === meId ? 'you get' : who(ups[0]) + ' gets') + ' ' + fmt(balances[ups[0]]) + ' back. '
          + (downs.length ? downs.length === 1 ? who(downs[0]) + (downs[0] === meId ? ' owe' : ' owes') + ' their share.' : downs.length + ' people owe their share.' : '')
        : 'Everyone paid exactly their own share — nobody owes anyone.' });

    // 5. The transfers: the real settlements once settling started, else the proposed plan
    const transfers = d.settlements.length
      ? d.settlements.map((s) => ({ from: s.from.id, to: s.to.id, amount: cents(s.amount), status: s.status }))
      : (d.plan || []).map((t) => ({ from: t.from, to: t.to, amount: cents(t.amount) }));
    st.phase = 'settle';
    let out = transfers.reduce((s, t) => s + t.amount, 0);
    st.center = { label: 'Still to settle', amount: out, cur };
    for (const t of transfers) {
      if (idSet.has(t.from)) st.bal[t.from] += t.amount;
      if (idSet.has(t.to)) st.bal[t.to] -= t.amount;
      out -= t.amount;
      st.center.amount = out;
      const open = t.status && t.status !== 'settled';
      push({ title: pays(t.from) + who(t.to), math: fmt(t.amount) + (t.status ? STATUS_NOTE[t.status] || '' : d.settlements.length ? '' : ' · proposed'), flights: [{ from: t.from, to: t.to, amount: t.amount, cur, dashed: open }] });
    }

    st.phase = 'done';
    const openCount = transfers.filter((t) => t.status && t.status !== 'settled').length;
    const even = ids.every((id) => st.bal[id] === 0);
    st.center = { label: even && !openCount ? 'All even' : 'Bill total', amount: total, cur };
    push({ title: even && !openCount ? 'Everyone’s even ✓' : even ? 'Almost there' : 'Where it stands', ledger,
      math: (transfers.length ? transfers.length + (transfers.length === 1 ? ' payment settles' : ' payments settle') + ' the whole ' + fmt(total) + ' bill.' : 'Nobody owes anyone.')
        + (openCount ? ' ' + openCount + ' still ' + (openCount === 1 ? 'needs' : 'need') + ' to be paid or confirmed.' : '')
        + (!d.settlements.length && transfers.length ? ' This is the proposed plan until the bill starts settling.' : '') });
    return { scenes, ledger, cur };
  }

  /** A point along the curve from a to b (bowed through the middle for person-to-person, gently otherwise). */
  function curve(a, b, mid, viaCenter) {
    const mx = (a.x + b.x) / 2;
    const my = (a.y + b.y) / 2;
    let cx; let cy;
    if (viaCenter) { cx = mx + (mid.x - mx) * 0.55; cy = my + (mid.y - my) * 0.55; } else {
      const dx = b.x - a.x; const dy = b.y - a.y;
      cx = mx - dy * 0.18; cy = my + dx * 0.18;
    }
    return { c: { x: cx, y: cy }, at: (t) => ({ x: (1 - t) * (1 - t) * a.x + 2 * (1 - t) * t * cx + t * t * b.x, y: (1 - t) * (1 - t) * a.y + 2 * (1 - t) * t * cy + t * t * b.y }) };
  }

  const BillReplay = {
    props: { d: { type: Object, required: true } },
    data: () => ({ open: false, i: 0, playing: false, speed: 1, size: 320, shown: null, flights: [], pulse: {}, run: 0, timer: null, anims: [], keyFn: null, resizeFn: null, chapters: CHAPTERS }),
    computed: {
      model() { return this.open ? build(this.d) : { scenes: [], ledger: [] }; },
      scenes() { return this.model.scenes; },
      scene() { return this.scenes[this.i] || null; },
      state() { return this.shown || (this.scene && this.scene.after); },
      meId() { return this.d.me ? this.d.me.id : 0; },
      byId() { return Object.fromEntries(this.d.members.map((m) => [m.id, m])); },
      av() { const n = this.d.members.length; return n <= 6 ? 46 : n <= 9 ? 38 : 32; },
      /** Avatar centres round the receipt, first person at the top, room left underneath for the name and amount. */
      spots() {
        const s = this.size;
        const r = s / 2 - this.av / 2 - 32;
        const n = this.d.members.length;
        const out = { c: { x: s / 2, y: s / 2 } };
        this.d.members.forEach((m, k) => {
          const a = -Math.PI / 2 + (2 * Math.PI * k) / n;
          out[m.id] = { x: s / 2 + r * Math.cos(a), y: s / 2 + r * Math.sin(a) };
        });
        return out;
      },
      ring() { return this.size / 2 - this.av / 2 - 32; },
      centerSize() { return Math.round(Math.min(92, this.size * 0.27)); },
      /** People the current step is about; everyone else fades back. */
      involved() {
        const set = new Set();
        for (const f of (this.scene && this.scene.flights) || []) { set.add(f.from); set.add(f.to); }
        return set;
      },
      compact() { return this.d.members.length > 8; },
    },
    beforeUnmount() { this.stop(); this.unbind(); },
    methods: {
      money(v, cur, short) { return (short ? global.Setlo.pesoShort : global.Setlo.peso)(v / 100, cur); },
      name(id) { return id === this.meId ? 'You' : (this.byId[id] || {}).first; },
      chip(id) {
        const s = this.state;
        const f = (v, k) => this.money(v, k || this.model.cur, this.compact);
        if (!s) return null;
        if (s.phase === 'pay') return s.paid[id] ? { text: 'paid ' + f(s.paid[id]), tone: 'pay' } : null;
        if (s.phase === 'split' || s.phase === 'share') return { text: f(s.tally[id] || 0, s.tallyCur), tone: s.phase === 'share' ? 'share' : '' };
        if (s.phase === 'balance' || s.phase === 'settle' || s.phase === 'done') {
          const b = s.bal[id] || 0;
          if (b > 0) return { text: '+' + f(b) + ' back', tone: 'up' };
          if (b < 0) return { text: 'owes ' + f(-b), tone: 'down' };
          return { text: '✓ even', tone: 'even' };
        }
        return null;
      },
      at(id) { const p = this.spots[id] || this.spots.c; return { left: p.x + 'px', top: p.y + 'px' }; },
      async show() {
        this.open = true;
        this.i = 0;
        this.shown = null;
        this.bind();
        await this.$nextTick();
        this.measure();
        this.playing = true;
        this.go(0);
      },
      close() { this.stop(); this.unbind(); this.open = false; },
      bind() {
        this.unbind();
        this.keyFn = (e) => this.onKey(e);
        this.resizeFn = () => this.measure();
        document.addEventListener('keydown', this.keyFn);
        window.addEventListener('resize', this.resizeFn);
      },
      unbind() {
        if (this.keyFn) document.removeEventListener('keydown', this.keyFn);
        if (this.resizeFn) window.removeEventListener('resize', this.resizeFn);
        this.keyFn = this.resizeFn = null;
      },
      onKey(e) {
        if (e.key === 'Escape') this.close();
        else if (e.key === 'ArrowRight') this.step(1);
        else if (e.key === 'ArrowLeft') this.step(-1);
        else if (e.key === ' ') { e.preventDefault(); this.toggle(); }
      },
      measure() { const el = this.$refs.stage; if (el) this.size = Math.max(260, Math.min(380, el.clientWidth)); },
      stop() {
        this.run++;
        clearTimeout(this.timer);
        this.anims.forEach((a) => a.cancel());
        this.anims = [];
        this.flights = [];
      },
      toggle() {
        if (this.playing) { this.playing = false; clearTimeout(this.timer); return; }
        this.playing = true;
        this.go(this.i >= this.scenes.length - 1 ? 0 : this.i + 1);
      },
      step(by) { this.playing = false; this.go(Math.max(0, Math.min(this.scenes.length - 1, this.i + by))); },
      jump(k) { this.playing = false; this.go(k); },
      restart() { this.playing = true; this.go(0); },
      cycleSpeed() { this.speed = this.speed === 1 ? 2 : this.speed === 2 ? 0.5 : 1; },
      /** Show scene k: the state before it, its money flying, then the state after it; on to the next while playing. */
      async go(k) {
        this.stop();
        const run = this.run;
        const sc = this.scenes[k];
        if (!sc) return;
        this.i = k;
        this.pulse = {};
        const reduce = global.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (sc.flights.length && !reduce) {
          this.shown = k > 0 ? this.scenes[k - 1].after : sc.after;
          const mid = this.spots.c;
          this.flights = sc.flights.map((f, n) => {
            const a = this.spots[f.from] || mid;
            const b = this.spots[f.to] || mid;
            const cv = curve(a, b, mid, f.from !== 'c' && f.to !== 'c');
            return { ...f, k: run + '-' + n, cv, path: `M${a.x} ${a.y} Q${cv.c.x} ${cv.c.y} ${b.x} ${b.y}`, label: (f.minus ? '−' : '') + this.money(f.amount, f.cur, this.compact) };
          });
          await this.$nextTick();
          if (run !== this.run) return;
          const els = this.$refs.stage ? [...this.$refs.stage.querySelectorAll('.rp-token')] : [];
          const dur = 1100 / this.speed;
          const gap = Math.min(150, 700 / this.flights.length) / this.speed;
          this.anims = els.map((el, n) => {
            const f = this.flights[n];
            const frames = [];
            for (let s = 0; s <= 16; s++) {
              const t = s / 16;
              const p = f.cv.at(t);
              frames.push({ offset: t, opacity: t < 0.1 ? t * 10 : t > 0.9 ? (1 - t) * 10 : 1, transform: `translate(${p.x}px, ${p.y}px) translate(-50%, -50%) scale(${t < 0.15 ? 0.6 + t * 2.6 : t > 0.85 ? 1 - (t - 0.85) * 2 : 1})` });
            }
            return el.animate(frames, { duration: dur, delay: n * gap, easing: 'cubic-bezier(.45,.05,.35,1)', fill: 'both' });
          });
          await Promise.all(this.anims.map((a) => a.finished.catch(() => {})));
          if (run !== this.run) return;
          this.flights = [];
          this.anims = [];
          this.pulse = Object.fromEntries(sc.flights.map((f) => [f.to, true]));
        }
        this.shown = sc.after;
        if (!this.playing) return;
        if (k >= this.scenes.length - 1) { this.playing = false; return; }
        const hold = Math.min(4200, 1500 + (sc.math || '').length * 16) / this.speed;
        this.timer = setTimeout(() => { if (run === this.run && this.playing) this.go(k + 1); }, hold);
      },
    },
    template: `<div>
      <button @click="show" class="btn btn-outline w-full">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path stroke-linejoin="round" d="M10 8.5v7l5.5-3.5z"/></svg>
        Replay the bill
      </button>
      <Teleport to="body">
      <div v-if="open" class="sheet-backdrop" @click.self="close">
        <div class="sheet rp-sheet" role="dialog" aria-label="Bill replay">
          <div class="sheet-grip"></div>
          <div class="mb-3 flex items-center justify-between gap-3">
            <div class="min-w-0">
              <h2 class="text-[17px] font-extrabold text-ink">Bill replay</h2>
              <p class="truncate text-[12px] font-semibold text-slate-400">{{ scene ? chapters[scene.phase] : '' }} · step {{ i + 1 }} of {{ scenes.length }}</p>
            </div>
            <button @click="close" class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-slate-100 text-slate-500" aria-label="Close">✕</button>
          </div>

          <div ref="stage" class="rp-stage" :style="{ height: size + 'px', '--av': av + 'px' }">
            <svg class="rp-svg" :viewBox="'0 0 ' + size + ' ' + size" aria-hidden="true">
              <circle :cx="size / 2" :cy="size / 2" :r="ring" class="rp-orbit" />
              <path v-for="f in flights" :key="'p' + f.k" :d="f.path" class="rp-trail" :class="[f.tone, { dashed: f.dashed }]" />
            </svg>
            <div v-if="state" class="rp-center" :style="[at('c'), { width: centerSize + 'px', height: centerSize + 'px' }]">
              <span class="rp-center-ico" aria-hidden="true">🧾</span>
              <span class="rp-center-label">{{ state.center.label }}</span>
              <b>{{ money(state.center.amount, state.center.cur, compact) }}</b>
            </div>
            <div v-for="(m, n) in d.members" :key="m.id" class="rp-node" :class="{ pulse: pulse[m.id], dim: involved.size && !involved.has(m.id) }" :style="[at(m.id), { '--i': n }]">
              <div class="rp-face"><avatar :user="m" :size="av" :ring="true"></avatar></div>
              <div class="rp-label">
                <span class="rp-name">{{ name(m.id) }}</span>
                <span v-if="chip(m.id)" class="rp-chip" :class="chip(m.id).tone">{{ chip(m.id).text }}</span>
              </div>
            </div>
            <div v-for="f in flights" :key="f.k" class="rp-token" :class="[f.tone, { dashed: f.dashed }]">{{ f.label }}</div>
          </div>

          <div v-if="scene" :key="i" class="rp-caption" :class="{ warn: scene.warn }" aria-live="polite">
            <p class="rp-title">{{ scene.title }}</p>
            <p class="rp-math">{{ scene.math }}</p>
          </div>

          <table v-if="scene && scene.ledger" class="rp-ledger">
            <thead><tr><th></th><th>Paid</th><th>Share</th><th>Balance</th></tr></thead>
            <tbody>
              <tr v-for="l in scene.ledger" :key="l.id">
                <td><span class="flex items-center gap-1.5"><avatar :user="byId[l.id]" :size="20"></avatar><span class="truncate">{{ name(l.id) }}</span></span></td>
                <td>{{ money(l.paid, model.cur) }}</td>
                <td>{{ money(l.share, model.cur) }}</td>
                <td :class="l.bal > 0 ? 'up' : l.bal < 0 ? 'down' : ''">{{ l.bal > 0 ? '+' : '' }}{{ money(l.bal, model.cur) }}</td>
              </tr>
            </tbody>
          </table>

          <div class="rp-bar">
            <button v-for="(s, k) in scenes" :key="k" :class="{ on: k <= i }" @click="jump(k)" :aria-label="'Go to step ' + (k + 1)"></button>
          </div>
          <div class="rp-controls">
            <button @click="restart" class="rp-btn" aria-label="Replay from the start">
              <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v6h6M5.5 15a7 7 0 101.6-7.3L4 10"/></svg>
            </button>
            <button @click="step(-1)" class="rp-btn" :disabled="i === 0" aria-label="Previous step">
              <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 18l-6-6 6-6"/></svg>
            </button>
            <button @click="toggle" class="rp-btn main" :aria-label="playing ? 'Pause' : 'Play'">
              <svg v-if="playing" class="h-5 w-5" fill="currentColor" viewBox="0 0 24 24"><rect x="6" y="5" width="4" height="14" rx="1"/><rect x="14" y="5" width="4" height="14" rx="1"/></svg>
              <svg v-else class="ml-0.5 h-5 w-5" fill="currentColor" viewBox="0 0 24 24"><path d="M7 4.5v15l12.5-7.5z"/></svg>
            </button>
            <button @click="step(1)" class="rp-btn" :disabled="i >= scenes.length - 1" aria-label="Next step">
              <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 6l6 6-6 6"/></svg>
            </button>
            <button @click="cycleSpeed" class="rp-btn" :aria-label="'Speed ' + speed + '×'">{{ speed }}×</button>
          </div>
        </div>
      </div>
      </Teleport>
    </div>`,
  };
  global.BillReplay = BillReplay;
  global.SetloReplay = { build };
})(window);
