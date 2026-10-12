// Setlo UI helpers: formatters, toast, shared Vue components and the mount() entry point.
(function (global) {
  const { createApp } = global.Vue;

  // ---------- Money in any currency (includes/currency.php CURRENCIES, put on the page by partials/head.php) ----------
  const CURRENCIES = Object.fromEntries((global.SETLO_CURRENCIES || [{ code: 'PHP', symbol: '₱', name: 'Philippine peso', decimals: 2 }]).map((c) => [c.code, c]));
  // What peso() formats in when no currency is given: PHP, or the bill's once a bill page calls Setlo.useCurrency()
  let pageCurrency = 'PHP';
  const cinfo = (code) => CURRENCIES[code] || { code, symbol: code + ' ', name: code, decimals: 2 };
  const sym = (code) => { const s = cinfo(code).symbol; return /^[A-Z]{2,}$/.test(s) ? s + ' ' : s; };
  /** "₱1,234.50", "¥6,000", "AED 12.00". cur: a currency code (default: the page's). */
  const peso = (n, cur) => {
    const c = cinfo(cur || pageCurrency);
    const v = Number(n || 0);
    return (v < 0 ? '-' : '') + sym(c.code) + Math.abs(v).toLocaleString('en-PH', { minimumFractionDigits: c.decimals, maximumFractionDigits: c.decimals });
  };
  const pesoShort = (n, cur) => sym(cur || pageCurrency) + Math.round(Number(n || 0)).toLocaleString('en-PH');
  const currencySymbol = (cur) => cinfo(cur || pageCurrency).symbol;
  const currencyLabel = (cur) => { const c = cinfo(cur); return c.code + ' · ' + c.name; };
  /** "1 JPY = ₱0.4100" */
  const fmtRate = (rate, from, to) => '1 ' + from + ' = ' + sym(to) + Number(rate).toLocaleString('en-PH', { maximumFractionDigits: Number(rate) < 1 ? 6 : 4 });
  /** Format everything on this page in `code` unless told otherwise (a bill page: its agreed currency). */
  const useCurrency = (code) => { pageCurrency = CURRENCIES[code] ? code : 'PHP'; };

  // ---------- Converting shares: mirrors allocate(), round_shares() and convert_calc() in PHP ----------
  // BigInt, because weight × total can pass 2^53 cents.
  const floorDiv = (a, b) => { const q = a / b; return (a % b !== 0n && a < 0n) ? q - 1n : q; };
  /** Share `total` cents in proportion to weights { id: cents }, largest remainder; adds up exactly. */
  function allocate(total, weights) {
    const ids = Object.keys(weights).map(Number).sort((a, b) => a - b);
    const sum = ids.reduce((s, id) => s + BigInt(weights[id]), 0n);
    if (sum === 0n) {
      if (!total || !ids.length) return Object.fromEntries(ids.map((id) => [id, 0]));
      const base = Math.floor(total / ids.length);
      return Object.fromEntries(ids.map((id, i) => [id, base + (i < total - base * ids.length ? 1 : 0)]));
    }
    const sign = sum < 0n ? -1n : 1n;
    const out = {}; const rem = {};
    let given = 0;
    for (const id of ids) {
      const num = BigInt(weights[id]) * BigInt(total) * sign;
      const q = floorDiv(num, sum * sign);
      out[id] = Number(q);
      rem[id] = num - q * sum * sign;
      given += out[id];
    }
    let left = total - given;
    const order = [...ids].sort((a, b) => (rem[b] > rem[a] ? 1 : rem[b] < rem[a] ? -1 : a - b));
    for (const id of order) { if (left <= 0) break; out[id]++; left--; }
    return out;
  }
  /** Rounding policy: every share but the payer's to a whole unit (nearest / up); the payer's absorbs the difference. */
  function roundShares(shares, policy, currency, payerId) {
    if (policy === 'exact' && cinfo(currency).decimals > 0) return shares;
    const out = { ...shares };
    const ids = Object.keys(out).map(Number);
    const payer = ids.includes(payerId) ? payerId : ids[0];
    let diff = 0;
    for (const id of ids) {
      if (id === payer) continue;
      const c = out[id];
      const r = policy === 'up' ? Math.ceil(c / 100) * 100 : Math.floor((c + 50) / 100) * 100;
      diff += r - c;
      out[id] = r;
    }
    out[payer] -= diff;
    return out;
  }
  /**
   * "Round to whole pesos", as round_to_whole() in PHP: the total to the nearest whole unit and, once everything is
   * shared out, every share in whole units adding up to it. Returns { shares, total, exactTotal, roundDiff }.
   */
  function roundWhole(res, on, currency) {
    const out = { ...res, exactTotal: res.total, roundDiff: 0 };
    if (!on || cinfo(currency).decimals === 0) return out;
    const rounded = Math.floor((res.total + 50) / 100) * 100;
    const sum = Object.values(res.shares).reduce((a, b) => a + b, 0);
    if (rounded > 0 && sum === res.total) {
      out.shares = Object.fromEntries(Object.entries(allocate(rounded / 100, res.shares)).map(([id, u]) => [id, u * 100]));
    }
    out.roundDiff = rounded - res.total;
    out.total = rounded;
    return out;
  }
  /**
   * Receipt-currency shares (cents) → the bill's settlement currency, as the server's convert_calc() does.
   * fx: bills.php bill.fx; nativeTotal: whole bill in receipt cents; roundTotal: bills.php bill.round_total.
   * Returns { shares, total, exactTotal, roundDiff }.
   */
  function convertShares(native, nativeTotal, fx, payerId, roundTotal = false) {
    const cur = fx ? fx.currency : 'PHP';
    return roundWhole(convertExact(native, nativeTotal, fx, payerId), roundTotal, cur);
  }
  function convertExact(native, nativeTotal, fx, payerId) {
    if (!fx || !fx.foreign) return { shares: roundShares(native, 'exact', fx ? fx.currency : 'PHP', payerId), total: nativeTotal };
    const c = (v) => Math.round(Number(v || 0) * 100);
    // in the settlement currency's smallest unit (whole yen, won…), as bill_fx() does
    const unit = cinfo(fx.currency).decimals === 0 ? 100 : 1;
    const conv = (v) => Math.round(v / unit) * unit;
    let converted;
    if (fx.missing) converted = 0;
    else if (fx.mode === 'actual' && fx.actual_total !== null) converted = conv(c(fx.actual_total));
    else converted = conv(nativeTotal * Number(fx.mode === 'manual' && fx.manual_rate ? fx.manual_rate : (fx.market ? fx.market.rate : 0)));
    const ids = Object.keys(native).map(Number);
    const assigned = ids.reduce((s, id) => s + native[id], 0);
    const shares = nativeTotal > 0 ? allocate(converted, { ...native, 0: nativeTotal - assigned }) : Object.fromEntries([...ids, 0].map((id) => [id, 0]));
    delete shares[0];
    const fees = conv(c(fx.fees));
    const sorted = [...ids].sort((a, b) => a - b);
    const base = sorted.length ? Math.floor(fees / sorted.length) : 0;
    sorted.forEach((id, i) => { shares[id] += base + (i < fees - base * sorted.length ? 1 : 0); });
    return { shares: roundShares(shares, fx.rounding, fx.currency, payerId), total: converted + fees };
  }

  const parseDate = (s) => (s instanceof Date ? s : new Date(String(s).replace(' ', 'T')));

  function timeAgo(s) {
    const d = parseDate(s);
    const sec = Math.max(0, (Date.now() - d.getTime()) / 1000);
    if (sec < 60) return 'just now';
    if (sec < 3600) return Math.floor(sec / 60) + 'm ago';
    if (sec < 86400) return Math.floor(sec / 3600) + 'h ago';
    if (sec < 172800) return 'Yesterday';
    if (sec < 604800) return Math.floor(sec / 86400) + 'd ago';
    return fmtDate(d);
  }

  const fmtDate = (s, withYear) =>
    parseDate(s).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', ...(withYear ? { year: 'numeric' } : {}) });

  const fmtDateTime = (s) =>
    parseDate(s).toLocaleString('en-PH', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });

  const fmtMonth = (s) => parseDate(s).toLocaleDateString('en-PH', { month: 'long', year: 'numeric' }).toUpperCase();

  const STATUS_LABELS = {
    draft: 'Draft', active: 'Active', settling: 'Settling', closed: 'Closed',
    pending: 'Pending', awaiting: 'Awaiting Confirmation', settled: 'Settled', disputed: 'Disputed',
    suspended: 'Suspended',
  };

  // ---------- SweetAlert2 dialogs & toasts (loaded in partials/head.php) ----------
  const swal = (opts) => global.Swal.fire({
    confirmButtonColor: global.SetloTheme ? global.SetloTheme.color(600) : '#0d9488', cancelButtonColor: '#94a3b8', reverseButtons: true, buttonsStyling: true,
    customClass: { popup: 'setlo-swal' }, ...opts,
  });
  const Toast = global.Swal ? global.Swal.mixin({
    toast: true, position: 'top-end', showConfirmButton: false, timer: 2800, timerProgressBar: true,
    customClass: { popup: 'setlo-swal-toast' },
    didOpen: (el) => { el.addEventListener('mouseenter', global.Swal.stopTimer); el.addEventListener('mouseleave', global.Swal.resumeTimer); },
  }) : null;

  /** Small corner notification. tone: 'error' (default) | 'warn' | 'ok' | 'info'. */
  function toast(message, tone) {
    const icon = { error: 'error', warn: 'warning', ok: 'success', info: 'info' }[tone || 'error'] || 'error';
    if (Toast) Toast.fire({ icon, title: message });
    else console.warn(message);
  }

  /** Yes/No dialog. Resolves true when confirmed. */
  async function confirmDialog({ title, text = '', confirmText = 'Yes', cancelText = 'Cancel', danger = false, icon }) {
    const r = await swal({
      title, text, icon: icon || (danger ? 'warning' : 'question'), showCancelButton: true,
      confirmButtonText: confirmText, cancelButtonText: cancelText, confirmButtonColor: danger ? '#e11d48' : (global.SetloTheme ? global.SetloTheme.color(600) : '#0d9488'), focusCancel: danger,
    });
    return r.isConfirmed;
  }

  /** Message dialog. */
  function alertDialog(title, text = '', icon = 'info') {
    return swal({ title, text, icon, confirmButtonText: 'OK' });
  }

  /** Text input dialog; validate(value) returns an error message or ''. Resolves the text, or null if cancelled. */
  async function promptText({ title, text = '', placeholder = '', value = '', confirmText = 'Send', input = 'textarea', validate }) {
    const r = await swal({
      title, text, input, inputValue: value, inputPlaceholder: placeholder, showCancelButton: true, confirmButtonText: confirmText,
      inputValidator: (v) => (validate ? validate(v || '') || null : null),
    });
    return r.isConfirmed ? (r.value || '') : null;
  }

  /** Show a value the user should copy (e.g. a temporary password), with a Copy button. */
  async function showCopy(title, value, text = '') {
    const r = await swal({
      title, html: (text ? `<p class="mb-3 text-sm text-slate-500">${escapeHtml(text)}</p>` : '') +
        `<code class="block select-all break-all rounded-xl bg-slate-100 px-4 py-3 text-lg font-bold text-ink">${escapeHtml(value)}</code>`,
      icon: 'success', showCancelButton: true, confirmButtonText: 'Copy', cancelButtonText: 'Close',
    });
    if (r.isConfirmed) {
      try { await navigator.clipboard.writeText(value); toast('Copied to clipboard', 'ok'); } catch (e) { toast('Select the text and copy it manually.', 'warn'); }
    }
  }

  // ---------- Personal "Pay me" QR ----------
  /** Absolute link a Pay-me QR points to (works from any page in /pages/). */
  const payLink = (code) => new URL('pay.php?u=' + encodeURIComponent(code), location.href).href;

  /** SVG markup of a QR code for `text` (needs qrcode-generator, loaded in partials/head.php). */
  function qrSvg(text) {
    if (!global.qrcode) return '';
    const q = global.qrcode(0, 'M');
    q.addData(text);
    q.make();
    return q.createSvgTag({ cellSize: 6, margin: 2, scalable: true });
  }

  /** PNG data URL of a QR code with a caption underneath, for downloading. */
  function qrPng(text, caption = '') {
    const q = global.qrcode(0, 'M');
    q.addData(text);
    q.make();
    const n = q.getModuleCount();
    const cell = 12, pad = 36, size = n * cell + pad * 2, capH = caption ? 64 : 0;
    const c = document.createElement('canvas');
    c.width = size;
    c.height = size + capH;
    const ctx = c.getContext('2d');
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, c.width, c.height);
    ctx.fillStyle = '#0f172a';
    for (let r = 0; r < n; r++) for (let col = 0; col < n; col++) if (q.isDark(r, col)) ctx.fillRect(pad + col * cell, pad + r * cell, cell, cell);
    if (caption) {
      ctx.fillStyle = global.SetloTheme ? global.SetloTheme.color(700) : '#0f766e';
      ctx.font = '700 26px "Plus Jakarta Sans", system-ui, sans-serif';
      ctx.textAlign = 'center';
      ctx.fillText(caption, size / 2, size + 34);
    }
    return c.toDataURL('image/png');
  }

  /** Swal showing someone's Pay-me QR plus their payment details. person: { name, payment_method, payment_account, pay_code } */
  function showPayQr(person) {
    const acct = person.payment_account ? `${person.payment_method} · ${person.payment_account}` : person.payment_method;
    return swal({
      title: `Pay ${escapeHtml(person.name.split(' ')[0])}`,
      html: `<div class="mx-auto h-56 w-56 rounded-2xl border border-slate-200 bg-white p-2 keep-white">${qrSvg(payLink(person.pay_code))}</div>
             <p class="mt-3 text-[14px] font-bold text-slate-800">${escapeHtml(acct || '')}</p>
             <p class="mt-1 text-[12px] text-slate-500">Scan to open their Setlo pay page, then send the money in ${escapeHtml(person.payment_method)}.</p>`,
      showCancelButton: !!person.payment_account, confirmButtonText: 'Close', cancelButtonText: 'Copy number',
    }).then(async (r) => {
      if (r.dismiss === global.Swal.DismissReason.cancel && person.payment_account) {
        try { await navigator.clipboard.writeText(person.payment_account); toast('Copied', 'ok'); } catch (e) { showCopy('Copy the number', person.payment_account); }
      }
    });
  }

  /** Archive a closed bill for me only, or bring it back. Updates bill.archived; resolves true when it changed. */
  async function toggleArchive(bill) {
    const archive = !bill.archived;
    if (archive && !(await confirmDialog({
      title: 'Archive this bill?', confirmText: 'Archive', icon: 'question',
      text: 'It leaves your bill list and past settlements. Nothing is deleted — it stays under My Bills → Archived, and the others on the bill still see it.',
    }))) return false;
    try {
      bill.archived = (await api.post('bills.php', { action: archive ? 'archive' : 'unarchive', bill_id: bill.id })).archived;
    } catch (e) {
      toast(e.message);
      return false;
    }
    toast(archive ? 'Bill archived' : 'Bill is back in your list', 'ok');
    return true;
  }

  // ---------- Achievements ----------
  // Pop-ups wait their turn: several badges (or a badge while another dialog is open) show one after another.
  let achievementQueue = Promise.resolve();

  // Each badge card's backdrop behind its artwork: [centre, edge] of a radial gradient.
  const BADGE_COLORS = {
    itemalizer: ['#99f6e4', '#14b8a6'], kuripot: ['#fef08a', '#eab308'], glutton: ['#fed7aa', '#f97316'],
    one_two_three: ['#bfdbfe', '#3b82f6'], samaritan: ['#bbf7d0', '#22c55e'], split_personality: ['#e9d5ff', '#a855f7'],
    debt_collector: ['#a7f3d0', '#059669'], clean_slate: ['#cffafe', '#06b6d4'], human_calculator: ['#c7d2fe', '#6366f1'],
    bullseye: ['#fecdd3', '#f43f5e'], main_character: ['#fde68a', '#f59e0b'], receipt_from_hell: ['#fecaca', '#b91c1c'],
    debt_survivor: ['#e2e8f0', '#64748b'], big_spender: ['#d9f99d', '#65a30d'],
  };
  const reducedMotion = () => global.matchMedia && global.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /** Tilt a badge card toward the pointer or finger, with a shine that follows it. */
  function tiltCard(card) {
    if (reducedMotion()) return;
    const move = (e) => {
      const r = card.getBoundingClientRect();
      const x = Math.min(1, Math.max(0, (e.clientX - r.left) / r.width));
      const y = Math.min(1, Math.max(0, (e.clientY - r.top) / r.height));
      card.classList.add('tilting');
      card.style.transform = `perspective(900px) rotateX(${((0.5 - y) * 14).toFixed(2)}deg) rotateY(${((x - 0.5) * 18).toFixed(2)}deg)`;
      card.style.setProperty('--mx', (x * 100).toFixed(1) + '%');
      card.style.setProperty('--my', (y * 100).toFixed(1) + '%');
      card.style.setProperty('--shine', '1');
    };
    const rest = () => {
      card.classList.remove('tilting');
      card.style.transform = '';
      card.style.setProperty('--shine', '0');
    };
    card.addEventListener('pointermove', move);
    card.addEventListener('pointerdown', move);
    card.addEventListener('pointerleave', rest);
    card.addEventListener('pointerup', rest);
    card.addEventListener('pointercancel', rest);
  }

  /** A short burst of confetti from behind the card, in the badge's colours plus the brand's. */
  function confetti(colors) {
    if (reducedMotion()) return;
    const c = document.createElement('canvas');
    c.className = 'badge-confetti';
    const dpr = Math.min(2, global.devicePixelRatio || 1);
    const W = global.innerWidth, H = global.innerHeight;
    c.width = W * dpr; c.height = H * dpr;
    document.body.appendChild(c);
    const ctx = c.getContext('2d');
    ctx.scale(dpr, dpr);
    const palette = [...colors, '#14b8a6', '#facc15', '#f472b6', '#ffffff'];
    const parts = Array.from({ length: 140 }, () => {
      const angle = -Math.PI / 2 + (Math.random() - 0.5) * Math.PI * 1.1;
      const speed = 7 + Math.random() * 9;
      return {
        x: W / 2 + (Math.random() - 0.5) * 120, y: H * 0.45,
        vx: Math.cos(angle) * speed, vy: Math.sin(angle) * speed,
        w: 5 + Math.random() * 6, h: 8 + Math.random() * 8, r: Math.random() * Math.PI, vr: (Math.random() - 0.5) * 0.35,
        color: palette[Math.floor(Math.random() * palette.length)],
      };
    });
    const start = performance.now();
    const frame = (now) => {
      const t = now - start;
      ctx.clearRect(0, 0, W, H);
      ctx.globalAlpha = t > 1700 ? Math.max(0, 1 - (t - 1700) / 600) : 1;
      for (const p of parts) {
        p.vy += 0.32; p.vx *= 0.985; p.vy *= 0.985;
        p.x += p.vx; p.y += p.vy; p.r += p.vr;
        ctx.save();
        ctx.translate(p.x, p.y);
        ctx.rotate(p.r);
        ctx.fillStyle = p.color;
        ctx.fillRect(-p.w / 2, -p.h / 2, p.w, Math.abs(Math.cos(p.r * 2)) * p.h + 1); // flutters as it turns
        ctx.restore();
      }
      if (t < 2300) requestAnimationFrame(frame);
      else c.remove();
    };
    requestAnimationFrame(frame);
  }

  /**
   * The pop-up for one badge: { code, emoji, title, description, image, earned_at? }. A tall card: artwork on the
   * badge's colour with slowly turning rays, then the title. It flips in, tilts under the pointer or finger, and a
   * newly unlocked badge bursts confetti. Resolves when closed.
   */
  function achievementModal(a, { reopened = false } = {}) {
    const [c1, c2] = BADGE_COLORS[a.code] || ['#ccfbf1', '#0d9488'];
    return swal({
      html: `<div class="badge-flip"><div class="badge-card" style="--c1:${c1};--c2:${c2}">
          <div class="badge-art">
            <div class="badge-rays"></div>
            <span class="badge-tag">${reopened ? 'Achievement' : 'Unlocked!'}</span>
            ${a.image ? `<img src="${escapeHtml(a.image)}" alt="" draggable="false" />` : `<span class="badge-emoji">${escapeHtml(a.emoji)}</span>`}
          </div>
          <div class="badge-body">
            <p class="text-[21px] font-extrabold leading-tight text-ink">${escapeHtml(a.emoji)} ${escapeHtml(a.title)}</p>
            <p class="mt-1.5 text-[13.5px] leading-snug text-slate-500">${escapeHtml(a.description)}</p>
            ${a.earned_at ? `<p class="mt-2.5 text-[11.5px] font-semibold text-slate-400">Unlocked ${escapeHtml(fmtDate(a.earned_at, true))}</p>` : ''}
          </div>
          <div class="badge-shine"></div>
        </div></div>`,
      customClass: { popup: 'setlo-swal badge-popup' },
      showClass: { popup: '' }, // the card's own flip-in replaces SweetAlert's zoom
      confirmButtonText: reopened ? 'Close' : 'Nice!',
      // Only the button closes a new badge, so it can't be dismissed by an accidental tap outside.
      allowOutsideClick: reopened, allowEscapeKey: reopened,
      didOpen: (el) => {
        tiltCard(el.querySelector('.badge-card'));
        if (!reopened) confetti([c1, c2]);
      },
    });
  }

  /** Show newly earned badges, one pop-up at a time. */
  function showAchievements(list) {
    for (const a of list || []) achievementQueue = achievementQueue.then(() => achievementModal(a)).catch(() => {});
    return achievementQueue;
  }

  /** The featured title next to someone's name: { emoji, title }. */
  const StatusBadge = {
    props: { badge: Object },
    template: `<span v-if="badge" class="status-badge" :title="'Title: ' + badge.title">{{ badge.emoji }} {{ badge.title }}</span>`,
  };

  /**
   * One person in a member search (api/users.php): picture, name with their featured achievement tag, and email
   * underneath, so people with the same name can be told apart. Sits inside the result's flex button.
   */
  const PersonResult = {
    props: { user: { type: Object, required: true }, size: { type: Number, default: 30 } },
    template: `<avatar :user="user" :size="size"></avatar>
      <span class="min-w-0 flex-1">
        <span class="flex min-w-0 items-center gap-1.5">
          <span class="truncate text-[13px] font-bold text-ink">{{ user.name }}</span>
          <status-badge :badge="user.badge" class="max-w-[55%] shrink-0"></status-badge>
        </span>
        <span v-if="user.email" class="block truncate text-[11.5px] text-slate-400">{{ user.email }}</span>
      </span>`,
  };

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }

  // ---------- Shared components ----------
  /** A person's picture (user.photo) or, without one or if it fails to load, their initials on their colour. */
  const Avatar = {
    props: { user: { type: Object, required: true }, size: { type: Number, default: 36 }, ring: { type: Boolean, default: false } },
    data: () => ({ broken: null }),
    template: `<img v-if="user.photo && broken !== user.photo" :src="user.photo" @error="broken = user.photo" alt="" class="av object-cover"
        :style="{ width: size + 'px', height: size + 'px', boxShadow: ring ? undefined : 'none' }" :title="user.name" />
      <span v-else class="av" :style="{ background: user.color, width: size + 'px', height: size + 'px', fontSize: size * 0.36 + 'px', boxShadow: ring ? undefined : 'none' }" :title="user.name">{{ user.initials }}</span>`,
  };

  /** Shrink a photo to at most maxSide px and re-save it as JPEG, so several fit in one upload. Returns the original if it can't. */
  async function compressImage(file, maxSide = 1600, quality = 0.85) {
    try {
      if (!file.type.startsWith('image/') || (file.size < 900 * 1024 && file.type === 'image/jpeg')) return file;
      const bmp = await createImageBitmap(file, { imageOrientation: 'from-image' });
      const scale = Math.min(1, maxSide / Math.max(bmp.width, bmp.height));
      const canvas = document.createElement('canvas');
      canvas.width = Math.round(bmp.width * scale);
      canvas.height = Math.round(bmp.height * scale);
      canvas.getContext('2d').drawImage(bmp, 0, 0, canvas.width, canvas.height);
      if (bmp.close) bmp.close();
      const blob = await new Promise((r) => canvas.toBlob(r, 'image/jpeg', quality));
      if (!blob || blob.size >= file.size) return file;
      return new File([blob], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' });
    } catch (e) { return file; }
  }
  /**
   * Full-screen photo viewer for one or several images: counter and Close button on top, big ‹ › buttons on the screen
   * edges, a thumbnail strip, and ← / → / Esc, swipe, or tapping the photo to move on. srcs: array of URLs.
   */
  function photoViewer(srcs, { title = 'Photo', start = 0 } = {}) {
    srcs = [].concat(srcs).filter(Boolean);
    if (!srcs.length) return;
    let i = Math.min(Math.max(0, start), srcs.length - 1);
    const many = srcs.length > 1;
    const el = (tag, css, props) => Object.assign(document.createElement(tag), props || {}, css ? { style: css } : {});
    const round = 'width:46px;height:46px;border-radius:50%;border:0;background:rgba(255,255,255,.92);color:#334155;font-size:26px;line-height:44px;cursor:pointer;box-shadow:0 4px 14px rgba(0,0,0,.35);';

    const root = el('div', 'position:fixed;inset:0;z-index:9999;display:flex;flex-direction:column;background:rgba(15,23,42,.92);font-family:inherit;', { role: 'dialog' });
    root.setAttribute('aria-modal', 'true');
    root.setAttribute('aria-label', title);
    const bar = el('div', 'display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 16px;color:#fff;');
    const heading = el('div', 'font-size:14px;font-weight:700;');
    const closeBtn = el('button', 'border:0;border-radius:999px;background:rgba(255,255,255,.18);color:#fff;font-weight:700;font-size:13px;padding:8px 14px;cursor:pointer;', { type: 'button', textContent: '✕ Close' });
    bar.append(heading, closeBtn);
    const stage = el('div', 'flex:1;min-height:0;display:flex;align-items:center;justify-content:center;padding:0 64px;');
    const img = el('img', 'max-width:100%;max-height:100%;object-fit:contain;border-radius:16px;background:#fff;box-shadow:0 20px 50px rgba(0,0,0,.5);user-select:none;-webkit-user-drag:none;', { alt: title, draggable: false });
    stage.appendChild(img);
    const thumbs = el('div', 'display:flex;justify-content:center;gap:8px;padding:10px 16px 18px;overflow-x:auto;');
    root.append(bar, stage);

    const prev = many ? el('button', round + 'position:absolute;left:10px;top:50%;transform:translateY(-50%);', { type: 'button', textContent: '‹', ariaLabel: 'Previous photo' }) : null;
    const next = many ? el('button', round + 'position:absolute;right:10px;top:50%;transform:translateY(-50%);', { type: 'button', textContent: '›', ariaLabel: 'Next photo' }) : null;
    if (many) {
      root.append(prev, next, thumbs);
      srcs.forEach((src, n) => {
        const b = el('button', 'flex:0 0 auto;padding:0;border:2px solid transparent;border-radius:10px;background:none;cursor:pointer;overflow:hidden;', { type: 'button', ariaLabel: 'Photo ' + (n + 1) });
        b.appendChild(el('img', 'width:56px;height:56px;object-fit:cover;display:block;', { src, alt: '', draggable: false }));
        b.onclick = (e) => { e.stopPropagation(); i = n; show(); };
        thumbs.appendChild(b);
      });
    }

    function show() {
      img.src = srcs[i];
      heading.textContent = title + (many ? '  ·  ' + (i + 1) + ' / ' + srcs.length : '');
      [...thumbs.children].forEach((b, n) => { b.style.borderColor = n === i ? '#fff' : 'transparent'; b.style.opacity = n === i ? '1' : '.6'; });
    }
    const step = (d) => { if (many) { i = (i + d + srcs.length) % srcs.length; show(); } };
    const close = () => { document.removeEventListener('keydown', onKey, true); root.remove(); if (opener && opener.focus) opener.focus(); };
    const onKey = (e) => {
      if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(); }
      else if (e.key === 'ArrowRight') { e.preventDefault(); step(1); }
      else if (e.key === 'ArrowLeft') { e.preventDefault(); step(-1); }
    };
    const opener = document.activeElement;

    closeBtn.onclick = (e) => { e.stopPropagation(); close(); };
    if (many) {
      prev.onclick = (e) => { e.stopPropagation(); step(-1); };
      next.onclick = (e) => { e.stopPropagation(); step(1); };
    }
    img.onclick = (e) => { e.stopPropagation(); step(1); };
    root.onclick = close; // the dark area closes it
    let downX = null;
    root.addEventListener('pointerdown', (e) => { downX = e.clientX; });
    root.addEventListener('pointerup', (e) => {
      if (downX !== null && Math.abs(e.clientX - downX) > 50) { step(e.clientX < downX ? 1 : -1); root.dataset.swiped = '1'; }
      downX = null;
    });
    // a swipe ends on the backdrop too; don't let that count as a tap that closes it
    root.addEventListener('click', (e) => { if (root.dataset.swiped) { e.stopImmediatePropagation(); delete root.dataset.swiped; } }, true);

    document.addEventListener('keydown', onKey, true);
    document.body.appendChild(root);
    show();
    closeBtn.focus();
  }


  /** No live camera available (plain http, no permission): the phone's own camera app via a file input. */
  function pickFromDevice(max) {
    return new Promise((resolve) => {
      const input = document.createElement('input');
      input.type = 'file';
      input.accept = 'image/*';
      input.multiple = max > 1;
      input.setAttribute('capture', 'environment');
      input.addEventListener('change', () => resolve([...input.files].slice(0, max)));
      input.addEventListener('cancel', () => resolve([]));
      input.click();
    });
  }

  let camCssAdded = false;
  function addCameraCss() {
    if (camCssAdded) return;
    camCssAdded = true;
    const st = document.createElement('style');
    st.textContent = `
      .cam-popup { padding: 1.1rem 1rem 1rem !important; }
      .cam-video { width: 100%; max-height: 52vh; border-radius: 16px; background: #0f172a; object-fit: cover; display: block; }
      .cam-bar { display: flex; align-items: center; justify-content: center; gap: 14px; margin-top: 12px; }
      .cam-shot { width: 64px; height: 64px; border-radius: 50%; border: 4px solid #fff; outline: 3px solid var(--cam-accent, #0d9488); background: var(--cam-accent, #0d9488); cursor: pointer; transition: transform .1s; }
      .cam-shot:active { transform: scale(.92); }
      .cam-shot:disabled { opacity: .4; cursor: not-allowed; }
      .cam-flip { border: 1px solid #cbd5e1; background: #fff; color: #334155; border-radius: 999px; padding: 8px 12px; font: 600 13px/1 inherit; cursor: pointer; }
      .cam-strip { display: flex; gap: 8px; margin-top: 12px; overflow-x: auto; min-height: 58px; padding-bottom: 2px; }
      .cam-thumb { position: relative; flex: 0 0 auto; }
      .cam-thumb img { width: 58px; height: 58px; border-radius: 10px; object-fit: cover; display: block; }
      .cam-thumb button { position: absolute; top: -6px; right: -6px; width: 20px; height: 20px; border-radius: 50%; border: 0; background: #0f172a; color: #fff; font-size: 13px; line-height: 20px; cursor: pointer; }
      .cam-hint { margin: 8px 0 0; font-size: 12px; color: #64748b; }
      @media (prefers-reduced-motion: reduce) { .cam-shot { transition: none; } }`;
    document.head.appendChild(st);
  }

  /**
   * Live camera that stays open between shots: tap the shutter as many times as needed (up to max), remove any
   * thumbnail, then Done. Resolves an array of JPEG Files ([] if cancelled). Without camera access it falls back to
   * the device's camera app / file picker.
   */
  async function captureCamera({ max = 5, title = 'Take photos' } = {}) {
    if (!(global.navigator.mediaDevices && global.navigator.mediaDevices.getUserMedia)) {
      toast('Live camera needs a secure (https) connection, so your device camera app opens instead.', 'info');
      return pickFromDevice(max);
    }
    addCameraCss();
    const shots = [];
    let stream = null;
    let facing = 'environment';
    let failure = null;
    const stop = () => { if (stream) { stream.getTracks().forEach((t) => t.stop()); stream = null; } };
    global.addEventListener('pagehide', stop);

    const result = await swal({
      title, showCancelButton: true, confirmButtonText: 'Done', cancelButtonText: 'Cancel',
      allowOutsideClick: false, customClass: { popup: 'setlo-swal cam-popup' }, showClass: { popup: '' },
      html: '<video class="cam-video" autoplay playsinline muted></video>'
        + '<div class="cam-bar"><button type="button" class="cam-flip" data-flip>Flip camera</button>'
        + '<button type="button" class="cam-shot" data-shot aria-label="Take photo"></button></div>'
        + '<div class="cam-strip" data-strip></div><p class="cam-hint" data-hint></p>',
      didOpen: async (el) => {
        const video = el.querySelector('video');
        const strip = el.querySelector('[data-strip]');
        const shotBtn = el.querySelector('[data-shot]');
        const hint = el.querySelector('[data-hint]');
        el.style.setProperty('--cam-accent', global.SetloTheme ? global.SetloTheme.color(600) : '#0d9488');
        const refresh = () => {
          strip.innerHTML = '';
          shots.forEach((s, i) => {
            const t = document.createElement('div');
            t.className = 'cam-thumb';
            t.innerHTML = '<img alt="Photo ' + (i + 1) + '" /><button type="button" aria-label="Remove photo ' + (i + 1) + '">×</button>';
            t.querySelector('img').src = s.url;
            t.querySelector('button').onclick = () => { URL.revokeObjectURL(s.url); shots.splice(i, 1); refresh(); };
            strip.appendChild(t);
          });
          shotBtn.disabled = shots.length >= max;
          hint.textContent = shots.length >= max ? 'Maximum of ' + max + ' photos reached.' : shots.length + ' of ' + max + ' taken. Tap the round button to take a photo.';
          const ok = global.Swal.getConfirmButton();
          if (ok) ok.textContent = shots.length ? 'Done (' + shots.length + ')' : 'Done';
        };
        const open = async () => {
          stop();
          try {
            stream = await global.navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: facing }, width: { ideal: 1920 }, height: { ideal: 1080 } }, audio: false });
            video.srcObject = stream;
          } catch (e) { failure = e; global.Swal.close(); }
        };
        el.querySelector('[data-flip]').onclick = () => { facing = facing === 'environment' ? 'user' : 'environment'; open(); };
        shotBtn.onclick = () => {
          if (!video.videoWidth || shots.length >= max) return;
          const scale = Math.min(1, 1600 / Math.max(video.videoWidth, video.videoHeight));
          const canvas = document.createElement('canvas');
          canvas.width = Math.round(video.videoWidth * scale);
          canvas.height = Math.round(video.videoHeight * scale);
          canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
          canvas.toBlob((blob) => {
            if (!blob) return;
            const file = new File([blob], 'proof-' + Date.now() + '-' + (shots.length + 1) + '.jpg', { type: 'image/jpeg' });
            shots.push({ file, url: URL.createObjectURL(file) });
            refresh();
          }, 'image/jpeg', 0.88);
        };
        refresh();
        await open();
      },
      preConfirm: () => {
        if (!shots.length) { global.Swal.showValidationMessage('Take at least one photo, or Cancel.'); return false; }
        return true;
      },
      willClose: () => { stop(); global.removeEventListener('pagehide', stop); },
    });

    const files = result.isConfirmed ? shots.map((s) => s.file) : [];
    shots.forEach((s) => URL.revokeObjectURL(s.url));
    if (failure && !files.length) {
      toast(failure.name === 'NotAllowedError' ? 'Camera access was blocked. Allow it in your browser, or pick photos instead.' : 'Couldn’t start the camera, so your device camera app opens instead.', 'warn');
      return pickFromDevice(max);
    }
    return files;
  }

  /**
   * Let the user frame a gallery photo before it's uploaded: drag to move; pinch, scroll or the slider to zoom; arrow
   * keys and +/- too. shape 'circle' (profile pictures: a round guide, saved square) or 'rect' (the whole stage, at
   * aspect = width / height). Resolves a JPEG blob $width px wide, or null if cancelled.
   */
  function cropPhoto(file, { shape = 'rect', aspect = 1, width = 1200, title = 'Crop your photo' } = {}) {
    return new Promise((resolve, reject) => {
      const img = new Image();
      const src = URL.createObjectURL(file);
      img.onerror = () => { URL.revokeObjectURL(src); reject(new Error('That file isn’t a photo we can open. Try a JPG or PNG.')); };
      img.onload = async () => {
        let crop;
        const icon = (d) => `<svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" d="${d}"/></svg>`;
        const r = await swal({
          title,
          html: `<div class="crop-stage" tabindex="0" aria-label="Photo framing: drag or use the arrow keys to move, plus and minus to zoom" style="aspect-ratio:${shape === 'circle' ? 1 : aspect}">
              <img src="${src}" alt="" draggable="false" /><div class="crop-frame crop-${shape}"></div>
            </div>
            <div class="crop-zoom">${icon('M5 12h14')}<input type="range" min="0" max="1" step="0.001" value="0" aria-label="Zoom" />${icon('M12 5v14M5 12h14')}</div>
            <p class="crop-hint">Drag to move · pinch, scroll or slide to zoom</p>`,
          customClass: { popup: 'setlo-swal crop-popup' },
          showCancelButton: true, confirmButtonText: 'Use photo',
          didOpen: (el) => { crop = cropper(el, img, shape); },
          preConfirm: () => crop.toBlob(width).catch((e) => { global.Swal.showValidationMessage(e.message); return false; }),
        });
        URL.revokeObjectURL(src);
        resolve(r.isConfirmed ? r.value : null);
      };
      img.src = src;
    });
  }

  /** The moving parts of cropPhoto's dialog. Returns { toBlob(width) } for the framed part. */
  function cropper(el, img, shape) {
    const stage = el.querySelector('.crop-stage');
    const pic = stage.querySelector('img');
    const range = el.querySelector('.crop-zoom input');
    const W = stage.clientWidth, H = stage.clientHeight;
    const pad = shape === 'circle' ? W * 0.08 : 0; // matches .crop-circle's inset
    const f = { x: pad, y: pad, w: W - 2 * pad, h: H - 2 * pad }; // the frame, in stage pixels
    const iw = img.naturalWidth, ih = img.naturalHeight;
    const min = Math.max(f.w / iw, f.h / ih), max = min * 5; // the photo always covers the frame
    let s = min, x = f.x + (f.w - iw * s) / 2, y = f.y + (f.h - ih * s) / 2;

    pic.style.width = iw + 'px';
    pic.style.height = ih + 'px';
    const draw = () => {
      x = Math.min(f.x, Math.max(f.x + f.w - iw * s, x));
      y = Math.min(f.y, Math.max(f.y + f.h - ih * s, y));
      pic.style.transform = `translate(${x}px, ${y}px) scale(${s})`;
      range.value = Math.log(s / min) / Math.log(max / min);
    };
    /** Zoom to scale $ns, keeping the stage point (cx, cy) still. */
    const zoomTo = (ns, cx = f.x + f.w / 2, cy = f.y + f.h / 2) => {
      ns = Math.min(max, Math.max(min, ns));
      x = cx - (cx - x) * ns / s;
      y = cy - (cy - y) * ns / s;
      s = ns;
      draw();
    };
    const local = (e) => { const b = stage.getBoundingClientRect(); return { x: (e.clientX - b.left) * W / b.width, y: (e.clientY - b.top) * H / b.height }; };

    // One finger or the mouse moves the photo; two fingers pinch-zoom around their midpoint (and move it).
    const pts = new Map();
    stage.addEventListener('pointerdown', (e) => {
      stage.setPointerCapture(e.pointerId);
      pts.set(e.pointerId, local(e));
      stage.classList.add('dragging');
    });
    stage.addEventListener('pointermove', (e) => {
      if (!pts.has(e.pointerId)) return;
      const before = [...pts.values()];
      pts.set(e.pointerId, local(e));
      const after = [...pts.values()];
      if (after.length === 1) {
        x += after[0].x - before[0].x;
        y += after[0].y - before[0].y;
        draw();
      } else if (after.length === 2) {
        const mid = (p) => ({ x: (p[0].x + p[1].x) / 2, y: (p[0].y + p[1].y) / 2 });
        const dist = (p) => Math.hypot(p[0].x - p[1].x, p[0].y - p[1].y) || 1;
        const m0 = mid(before), m1 = mid(after);
        x += m1.x - m0.x;
        y += m1.y - m0.y;
        zoomTo(s * dist(after) / dist(before), m1.x, m1.y);
      }
    });
    const lift = (e) => { pts.delete(e.pointerId); if (!pts.size) stage.classList.remove('dragging'); };
    stage.addEventListener('pointerup', lift);
    stage.addEventListener('pointercancel', lift);
    stage.addEventListener('wheel', (e) => { e.preventDefault(); const p = local(e); zoomTo(s * Math.exp(-e.deltaY * 0.0015), p.x, p.y); }, { passive: false });
    stage.addEventListener('keydown', (e) => {
      const step = { ArrowLeft: [12, 0], ArrowRight: [-12, 0], ArrowUp: [0, 12], ArrowDown: [0, -12] }[e.key];
      if (step) { x += step[0]; y += step[1]; draw(); }
      else if (e.key === '+' || e.key === '=') zoomTo(s * 1.1);
      else if (e.key === '-') zoomTo(s / 1.1);
      else return;
      e.preventDefault();
    });
    range.addEventListener('input', () => zoomTo(min * Math.pow(max / min, +range.value)));
    draw();

    return {
      toBlob(width) {
        const c = document.createElement('canvas');
        c.width = Math.round(Math.min(width, f.w / s)) || width; // never enlarged past the photo's own pixels
        c.height = Math.round(c.width * f.h / f.w);
        const ctx = c.getContext('2d');
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, c.width, c.height);
        ctx.imageSmoothingQuality = 'high';
        ctx.drawImage(img, (f.x - x) / s, (f.y - y) / s, f.w / s, f.h / s, 0, 0, c.width, c.height);
        return new Promise((ok, fail) => c.toBlob((b) => (b ? ok(b) : fail(new Error('Could not read that photo.'))), 'image/jpeg', 0.88));
      },
    };
  }

  /**
   * Profile picture picker: the preset pictures (assets/pfp), a photo from the gallery, or initials. Saves on tap.
   * props: profile (from api/profile.php: avatar, photo, initials, color…), presets ([{ key, url }]). Emits saved(profile).
   */
  const AvatarPicker = {
    components: { Avatar },
    props: { profile: { type: Object, required: true }, presets: { type: Array, default: () => [] } },
    emits: ['saved'],
    data: () => ({ busy: null }),
    computed: {
      uploaded() { return (this.profile.avatar || '').startsWith('up/'); },
      initialsUser() { return { ...this.profile, photo: null }; },
    },
    methods: {
      async save(key) {
        if (this.busy || key === (this.profile.avatar || '')) return;
        this.busy = key || 'initials';
        try { this.$emit('saved', (await api.post('profile.php', { action: 'set_avatar', key })).profile); }
        catch (e) { toast(e.message); }
        finally { this.busy = null; }
      },
      async pick(e) {
        const file = e.target.files[0];
        e.target.value = '';
        if (!file) return;
        if (!file.type.startsWith('image/')) { toast('Choose a photo.'); return; }
        this.busy = 'crop';
        try {
          const photo = await cropPhoto(file, { shape: 'circle', width: 384, title: 'Crop your picture' });
          if (!photo) return;
          this.busy = 'upload';
          const fd = new FormData();
          fd.append('action', 'upload_avatar');
          fd.append('photo', photo, 'avatar.jpg');
          this.$emit('saved', (await api.post('profile.php', fd)).profile);
        } catch (err) { toast(err.message); }
        finally { this.busy = null; }
      },
    },
    template: `<div>
      <input ref="file" type="file" accept="image/*" class="hidden" @change="pick" aria-label="Choose a photo from your gallery" />
      <button type="button" @click="$refs.file.click()" :disabled="!!busy"
        class="flex w-full items-center gap-3 rounded-2xl border-[1.5px] p-3 text-left transition"
        :class="uploaded ? 'border-brand-500 bg-brand-50/60' : 'border-dashed border-slate-300 bg-white hover:border-brand-400'">
        <img v-if="uploaded" :src="profile.photo" alt="" class="h-12 w-12 shrink-0 rounded-full object-cover" />
        <span v-else class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-brand-50 text-brand-600">
          <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.6-4.6a2 2 0 012.8 0L16 16m-2-2l1.6-1.6a2 2 0 012.8 0L20 14M14 8h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
        </span>
        <span class="min-w-0 flex-1">
          <span class="block text-[14px] font-bold text-ink">{{ busy === 'upload' ? 'Uploading…' : uploaded ? 'Your photo' : 'Choose from gallery' }}</span>
          <span class="block text-[12px] text-slate-500">{{ uploaded ? 'Tap to pick a different one' : 'Use a photo from your phone' }}</span>
        </span>
        <span v-if="uploaded" class="text-[12px] font-bold text-brand-700">✓</span>
      </button>

      <p class="mb-2 mt-4 text-[12px] font-bold text-slate-500">Or pick one</p>
      <div class="grid grid-cols-4 gap-3 sm:grid-cols-6">
        <button v-for="p in presets" :key="p.key" type="button" @click="save(p.key)" :disabled="!!busy" :aria-label="'Use picture ' + p.key.slice(4)"
          :aria-pressed="profile.avatar === p.key"
          class="relative aspect-square rounded-full p-1 transition hover:scale-105"
          :class="profile.avatar === p.key ? 'ring-[3px] ring-brand-500' : 'ring-1 ring-transparent'">
          <img :src="p.url" alt="" class="h-full w-full" loading="lazy" :class="{ 'opacity-50': busy === p.key }" />
          <span v-if="profile.avatar === p.key" class="absolute -right-0.5 -top-0.5 flex h-5 w-5 items-center justify-center rounded-full bg-brand-600 text-[11px] font-extrabold text-white ring-2 ring-white">✓</span>
        </button>
        <button type="button" @click="save('')" :disabled="!!busy" aria-label="Use my initials" :aria-pressed="!profile.avatar"
          class="relative flex aspect-square items-center justify-center rounded-full p-1 transition hover:scale-105"
          :class="!profile.avatar ? 'ring-[3px] ring-brand-500' : 'ring-1 ring-transparent'">
          <span class="av h-full w-full text-[15px]" :style="{ background: profile.color, boxShadow: 'none' }">{{ profile.initials }}</span>
          <span v-if="!profile.avatar" class="absolute -right-0.5 -top-0.5 flex h-5 w-5 items-center justify-center rounded-full bg-brand-600 text-[11px] font-extrabold text-white ring-2 ring-white">✓</span>
        </button>
      </div>
      <p class="mt-2 text-[11.5px] text-slate-400">The last circle keeps your initials.</p>
    </div>`,
  };

  /**
   * Installment interest: one of the fixed rates (INSTALLMENT_RATES in includes/bootstrap.php — two low, one moderate).
   * v-model is the chosen rate (a number). props: rates [{ rate, label, hint }].
   */
  const RateChoice = {
    props: { rates: { type: Array, required: true }, modelValue: Number },
    emits: ['update:modelValue'],
    template: `<div class="grid grid-cols-3 gap-2" role="radiogroup" aria-label="Interest after each partial payment">
      <button v-for="r in rates" :key="r.rate" type="button" role="radio" :aria-checked="modelValue === r.rate" @click="$emit('update:modelValue', r.rate)"
        class="rounded-2xl border-[1.5px] px-2 py-2.5 text-center transition"
        :class="modelValue === r.rate ? (r.rate >= 5 ? 'border-amber-400 bg-amber-50' : 'border-brand-500 bg-brand-50') : 'border-slate-200 bg-white hover:border-slate-300'">
        <span class="block text-[18px] font-extrabold leading-tight" :class="r.rate >= 5 ? 'text-amber-700' : 'text-brand-700'">{{ r.rate }}%</span>
        <span class="block text-[12px] font-bold text-ink">{{ r.label }}</span>
        <span class="mt-0.5 block text-[10.5px] leading-tight text-slate-400">{{ r.hint }}</span>
      </button>
    </div>`,
  };

  /**
   * Currency dropdown (v-model: a code). Closed, it shows the symbol (bold, in the accent colour) and the code, e.g.
   * "¥ JPY" (plus the name in grey unless `compact`); open, it's the browser's own plain list with every currency in
   * full. A transparent native <select> sits over the label, so the list, keyboard and phone pickers are the default ones.
   * props: modelValue, label (aria), disabled, compact.
   */
  const CurrencySelect = {
    props: { modelValue: String, label: { type: String, default: 'Currency' }, disabled: Boolean, compact: Boolean },
    emits: ['update:modelValue'],
    computed: {
      current() { return cinfo(this.modelValue || 'PHP'); },
      groups() {
        const all = Object.values(CURRENCIES);
        const top = ['PHP', 'USD', 'JPY', 'KRW', 'SGD', 'HKD', 'EUR'].map((c) => CURRENCIES[c]).filter(Boolean);
        return [{ label: 'Common', list: top }, { label: 'All currencies', list: all.filter((c) => !top.includes(c)).sort((a, b) => a.name.localeCompare(b.name)) }];
      },
    },
    template: `<label class="cur-select" :class="{ 'cur-compact': compact, 'cur-disabled': disabled }">
      <span class="cur-sym">{{ current.symbol }}</span>
      <span class="cur-code">{{ current.code }}</span>
      <span v-if="!compact" class="cur-name">{{ current.name }}</span>
      <svg class="cur-chev" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6"/></svg>
      <select :value="modelValue" @change="$emit('update:modelValue', $event.target.value)" :disabled="disabled" :aria-label="label" class="cur-native">
        <optgroup v-for="g in groups" :key="g.label" :label="g.label">
          <option v-for="c in g.list" :key="c.code" :value="c.code">{{ c.symbol }} {{ c.code }} — {{ c.name }}</option>
        </optgroup>
      </select>
    </label>`,
  };

  /** Where to go after Google or email sign-in: a brand-new account picks a profile picture first. */
  const afterSignUp = (r, next) => (r.new ? 'choose-photo' + (next ? '?next=' + encodeURIComponent(next) : '') : next || r.redirect);

  const StatusPill = {
    props: { status: String, label: String },
    template: `<span class="pill" :class="'pill-' + status">{{ label || labels[status] || status }}</span>`,
    data: () => ({ labels: STATUS_LABELS }),
  };

  const Spinner = {
    // Fades in after 300 ms, so a quick load never flashes a spinner
    template: `<div class="spinner-delay flex justify-center py-10"><span class="h-7 w-7 animate-spin rounded-full border-[3px] border-brand-200 border-t-brand-600"></span></div>`,
  };

  const NOTIF_ICONS = {
    paid: ['bg-sky-100 text-sky-600', 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z'],
    confirmed: ['bg-emerald-100 text-emerald-700', 'M5 13l4 4L19 7'],
    disputed: ['bg-rose-100 text-rose-600', 'M12 9v3.75m0 3.75h.008M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
    resent: ['bg-amber-100 text-amber-600', 'M4 4v5h5M20 20v-5h-5M5.1 15a7 7 0 0011.8 2.5M18.9 9A7 7 0 007.1 6.5'],
    added: ['bg-brand-100 text-brand-700', 'M12 4v16m8-8H4'],
    settling: ['bg-yellow-100 text-yellow-700', 'M12 8v4l3 2m6-2a9 9 0 11-18 0 9 9 0 0118 0z'],
    closed: ['bg-slate-100 text-slate-600', 'M5 13l4 4L19 7'],
    admin: ['bg-slate-800 text-white', 'M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
    nudge: ['bg-amber-100 text-amber-600', 'M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 10-12 0v3.2a2 2 0 01-.6 1.4L4 17h5'],
    game: ['bg-fuchsia-100 text-fuchsia-600', 'M14.8 9.2a2.5 2.5 0 00-3.6 0M9 10h.01M15 10h.01M8.5 14.5a5 5 0 007 0M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
    achievement: ['bg-violet-100 text-violet-600', 'M8 21h8m-4-4v4m-5-17h10v5a5 5 0 01-10 0V4zM7 6H4v1a3 3 0 003 3m10-4h3v1a3 3 0 01-3 3'],
  };

  const NotifIcon = {
    props: { type: String, size: { type: Number, default: 32 } },
    computed: { icon() { return NOTIF_ICONS[this.type] || NOTIF_ICONS.admin; } },
    template: `<div class="flex shrink-0 items-center justify-center rounded-full" :class="icon[0]" :style="{ width: size + 'px', height: size + 'px' }">
      <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" :d="icon[1]"/></svg>
    </div>`,
  };

  /** Bell button + dropdown, polls every 30 s. */
  const NotifBell = {
    components: { NotifIcon },
    data: () => ({ open: false, items: [], unread: 0, timer: null }),
    mounted() {
      this.load();
      this.timer = setInterval(this.load, 30000);
      document.addEventListener('click', this.outside);
    },
    unmounted() {
      clearInterval(this.timer);
      document.removeEventListener('click', this.outside);
    },
    methods: {
      async load() {
        try {
          const r = await api.get('notifications.php', { limit: 6 });
          this.items = r.notifications;
          this.unread = r.unread;
        } catch (e) { /* bell is non-critical */ }
      },
      outside(e) { if (!this.$el.contains(e.target)) this.open = false; },
      async go(n) {
        if (!n.is_read) api.post('notifications.php', { action: 'read', id: n.id }).catch(() => {});
        location.href = n.link || 'notifications';
      },
      timeAgo,
    },
    template: `<div class="relative">
      <button @click="open = !open" class="glass-btn" aria-label="Notifications" :aria-expanded="open">
        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 10-12 0v3.2a2 2 0 01-.6 1.4L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
        <span v-if="unread" class="absolute -right-0.5 -top-0.5 flex h-[18px] min-w-[18px] items-center justify-center rounded-full bg-amber-400 px-1 text-[10px] font-extrabold text-white ring-2 ring-brand-500">{{ unread > 9 ? '9+' : unread }}</span>
      </button>
      <div v-if="open" class="hero-pop tile absolute right-0 top-12 z-40 w-80 max-w-[88vw] overflow-hidden text-slate-800 shadow-xl">
        <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
          <span class="text-sm font-bold text-ink">Notifications</span>
          <a href="notifications" class="text-xs font-bold text-brand-700">See all</a>
        </div>
        <div class="max-h-80 divide-y divide-slate-100 overflow-y-auto">
          <p v-if="!items.length" class="px-4 py-6 text-center text-[13px] text-slate-400">You're all caught up.</p>
          <button v-for="n in items" :key="n.id" @click="go(n)" class="flex w-full gap-3 px-4 py-3 text-left hover:bg-slate-50" :class="{ 'bg-brand-50/50': !n.is_read }">
            <notif-icon :type="n.type"></notif-icon>
            <div class="min-w-0">
              <p class="text-[13px] text-slate-700">{{ n.message }}</p>
              <p class="mt-0.5 text-[11px] text-slate-400">{{ timeAgo(n.created_at) }}</p>
            </div>
          </button>
        </div>
      </div>
    </div>`,
  };

  /** "Share summary" button + preview sheet. Needs assets/js/summary.js on the page. */
  const ShareSummary = {
    props: { d: { type: Object, required: true } },
    data: () => ({ open: false, url: null, blob: null, busy: false, canShareFiles: false, copied: false }),
    methods: {
      async show() {
        this.open = true;
        this.busy = true;
        try {
          const data = SetloSummary.fromBill(this.d);
          const canvas = await SetloSummary.render(data);
          this.blob = await new Promise((r) => canvas.toBlob(r, 'image/png'));
          if (this.url) URL.revokeObjectURL(this.url);
          this.url = URL.createObjectURL(this.blob);
          const file = new File([this.blob], this.fileName(), { type: 'image/png' });
          this.canShareFiles = !!(navigator.canShare && navigator.canShare({ files: [file] }));
        } catch (e) {
          toast('Could not create the summary image.');
          this.open = false;
        } finally {
          this.busy = false;
        }
      },
      fileName() { return 'setlo-' + this.d.bill.name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') + '.png'; },
      async share() {
        const file = new File([this.blob], this.fileName(), { type: 'image/png' });
        try { await navigator.share({ files: [file], title: this.d.bill.name, text: SetloSummary.asText(SetloSummary.fromBill(this.d)) }); } catch (e) { /* cancelled */ }
      },
      download() {
        const a = document.createElement('a');
        a.href = this.url;
        a.download = this.fileName();
        a.click();
      },
      async copyText() {
        const text = SetloSummary.asText(SetloSummary.fromBill(this.d));
        try { await navigator.clipboard.writeText(text); } catch (e) { showCopy('Copy the summary', text); }
        this.copied = true;
        setTimeout(() => { this.copied = false; }, 1800);
      },
    },
    template: `<div>
      <button @click="show" class="btn btn-outline w-full">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 12v7a1 1 0 001 1h14a1 1 0 001-1v-7M16 6l-4-4-4 4M12 2v14"/></svg>
        Share summary
      </button>
      <Teleport to="body">
      <div v-if="open" class="sheet-backdrop" @click.self="open = false">
        <div class="sheet">
          <div class="sheet-grip"></div>
          <div class="mb-3 flex items-center justify-between">
            <h2 class="text-[17px] font-extrabold text-ink">Bill summary</h2>
            <button @click="open = false" class="flex h-8 w-8 items-center justify-center rounded-full bg-slate-100 text-slate-500" aria-label="Close">✕</button>
          </div>
          <div class="max-h-[50vh] overflow-y-auto rounded-2xl border border-slate-200 bg-slate-50">
            <div v-if="busy" class="flex justify-center py-16"><span class="h-7 w-7 animate-spin rounded-full border-[3px] border-brand-200 border-t-brand-600"></span></div>
            <img v-else-if="url" :src="url" alt="Bill summary image" class="w-full" />
          </div>
          <div class="mt-4 grid gap-2.5" :class="canShareFiles ? 'grid-cols-3' : 'grid-cols-2'">
            <button v-if="canShareFiles" @click="share" class="btn btn-primary" :disabled="busy">Share</button>
            <button @click="download" class="btn btn-outline" :disabled="busy">Download</button>
            <button @click="copyText" class="btn btn-ghost" :disabled="busy">{{ copied ? 'Copied ✓' : 'Copy text' }}</button>
          </div>
        </div>
      </div>
      </Teleport>
    </div>`,
  };

  /** What to call a receipt: its title, else the store, else "Receipt 2". */
  const receiptLabel = (rc) => rc.title || rc.store || 'Receipt ' + rc.number;

  /**
   * Items under their receipt, for bills with two or more receipts: [{ key, label, items }]. Typed-in items
   * come last as "Added manually". With one receipt or none, a single unlabelled group.
   */
  function groupByReceipt(items, receipts) {
    receipts = receipts || [];
    if (receipts.length < 2) return [{ key: 'all', label: null, items }];
    const ids = receipts.map((rc) => rc.id);
    const groups = receipts.map((rc) => ({ key: 'r' + rc.id, label: rc.number + '. ' + receiptLabel(rc), items: items.filter((it) => it.receipt_id === rc.id) }));
    const loose = items.filter((it) => !ids.includes(it.receipt_id));
    if (loose.length) groups.push({ key: 'manual', label: 'Added manually', items: loose });
    return groups.filter((g) => g.items.length);
  }

  /** Which PayMongo channel paid an online payment: "GCash", "Maya", "Card · Visa •4242" ('' when unknown). */
  const ONLINE_VIA = { card: 'Card', gcash: 'GCash', maya: 'Maya', grab_pay: 'GrabPay', qrph: 'QR Ph', billease: 'BillEase', dob: 'Online banking' };
  function onlineVia(p) {
    if (!p || p.method !== 'online' || !p.online_via) return '';
    const name = ONLINE_VIA[p.online_via] || p.online_via;
    return p.online_detail ? name + ' · ' + p.online_detail : name;
  }
  /** A payment's method with its online channel: "Online (GCash)". methods: the page's own method labels. */
  function methodWithVia(p, methods) {
    const base = (methods && methods[p.method]) || p.method;
    const via = onlineVia(p);
    return via ? base + ' (' + via + ')' : base;
  }

  /**
   * Dropdown to pick who paid the restaurant (one person, or several with `multiple`).
   * props: people ([{ id, name, first, initials, color, photo }]), modelValue (selected ids), me (your id), multiple.
   * Emits update:modelValue. Closes on outside click / Esc / Tab, arrow keys move, Space/Enter toggles.
   */
  const PayerPicker = {
    components: { Avatar },
    props: {
      people: { type: Array, required: true },
      modelValue: { type: Array, required: true },
      me: { type: Number, default: 0 },
      multiple: { type: Boolean, default: true },
    },
    emits: ['update:modelValue'],
    data: () => ({ open: false, active: 0, up: false, shake: false }),
    computed: {
      selected() { return this.people.filter((p) => this.modelValue.includes(p.id)); },
      names() { return this.selected.map((p) => (p.id === this.me ? 'You' : p.first || p.name)); },
      summary() {
        const n = this.names;
        return n.length <= 2 ? n.join(' & ') : n[0] + ', ' + n[1] + ' +' + (n.length - 2);
      },
    },
    mounted() {
      this._outside = (e) => { if (this.open && !this.$el.contains(e.target)) this.close(); };
      document.addEventListener('pointerdown', this._outside);
    },
    beforeUnmount() { document.removeEventListener('pointerdown', this._outside); },
    methods: {
      isOn(id) { return this.modelValue.includes(id); },
      openList() {
        const r = this.$el.getBoundingClientRect();
        this.up = window.innerHeight - r.bottom < 280 && r.top > 280;
        this.active = Math.max(0, this.people.findIndex((p) => this.isOn(p.id)));
        this.open = true;
      },
      close(focus) { this.open = false; if (focus) this.$refs.trigger.focus(); },
      toggleOpen() { this.open ? this.close() : this.openList(); },
      pick(id) {
        if (!this.multiple) { this.$emit('update:modelValue', [id]); this.close(true); return; }
        if (this.isOn(id)) {
          if (this.modelValue.length === 1) {
            this.shake = true;
            setTimeout(() => { this.shake = false; }, 400);
            toast('Someone has to pay the restaurant.', 'warn');
            return;
          }
          this.$emit('update:modelValue', this.modelValue.filter((x) => x !== id));
        } else {
          // keep the people's own order so the first payer is stable
          this.$emit('update:modelValue', this.people.filter((p) => p.id === id || this.isOn(p.id)).map((p) => p.id));
        }
      },
      selectAll() { this.$emit('update:modelValue', this.people.map((p) => p.id)); },
      onlyMe() { this.$emit('update:modelValue', [this.me || this.people[0].id]); },
      onKey(e) {
        if (!this.open) {
          if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(e.key)) { e.preventDefault(); this.openList(); }
          return;
        }
        const n = this.people.length;
        if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); this.close(true); }
        else if (e.key === 'Tab') this.close();
        else if (e.key === 'ArrowDown') { e.preventDefault(); this.active = (this.active + 1) % n; }
        else if (e.key === 'ArrowUp') { e.preventDefault(); this.active = (this.active + n - 1) % n; }
        else if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); this.pick(this.people[this.active].id); }
      },
    },
    template: `<div class="pp" :class="{ open, up }" @keydown="onKey">
      <button ref="trigger" type="button" class="pp-trigger" :class="{ shake }" aria-haspopup="listbox" :aria-expanded="open" @click="toggleOpen">
        <span class="pp-faces"><avatar v-for="p in selected.slice(0, 3)" :key="p.id" :user="p" :size="24"></avatar></span>
        <span class="pp-summary">{{ summary }}</span>
        <span v-if="multiple && selected.length > 1" class="pp-count">{{ selected.length }} paid</span>
        <svg class="pp-chev" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6"/></svg>
      </button>
      <div v-if="open" class="pp-panel" role="listbox" :aria-multiselectable="multiple">
        <div v-if="multiple && people.length > 1" class="pp-tools">
          <span>Tick everyone who paid</span>
          <span><button type="button" @click="selectAll">All</button><button type="button" @click="onlyMe">Just me</button></span>
        </div>
        <div v-for="(p, i) in people" :key="p.id" class="pp-opt" :class="{ on: isOn(p.id), active: active === i }" role="option" :aria-selected="isOn(p.id)"
             @click="pick(p.id)" @mouseenter="active = i">
          <span class="pp-box" :class="{ radio: !multiple }"></span>
          <avatar :user="p" :size="28"></avatar>
          <span class="pp-name">{{ p.id === me ? 'You' : p.name }}</span>
          <span v-if="p.id === me" class="pp-tag">Creator</span>
        </div>
      </div>
    </div>`,
  };

  /**
   * Who physically received the change: one tap per person, the people who paid first. props: people (members),
   * modelValue (user id or null), me (your id), paid ({ id: amount } handed over). Emits update:modelValue.
   */
  const ChangeHolderPicker = {
    components: { Avatar },
    props: { people: { type: Array, required: true }, modelValue: { type: Number, default: null }, me: { type: Number, default: 0 }, paid: { type: Object, default: () => ({}) }, disabled: Boolean },
    emits: ['update:modelValue'],
    computed: {
      ordered() { return [...this.people].sort((a, b) => (this.paid[b.id] || 0) - (this.paid[a.id] || 0)); },
    },
    template: `<div class="flex flex-wrap gap-2" role="radiogroup" aria-label="Who received the change">
      <button v-for="p in ordered" :key="p.id" type="button" role="radio" :aria-checked="modelValue === p.id" :disabled="disabled"
              class="mchip" :class="{ on: modelValue === p.id }" @click="$emit('update:modelValue', p.id)">
        <avatar :user="p" :size="24"></avatar>{{ p.id === me ? 'You' : p.first }}
      </button>
    </div>`,
  };

  /**
   * The change, person by person (bills.php payments): the total handed back by the cashier, who holds it, and for
   * each member what they put in, their share, and what's due back to them (or what they still owe), so everyone can
   * check it against the cash in their hand. Shown only when there was change. props: payments, shares, members, me.
   */
  const ChangeSummary = {
    components: { Avatar },
    props: { payments: { type: Object, required: true }, shares: { type: Object, default: () => ({}) }, members: { type: Array, required: true }, me: { type: Number, default: 0 } },
    computed: {
      holder() { return this.members.find((m) => m.id === this.payments.change_holder) || null; },
      rows() {
        const exp = this.payments.expected || {};
        return this.members
          .map((m) => ({ ...m, put: this.payments.paid[m.id] || 0, share: this.shares[m.id] || 0, back: exp[m.id] || 0 }))
          .sort((a, b) => b.put - a.put || b.back - a.back);
      },
    },
    methods: { who(m) { return m.id === this.me ? 'You' : m.first; } },
    template: `<div v-if="payments.change > 0" class="rounded-2xl border border-amber-200 bg-amber-50/70 p-3.5">
      <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
          <p class="text-[11px] font-extrabold uppercase tracking-[.06em] text-amber-800/70">Group change</p>
          <p class="text-[20px] font-extrabold leading-tight text-amber-900">{{ peso(payments.change) }}</p>
        </div>
        <div v-if="holder" class="flex shrink-0 items-center gap-1.5 rounded-full bg-white/80 py-1 pl-1 pr-2.5 text-[12px] font-bold text-amber-900">
          <avatar :user="holder" :size="22"></avatar>{{ holder.id === me ? 'You have it' : holder.first + ' has it' }}
        </div>
        <span v-else class="shrink-0 rounded-full bg-rose-100 px-2.5 py-1 text-[11.5px] font-bold text-rose-700">Who got it?</span>
      </div>
      <p class="mt-1 text-[11.5px] leading-snug text-amber-800/80">{{ peso(payments.total) }} handed to the cashier. Each person's change is what they put in minus their share.</p>
      <div class="mt-3 divide-y divide-amber-200/60 rounded-xl bg-white/70">
        <div v-for="m in rows" :key="m.id" class="flex items-center gap-2.5 px-3 py-2">
          <avatar :user="m" :size="26"></avatar>
          <div class="min-w-0 flex-1">
            <p class="truncate text-[12.5px] font-bold text-ink">{{ who(m) }}</p>
            <p class="text-[11px] text-slate-500">Put in {{ peso(m.put) }} · share {{ peso(m.share) }}</p>
          </div>
          <p v-if="m.back > 0.004" class="text-right text-[13px] font-extrabold text-emerald-600">{{ peso(m.back) }}<span class="block text-[10px] font-semibold text-slate-400">change due</span></p>
          <p v-else-if="m.back < -0.004" class="text-right text-[13px] font-extrabold text-rose-600">{{ peso(-m.back) }}<span class="block text-[10px] font-semibold text-slate-400">still owes</span></p>
          <p v-else class="text-right text-[12px] font-bold text-slate-400">Even</p>
        </div>
      </div>
    </div>`,
  };

  const helpers = { peso, onlineVia, methodWithVia, pesoShort, currencySymbol, currencyLabel, fmtRate, homeCurrency: global.SETLO_HOME_CURRENCY || 'PHP', timeAgo, fmtDate, fmtDateTime, fmtMonth, toast, receiptLabel, statusLabel: (s) => STATUS_LABELS[s] || s };

  /** Create and mount a page's Vue app with the shared components and helpers. */
  function mount(options, selector) {
    const app = createApp(options);
    app.component('avatar', Avatar);
    app.component('avatar-picker', AvatarPicker);
    app.component('payer-picker', PayerPicker);
    app.component('change-holder-picker', ChangeHolderPicker);
    app.component('change-summary', ChangeSummary);
    app.component('rate-choice', RateChoice);
    app.component('currency-select', CurrencySelect);
    app.component('status-pill', StatusPill);
    app.component('spinner', Spinner);
    app.component('notif-bell', NotifBell);
    app.component('notif-icon', NotifIcon);
    app.component('share-summary', ShareSummary);
    app.component('status-badge', StatusBadge);
    app.component('person-result', PersonResult);
    // Pages that load assets/js/replay.js
    if (global.BillReplay) app.component('bill-replay', global.BillReplay);
    Object.assign(app.config.globalProperties, helpers);
    app.config.errorHandler = (err) => { console.error(err); toast(err.message || 'Something went wrong.'); };
    return app.mount(selector || '#app');
  }

  /** Run an API call with a busy flag and error toast. Returns the result or undefined on error. */
  async function run(vm, fn, busyKey) {
    const key = busyKey || 'busy';
    vm[key] = true;
    try {
      return await fn();
    } catch (e) {
      toast(e.message);
      return undefined;
    } finally {
      vm[key] = false;
    }
  }

  /**
   * Load a page's data without a blink: show the last saved reply at once (if this tab has one),
   * then always fetch fresh data and apply it again. `apply(reply, fresh)` copies the reply into the page;
   * `fresh` is false for the saved reply, true for the server's.
   */
  async function load(vm, path, params, apply, busyKey) {
    const key = busyKey || 'loading';
    const saved = api.peek(path, params);
    if (saved) apply(saved, false);
    vm[key] = !saved;
    try {
      apply(await api.get(path, params), true);
    } catch (e) {
      toast(e.message);
    } finally {
      vm[key] = false;
    }
  }

  // Sidebar burger ([data-nav-toggle="lg|md"]): on screens at least that wide it shrinks the sidebar
  // to icons (remembered), on smaller ones it opens the slide-in menu. Delegated, so it works in and out of Vue.
  const root = document.documentElement;
  const NAV_BREAK = { lg: 1024, md: 768 };
  const syncBurgers = () => {
    document.querySelectorAll('[data-nav-toggle]').forEach((b) => {
      const wide = window.innerWidth >= (NAV_BREAK[b.dataset.navToggle] || 1024);
      b.setAttribute('aria-expanded', String(wide ? !root.classList.contains('sb-collapsed') : root.classList.contains('nav-open')));
    });
  };
  const closeDrawer = () => { root.classList.remove('nav-open'); syncBurgers(); };
  document.addEventListener('click', (e) => {
    const toggle = e.target.closest('[data-nav-toggle]');
    if (toggle) {
      const brk = NAV_BREAK[toggle.dataset.navToggle] || 1024;
      if (window.innerWidth >= brk) {
        const collapsed = root.classList.toggle('sb-collapsed');
        try { localStorage.setItem('setlo-sidebar', collapsed ? 'collapsed' : 'open'); } catch (err) { /* storage blocked: this page only */ }
      } else {
        root.dataset.navBreak = brk;
        root.classList.toggle('nav-open');
      }
      syncBurgers();
      return;
    }
    if (root.classList.contains('nav-open') && e.target.closest('[data-nav-close], .nav-drawer a, .admin-aside a')) closeDrawer();
  });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && root.classList.contains('nav-open')) closeDrawer(); });
  window.addEventListener('resize', () => {
    if (root.classList.contains('nav-open') && window.innerWidth >= Number(root.dataset.navBreak || 1024)) closeDrawer();
  });
  window.addEventListener('load', syncBurgers);

  // Confirm before signing out, everywhere a sign-out form appears (sidebar, phone drawer, admin, account menu).
  // Capture phase: runs before the click reaches the <button> and submits the form.
  document.addEventListener('click', (e) => {
    const form = e.target.closest('form[action="logout"]');
    if (!form || !e.target.closest('button')) return;
    e.preventDefault();
    // If the styled dialog can't be shown (e.g. SweetAlert failed to load), log why and ask with the browser's own prompt.
    confirmDialog({ title: 'Sign out?', text: 'You can log back in anytime.', confirmText: 'Sign out' })
      .catch((err) => { console.error('Sign-out dialog failed:', err); return window.confirm('Sign out?'); })
      .then((ok) => { if (ok) form.submit(); });
  }, true);

  global.Setlo = { mount, run, load, useCurrency, allocate, roundShares, convertShares, currencies: CURRENCIES, homeCurrency: global.SETLO_HOME_CURRENCY || 'PHP', confirm: confirmDialog, alert: alertDialog, promptText, showCopy, escapeHtml, payLink, qrSvg, qrPng, showPayQr, groupByReceipt, toggleArchive, afterSignUp, cropPhoto, captureCamera, compressImage, photoViewer, showAchievements, showAchievement: (a) => achievementModal(a, { reopened: true }), ...helpers };
})(window);
