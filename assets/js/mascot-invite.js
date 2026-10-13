// "Create an account" invitation: the Setlo chameleon peeks over a card, says hi, and shows what registering unlocks.
// Used for guests (the demo at pages/try, a guest's bill at pages/guest). Needs assets/css/tour.css (its pose
// animations and the .inv-* styles). Exposes window.SetloInvite:
//   show({ variant: 'demo' | 'guest' | 'locked', name, creator, bill, feature, registerHref, loginHref })
//        → Promise of 'register' | 'login' | 'later' (the two links navigate, so those rarely get read)
//   once(key, opts) → like show(), but opens at most once per browser tab session for that key (null if it already did)
(function (global) {
  const SRC = (document.currentScript && document.currentScript.src) || '';
  const base = SRC.includes('assets/js/') ? SRC.split('assets/js/')[0] : '';
  const POSE_URL = (p) => base + 'assets/mascot/' + p + '.png';
  const EMOTES = { wave: 'hand', cheer: 'party', thumbs: 'thumbs', think: 'help', search: 'search', run: 'bolt' };
  const PERKS = [
    ['receipt', 'Save every bill and your full history'],
    ['scan', 'Scan receipts, items read for you'],
    ['wallet', 'Pay with GCash, Maya or cash'],
    ['users', 'Groups and friends, ready in one tap'],
    ['trophy', 'Badges and Fun Mode games'],
  ];
  const ICON = (name, size) => (global.SetloIcons ? SetloIcons.svg(name, size) : '');
  const reduced = () => global.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  let open = null;

  function words(o) {
    const first = esc(String(o.name || '').split(' ')[0] || 'there');
    if (o.variant === 'guest') {
      return {
        kicker: 'You’re on a bill',
        title: `Hi <b>${first}</b>!`,
        text: `${esc(o.creator || 'A friend')} added you to “${esc(o.bill || 'a bill')}”. Create a free account and this bill, and every payment you make, stays with you.`,
        note: 'Your share and payment records are kept when you sign up.',
        cta: 'Create my free account', pose: 'wave',
      };
    }
    if (o.variant === 'locked') {
      const f = esc(o.feature || 'unlock this');
      return {
        kicker: 'Free account needed',
        title: `Want to <b>${f}</b>?`,
        text: 'It only takes a minute to make a free account, and then it’s all yours.',
        note: 'Nothing you tried here is saved until you sign up.',
        cta: 'Unlock with a free account', pose: 'search',
      };
    }
    return {
      kicker: 'Enjoying Setlo?',
      title: 'Make it <b>yours</b>',
      text: 'You’re signed in as a guest, so nothing here is saved. Sign up free to keep your bills and settle up with friends.',
      note: 'Free, no card needed.',
      cta: 'Create my free account', pose: 'cheer',
    };
  }

  function show(o = {}) {
    if (open) return open.done;
    const w = words(o);
    const registerHref = o.registerHref || 'register';
    const loginHref = o.loginHref || 'login';
    const root = document.createElement('div');
    root.className = 'inv';
    root.setAttribute('role', 'dialog');
    root.setAttribute('aria-modal', 'true');
    root.setAttribute('aria-label', 'Create a Setlo account');
    root.innerHTML = `
      <div class="inv-card">
        <div class="tour-mascot inv-mascot">
          <div class="tour-bob"><div class="tour-flip"><img alt="" draggable="false" /></div></div>
          <span class="tour-emote" aria-hidden="true"></span>
        </div>
        <span class="inv-spark" aria-hidden="true">${ICON('sparkle', 18)}</span><span class="inv-spark" aria-hidden="true">${ICON('star', 22)}</span><span class="inv-spark" aria-hidden="true">${ICON('sparkle', 14)}</span>
        <button type="button" class="inv-x" aria-label="Close">${ICON('x', 16)}</button>
        <p class="inv-kicker">${w.kicker}</p>
        <h2 class="inv-title">${w.title}</h2>
        <p class="inv-text">${w.text}</p>
        <ul class="inv-perks">${PERKS.map(([i, t], k) => `<li style="animation-delay:${0.45 + k * 0.12}s"><i>${ICON(i, 16)}</i><span>${t}</span><em style="animation-delay:${0.75 + k * 0.12}s">${ICON('check', 16)}</em></li>`).join('')}</ul>
        <a class="inv-cta" href="${esc(registerHref)}">${w.cta} <span aria-hidden="true">→</span></a>
        <a class="inv-alt" href="${esc(loginHref)}">I already have an account</a>
        <button type="button" class="inv-later">Maybe later</button>
        <p class="inv-note">${w.note}</p>
      </div>`;
    const card = root.querySelector('.inv-card'), img = root.querySelector('.tour-mascot img'), emote = root.querySelector('.tour-emote');
    const cta = root.querySelector('.inv-cta'), alt = root.querySelector('.inv-alt'), later = root.querySelector('.inv-later');

    let resolveDone, closed = false, current = '';
    const done = new Promise((r) => { resolveDone = r; });
    const htmlStyle = document.documentElement.style, oldOverflow = htmlStyle.overflow;
    htmlStyle.overflow = 'hidden';
    const lastFocus = document.activeElement;

    /** The chameleon changes pose (and mood) with a little pop. */
    function pose(name) {
      if (name === current) return;
      current = name;
      img.src = POSE_URL(name);
      img.className = '';
      emote.classList.remove('show');
      void img.offsetWidth;
      img.className = 'pose-' + name + ' act-' + name;
      emote.innerHTML = ICON(EMOTES[name], 22);
      emote.classList.add('show');
    }

    function close(result) {
      if (closed) return;
      closed = true;
      document.removeEventListener('keydown', onKey, true);
      root.classList.add('leaving');
      setTimeout(() => {
        root.remove();
        htmlStyle.overflow = oldOverflow;
        open = null;
        if (lastFocus && lastFocus.focus) lastFocus.focus({ preventScroll: true });
        resolveDone(result);
      }, reduced() ? 0 : 230);
    }
    function onKey(e) {
      if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close('later'); }
      else if (e.key === 'Tab') {   // keep focus inside the card
        const f = [...card.querySelectorAll('a[href], button')];
        const i = f.indexOf(document.activeElement);
        if (e.shiftKey && i <= 0) { e.preventDefault(); f[f.length - 1].focus(); }
        else if (!e.shiftKey && i === f.length - 1) { e.preventDefault(); f[0].focus(); }
      }
    }

    root.addEventListener('click', (e) => { if (e.target === root) close('later'); });
    root.querySelector('.inv-x').addEventListener('click', () => close('later'));
    later.addEventListener('click', () => close('later'));
    // The mascot reacts to what you're looking at: thrilled at the button, a little sad at "maybe later"
    let hovering = false;
    const mood = (el, p, back) => {
      ['pointerenter', 'focus'].forEach((t) => el.addEventListener(t, () => { hovering = true; pose(p); }));
      ['pointerleave', 'blur'].forEach((t) => el.addEventListener(t, () => { hovering = false; pose(back()); }));
    };
    let resting = w.pose;
    mood(cta, 'cheer', () => resting);
    mood(alt, 'wave', () => resting);
    mood(later, 'think', () => resting);
    cta.addEventListener('click', () => { resolveDone('register'); });
    alt.addEventListener('click', () => { resolveDone('login'); });
    // Tilt toward the pointer, like the badge cards
    if (!reduced() && matchMedia('(hover: hover)').matches) {
      root.addEventListener('pointermove', (e) => {
        const r = card.getBoundingClientRect();
        const x = ((e.clientX - r.left) / r.width - 0.5), y = ((e.clientY - r.top) / r.height - 0.5);
        card.style.transform = `perspective(900px) rotateY(${(x * 7).toFixed(2)}deg) rotateX(${(-y * 7).toFixed(2)}deg)`;
      });
      root.addEventListener('pointerleave', () => { card.style.transform = ''; });
    }

    document.addEventListener('keydown', onKey, true);
    document.body.appendChild(root);
    open = { done };
    pose(w.pose);
    // After the list has ticked in, the chameleon gives it a thumbs up
    setTimeout(() => { if (closed) return; resting = 'thumbs'; if (!hovering) pose('thumbs'); }, reduced() ? 0 : 1700);
    if (global.Setlo && Setlo.confetti) setTimeout(() => !closed && Setlo.confetti(['#14b8a6', '#facc15']), 450);
    card.tabIndex = -1;
    card.focus({ preventScroll: true });
    return done;
  }

  /** show() at most once per tab session for this key, so automatic invitations never nag. */
  function once(key, o) {
    const k = 'splitinvite:' + key;
    try { if (sessionStorage.getItem(k)) return null; sessionStorage.setItem(k, '1'); } catch (e) { /* storage blocked: still show it once per page */ }
    return show(o);
  }

  global.SetloInvite = { show, once };
})(window);
