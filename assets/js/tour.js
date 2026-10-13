// First-visit dashboard tour: the Setlo chameleon walks a new user through the app, one spotlighted feature at a
// time. Tap anywhere (or → / Enter / Space) to go on, ← to go back, Skip or Esc to leave. Finishing or skipping is
// saved (api/profile.php 'onboarded') so it shows once; the dashboard's account menu can replay it.
// Exposes window.SetloTour = { start({ replay }) } → Promise that resolves when the tour closes.
(function (global) {
  const base = (document.querySelector('link[href*="assets/css/tour.css"]')?.getAttribute('href') || '').split('assets/css/')[0];
  const POSES = { wave: 'right', run: 'right', think: 'left', cheer: null, thumbs: 'right', search: 'left' }; // way each pose faces
  const EMOTES = { wave: 'hand', run: 'bolt', think: 'help', cheer: 'party', thumbs: 'thumbs', search: 'search' };
  const poseUrl = (p) => base + 'assets/mascot/' + p + '.png';
  const MENU = 'button[aria-label="Open menu"]';

  // target: a [data-tour] name, or none for a centred step. alt: where to point when the target isn't on screen
  // (the sidebar-only links are hidden on phones, where they live in the Menu).
  const STEPS = [
    { pose: 'wave', title: 'Hey there, welcome to Setlo!',
      text: "I'm your Setlo buddy. Let me show you how splitting the bill works. It only takes a minute." },
    { target: 'balance', pose: 'think', title: 'Who owes who?',
      text: 'This shows what you owe your friends and what they owe you across all open bills. Tap either side for the details.' },
    { target: 'new-bill', pose: 'run', title: 'Start a bill',
      text: "Just ate out? Tap New Bill, add the people you ate with, and I'll keep track of who had what." },
    { target: 'nav-scan', pose: 'search', title: 'Scan the receipt',
      text: "Snap a photo of the receipt and I'll read the items and prices for you. No more typing everything in." },
    { target: 'nav-groups', alt: MENU, pose: 'wave', title: 'Your usual barkada',
      text: 'Save the people you always eat with as a group, so the next bill takes one tap to set up.',
      altText: 'Groups are in the Menu. Save the people you always eat with, so the next bill takes one tap to set up.' },
    { target: 'nav-settle', pose: 'thumbs', title: 'Settle up',
      text: 'Pay what you owe with GCash, Maya, bank transfer or cash, and attach your proof. Once your friend confirms, it\'s done.' },
    { target: 'nav-community', alt: MENU, pose: 'run', title: 'Community',
      text: 'See where everyone else is eating and share your own food finds.',
      altText: 'Community is in the Menu too. See where everyone else is eating and share your own food finds.' },
    { pose: 'cheer', title: "You're all set!", last: true,
      text: 'That\'s the tour! Ready to split your first bill? You can replay this anytime from your account menu.' },
  ];

  const reduced = () => global.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  const wait = (ms) => new Promise((r) => setTimeout(r, ms));
  const shown = (el) => { if (!el) return false; const r = el.getBoundingClientRect(); return r.width > 0 && r.height > 0; };

  let active = null;

  function start({ replay = false } = {}) {
    if (active) return active.done;
    Object.keys(POSES).forEach((p) => { new Image().src = poseUrl(p); }); // no blank frame when the pose changes

    const root = document.createElement('div');
    root.className = 'tour';
    root.setAttribute('role', 'dialog');
    root.setAttribute('aria-modal', 'true');
    root.setAttribute('aria-label', 'Setlo tour');
    root.tabIndex = -1;
    root.innerHTML = `
      <div class="tour-spot"></div>
      <button type="button" class="tour-skip">Skip tour</button>
      <div class="tour-guide">
        <div class="tour-mascot">
          <div class="tour-bob"><div class="tour-flip"><img alt="" draggable="false" /></div></div>
          <span class="tour-emote" aria-hidden="true"></span>
        </div>
        <div class="tour-bubble" aria-live="polite">
          <p class="tour-count"></p>
          <h3 class="tour-title"></h3>
          <p class="tour-text"></p>
          <button type="button" class="tour-cta" hidden>Create my first bill</button>
          <div class="tour-foot">
            <div class="tour-dots">${STEPS.map(() => '<i></i>').join('')}</div>
            <span class="tour-hint"></span>
          </div>
        </div>
      </div>`;
    const $ = (s) => root.querySelector(s);
    const spot = $('.tour-spot'), guide = $('.tour-guide'), bubble = $('.tour-bubble'), img = $('.tour-mascot img');
    const flip = $('.tour-flip'), emote = $('.tour-emote'), textEl = $('.tour-text'), cta = $('.tour-cta');

    let index = -1, target = null, typing = null, closed = false, resolveDone;
    const done = new Promise((r) => { resolveDone = r; });
    const htmlStyle = document.documentElement.style, oldOverflow = htmlStyle.overflow;
    htmlStyle.overflow = 'hidden';

    /** The element a step points at on this screen size, and the text that goes with it. */
    function resolve(step) {
      if (!step.target) return [null, step.text];
      const el = document.querySelector(`[data-tour="${step.target}"]`);
      if (shown(el)) return [el, step.text];
      const alt = step.alt && document.querySelector(step.alt);
      return shown(alt) ? [alt, step.altText || step.text] : [null, step.text];
    }

    /** Move the spotlight onto the target (or close it for a centred step) and put the mascot + bubble beside it. */
    function place() {
      const vw = innerWidth, vh = innerHeight, pad = 12;
      guide.classList.toggle('center', !target);
      guide.classList.remove('mascot-right');
      if (!target) {
        Object.assign(spot.style, { left: vw / 2 + 'px', top: vh / 2 + 'px', width: '0px', height: '0px' });
        spot.classList.remove('on');
        const g = guide.getBoundingClientRect();
        guide.style.left = Math.max(pad, (vw - g.width) / 2) + 'px';
        guide.style.top = Math.max(pad, (vh - g.height) / 2) + 'px';
        flip.classList.remove('flipped');
        return;
      }
      const r = target.getBoundingClientRect(), gap = 8;
      const s = { left: r.left - gap, top: r.top - gap, width: r.width + gap * 2, height: r.height + gap * 2 };
      Object.assign(spot.style, { left: s.left + 'px', top: s.top + 'px', width: s.width + 'px', height: s.height + 'px' });
      spot.style.borderRadius = Math.min(22, s.height / 2) + 'px';
      spot.classList.add('on');

      const g = guide.getBoundingClientRect();
      const cx = r.left + r.width / 2, cy = r.top + r.height / 2;
      let left, top;
      if (r.width < 260 && vw - (s.left + s.width) >= g.width + pad * 2 && r.left < vw / 3) {
        // A sidebar link: stand to its right
        left = s.left + s.width + pad;
        top = cy - g.height + 40;
      } else {
        // Below the target when there's room, otherwise above; lined up with it as far as the screen allows
        left = cx - g.width / 2;
        top = vh - (s.top + s.height) >= g.height + pad ? s.top + s.height + pad : s.top - g.height - pad;
      }
      left = Math.min(Math.max(pad, left), vw - g.width - pad);
      top = Math.min(Math.max(pad + 40, top), vh - g.height - pad);
      // The mascot stands on the side of the bubble nearer the target, and turns to face it
      const mascotRight = cx > left + g.width * 0.62;
      guide.classList.toggle('mascot-right', mascotRight);
      guide.style.left = left + 'px';
      guide.style.top = top + 'px';
      const mw = $('.tour-mascot').offsetWidth; // where it will stand (the guide may still be sliding there)
      const faces = POSES[STEPS[index].pose];
      const lookRight = cx > (mascotRight ? left + g.width - mw / 2 : left + mw / 2);
      flip.classList.toggle('flipped', !!faces && (faces === 'right') !== lookRight);
    }

    function type(text) {
      clearInterval(typing);
      typing = null;
      if (reduced()) { textEl.textContent = text; return; }
      let n = 0;
      textEl.innerHTML = '<span></span><span class="caret"></span>';
      const out = textEl.firstChild;
      typing = setInterval(() => {
        n += 2;
        out.textContent = text.slice(0, n);
        if (n >= text.length) finishTyping(text);
      }, 22);
      textEl.dataset.full = text;
    }
    function finishTyping(text) {
      clearInterval(typing);
      typing = null;
      textEl.textContent = text || textEl.dataset.full || '';
    }

    async function go(i) {
      if (i < 0 || i >= STEPS.length || closed) return;
      const step = STEPS[i];
      index = i;
      let text;
      [target, text] = resolve(step);
      if (target) {
        const r = target.getBoundingClientRect();
        if (r.top < 70 || r.bottom > innerHeight - 70) {
          target.scrollIntoView({ block: 'center', behavior: reduced() ? 'auto' : 'smooth' });
          await wait(reduced() ? 0 : 380);
          if (index !== i || closed) return;
        }
      }

      $('.tour-count').textContent = step.last ? 'All done' : `Step ${i + 1} of ${STEPS.length}`;
      $('.tour-title').textContent = step.title;
      $('.tour-hint').textContent = step.last ? 'Tap anywhere to finish' : 'Tap anywhere to continue';
      cta.hidden = !step.last;
      $('.tour-skip').hidden = !!step.last;
      root.querySelectorAll('.tour-dots i').forEach((d, k) => { d.className = k < i ? 'done' : k === i ? 'now' : ''; });

      // New pose and expression: restart its entrance animation
      img.src = poseUrl(step.pose);
      img.className = '';
      emote.classList.remove('show');
      bubble.classList.remove('swap');
      void img.offsetWidth;
      img.className = 'pose-' + step.pose + ' act-' + step.pose;
      emote.innerHTML = global.SetloIcons ? SetloIcons.svg(EMOTES[step.pose], 22) : '';
      emote.classList.add('show');
      bubble.classList.add('swap');

      // Size the bubble for the whole sentence before typing it, so it doesn't grow into the spotlight
      textEl.style.minHeight = '';
      textEl.textContent = text;
      textEl.style.minHeight = textEl.offsetHeight + 'px';
      place();
      type(text);
      if (step.last && global.Setlo?.confetti) setTimeout(() => !closed && Setlo.confetti(['#14b8a6', '#facc15']), 350);
    }

    function next() {
      if (typing) return finishTyping(); // first tap shows the whole sentence
      if (index >= STEPS.length - 1) return close();
      go(index + 1);
    }

    async function close(then) {
      if (closed) return;
      closed = true;
      clearInterval(typing);
      removeEventListener('resize', onResize);
      removeEventListener('scroll', onResize, true);
      document.removeEventListener('keydown', onKey, true);
      if (!replay) {
        try { await api.post('profile.php', { action: 'onboarded' }); } catch (e) { /* shows again next visit, that's all */ }
      }
      root.classList.add('leaving');
      await wait(reduced() ? 0 : 240);
      root.remove();
      htmlStyle.overflow = oldOverflow;
      active = null;
      resolveDone();
      if (then) then();
    }

    const onResize = () => place();
    function onKey(e) {
      if (e.key === 'Escape') { e.preventDefault(); close(); }
      else if (e.key === 'ArrowRight' || e.key === 'Enter' || e.key === ' ') {
        if (e.target === cta) return;
        e.preventDefault(); next();
      } else if (e.key === 'ArrowLeft') { e.preventDefault(); finishTyping(); go(index - 1); }
      else return;
      e.stopPropagation();
    }

    root.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      if (e.target.closest('.tour-skip')) return close();
      if (e.target.closest('.tour-cta')) return close(() => { location.href = 'my-bills?new=1'; });
      next();
    });
    addEventListener('resize', onResize);
    addEventListener('scroll', onResize, true);
    document.addEventListener('keydown', onKey, true);

    document.body.appendChild(root);
    root.focus({ preventScroll: true });
    active = { done };
    go(0);
    return done;
  }

  global.SetloTour = { start };
})(window);
