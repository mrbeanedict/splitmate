// Setlo Buddy: the chameleon's chat panel (floating button on every signed-in page). It talks to api/assistant.php; the
// server decides what the assistant may look at (only the signed-in user's own data) and keeps the conversation.
// Replies are plain text with a little markdown (**bold**, "- " lists, [links](page)); everything is escaped before
// it reaches the page, and only links to Setlo's own pages are made clickable.
(function () {
  const root = document.getElementById('sai');
  if (!root) return;

  const $ = (sel) => root.querySelector(sel);
  const fab = $('.sai-fab'), panel = $('.sai-panel'), log = $('.sai-log'), chips = $('.sai-chips');
  const form = $('.sai-form'), box = form.querySelector('textarea'), send = $('.sai-send');
  const base = root.dataset.mascot || '';
  const SUGGESTIONS = [
    'How much do I owe?',
    'Who hasn’t paid me?',
    'Remind someone who owes me',
    'How much did I spend this month?',
    'How do I scan a receipt?',
    'Teach me how to split a bill',
    'How do foreign currencies work?',
  ];
  let loaded = false, busy = false;

  const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

  // [text](page): only a plain Setlo page name (optionally ?bill=1) becomes a link; anything else stays as text.
  const SAFE_LINK = /^[a-z0-9-]+(\?[a-z0-9=&_-]*)?$/i;
  function inline(text) {
    return esc(text)
      .replace(/\[([^\]]{1,80})\]\(([^)\s]{1,120})\)/g, (m, label, href) => (SAFE_LINK.test(href) ? '<a href="' + href + '">' + label + '</a>' : label))
      .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
      .replace(/(^|[\s(])\*([^*\s][^*]*)\*(?=[\s).,!?]|$)/g, '$1<em>$2</em>');
  }
  function render(text) {
    const out = [];
    let list = null;
    const flush = () => { if (list) { out.push('<ul>' + list.join('') + '</ul>'); list = null; } };
    String(text).split(/\r?\n/).forEach((line) => {
      const li = line.match(/^\s*(?:[-*•]|\d+[.)])\s+(.*)$/);
      if (li) { (list = list || []).push('<li>' + inline(li[1]) + '</li>'); return; }
      flush();
      if (line.trim()) out.push('<p>' + inline(line.trim()) + '</p>');
    });
    flush();
    return out.join('');
  }

  function scrollDown() { log.scrollTop = log.scrollHeight; }
  function add(kind, text) {
    const el = document.createElement('div');
    el.className = 'sai-msg ' + kind;
    if (kind === 'sai-me') el.textContent = text; else el.innerHTML = render(text);
    log.appendChild(el);
    scrollDown();
    return el;
  }
  // An abusive message is hidden for good: the text is wiped from the page and only this placeholder stays.
  function hideBubble(el) {
    el.className = 'sai-msg sai-hidden';
    el.innerHTML = '<svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3l18 18M10.6 10.6a2 2 0 002.8 2.8M9.9 5.1A9.8 9.8 0 0112 5c5 0 9 4.5 10 7a11.6 11.6 0 01-3.2 4.3M6.3 6.3C3.9 8 2.4 10.3 2 12c1 2.5 5 7 10 7a9.7 9.7 0 004.1-.9"/></svg> Message hidden · offensive language';
    return el;
  }
  const mascot = (pose) => { const img = fab.querySelector('img'); img.src = base + pose + '.png'; };

  // Things the server attached to a reply: a bar chart, or an action waiting for the user's OK. An action only runs
  // when its button is tapped, through the normal API (which checks it again), never because the model said so.
  function renderActions(list) {
    (list || []).forEach((a) => {
      if (a.type === 'chart') chart(a);
      else if (a.type === 'nudge') nudgeCard(a);
    });
    scrollDown();
  }
  function chart(a) {
    const max = Math.max(...a.bars.map((b) => Number(b.amount) || 0), 1);
    const el = document.createElement('div');
    el.className = 'sai-card';
    el.innerHTML = '<b>' + esc(a.title) + '</b>' + a.bars.map((b) =>
      '<div class="sai-bar"><span>' + esc(b.label) + '</span><i style="--w:' + Math.max(3, Math.round(100 * b.amount / max)) + '%"></i><em>'
      + esc(Setlo.peso(b.amount, a.currency)) + '</em></div>').join('');
    log.appendChild(el);
  }
  function nudgeCard(a) {
    const el = document.createElement('div');
    el.className = 'sai-card';
    const t = document.createElement('p');
    t.textContent = a.summary + '?';
    const row = document.createElement('div');
    row.className = 'sai-card-actions';
    const yes = document.createElement('button');
    yes.type = 'button'; yes.className = 'sai-btn'; yes.textContent = a.confirm || 'Confirm';
    const no = document.createElement('button');
    no.type = 'button'; no.className = 'sai-btn sai-btn-ghost'; no.textContent = 'Not now';
    const done = (msg, ok) => { row.remove(); const m = document.createElement('p'); m.className = ok ? 'sai-ok' : 'sai-no'; m.textContent = msg; el.appendChild(m); };
    yes.addEventListener('click', async () => {
      yes.disabled = no.disabled = true;
      try {
        await api.post('settlements.php', { action: 'nudge', id: a.settlement_id });
        Setlo.toast('Reminder sent', 'ok');
        done('✓ Reminder sent', true);
      } catch (e) { done(e.message || 'Could not send the reminder.', false); }
    });
    no.addEventListener('click', () => done('Okay, I won’t send it.', false));
    row.append(yes, no);
    el.append(t, row);
    log.appendChild(el);
  }

  function showChips() {
    chips.innerHTML = '';
    SUGGESTIONS.forEach((s) => {
      const b = document.createElement('button');
      b.type = 'button'; b.className = 'sai-chip'; b.textContent = s;
      b.addEventListener('click', () => ask(s));
      chips.appendChild(b);
    });
  }

  async function load() {
    loaded = true;
    try {
      const r = await api.poll('assistant.php');
      (r.messages || []).forEach((m) => {
        if (Number(m.flag) === 1) hideBubble(add('sai-me', ''));
        else add(m.role === 'user' ? 'sai-me' : 'sai-bot', m.content);
      });
      if (!r.messages || !r.messages.length) greet();
    } catch (e) { greet(); }
    showChips();
  }
  function greet() {
    add('sai-bot', 'Hi! I’m **Setlo Buddy**. Ask me how Setlo works, or about your own bills, debts and spending. I can only read your data, never change it.');
  }

  async function ask(text) {
    text = String(text || '').trim();
    if (!text || busy) return;
    busy = true; send.disabled = true;
    chips.innerHTML = '';
    const mine = add('sai-me', text);
    box.value = ''; box.style.height = '';
    const dots = document.createElement('div');
    dots.className = 'sai-typing'; dots.setAttribute('aria-label', 'Setlo Buddy is typing'); dots.innerHTML = '<i></i><i></i><i></i>';
    log.appendChild(dots); scrollDown(); mascot('think');
    try {
      const r = await api.post('assistant.php', { action: 'chat', message: text });
      dots.remove();
      if (r.hidden) { hideBubble(mine); text = ''; }
      add('sai-bot', r.reply); renderActions(r.actions); mascot(r.hidden ? 'wave' : 'cheer');
      setTimeout(() => mascot('wave'), 2500);
    } catch (e) {
      dots.remove(); add('sai-err', e.message || 'Something went wrong. Try again.'); mascot('wave');
    }
    busy = false; send.disabled = !box.value.trim();
    box.focus();
  }

  function open() {
    root.classList.add('open');
    panel.hidden = false;
    fitToKeyboard();
    fab.setAttribute('aria-expanded', 'true');
    if (!loaded) load();
    setTimeout(() => { box.focus(); scrollDown(); }, 60);
    try { sessionStorage.setItem('setlo-ai-open', '1'); } catch (e) { /* storage blocked */ }
  }
  function close() {
    root.classList.remove('open');
    panel.hidden = true;
    fab.setAttribute('aria-expanded', 'false');
    fab.focus();
    try { sessionStorage.removeItem('setlo-ai-open'); } catch (e) { /* storage blocked */ }
  }

  async function clearChat() {
    const ok = await Setlo.confirm({ title: 'Clear this chat?', text: 'Setlo Buddy forgets the conversation.', confirmText: 'Clear', danger: true });
    if (!ok) return;
    try { await api.post('assistant.php', { action: 'clear' }); } catch (e) { Setlo.toast(e.message); return; }
    log.innerHTML = ''; greet(); showChips();
  }

  // Sit above the sticky "Continue" bar (phones) so the button never covers it
  function lift() {
    const dock = document.querySelector('.dock');
    const h = dock && dock.offsetParent !== null ? Math.ceil(dock.getBoundingClientRect().height) + 8 : 0;
    root.style.setProperty('--sai-lift', (window.innerWidth < 1024 ? h : 0) + 'px');
  }

  fab.addEventListener('click', open);
  $('.sai-close').addEventListener('click', close);
  $('.sai-clear').addEventListener('click', clearChat);
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && root.classList.contains('open') && !document.querySelector('.swal2-container')) close(); });
  form.addEventListener('submit', (e) => { e.preventDefault(); ask(box.value); });
  box.addEventListener('keydown', (e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); ask(box.value); } });
  box.addEventListener('input', () => {
    box.style.height = 'auto';
    box.style.height = Math.min(box.scrollHeight, 104) + 'px';
    send.disabled = busy || !box.value.trim();
  });
  // Phones: when the on-screen keyboard opens, keep the sheet (and its input) above it instead of hidden behind it
  function fitToKeyboard() {
    const vv = window.visualViewport;
    if (!vv || window.innerWidth >= 640) { panel.style.removeProperty('--sai-kb'); panel.style.removeProperty('--sai-vh'); return; }
    const kb = Math.max(0, Math.round(window.innerHeight - vv.height - vv.offsetTop));
    panel.style.setProperty('--sai-kb', kb + 'px');
    panel.style.setProperty('--sai-vh', Math.round(vv.height) + 'px');
    if (kb > 0) scrollDown();
  }
  if (window.visualViewport) {
    window.visualViewport.addEventListener('resize', fitToKeyboard);
    window.visualViewport.addEventListener('scroll', fitToKeyboard);
  }

  // Phones: tuck the button into the edge while the page scrolls, bring it back when it stops
  let scrollTimer = 0;
  window.addEventListener('scroll', () => {
    root.classList.add('scrolling');
    clearTimeout(scrollTimer);
    scrollTimer = setTimeout(() => root.classList.remove('scrolling'), 700);
  }, { passive: true });
  window.addEventListener('resize', lift);
  window.addEventListener('load', lift);
  setTimeout(lift, 600);
  send.disabled = true;
  try { if (sessionStorage.getItem('setlo-ai-open') === '1') open(); } catch (e) { /* storage blocked */ }
})();
