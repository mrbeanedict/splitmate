// The page backdrop chosen on the Profile (body[data-bg], set by head.php): the user's shown badges, repeated and
// scattered over a jittered grid, drifting behind the page (see badge-bg.css); plain; or their own photo.
// Added to <body> (not #app) so it never fights Vue's rendering. Decoration only: any failure just shows nothing.
(function () {
  const rnd = (min, max) => min + Math.random() * (max - min);

  function build(badges) {
    document.querySelector('.badge-field')?.remove();
    if (!badges.length) return;
    const big = window.matchMedia('(min-width: 1024px)').matches;
    const cols = big ? 6 : 4, rows = 4;
    const field = document.createElement('div');
    field.className = 'badge-field';
    field.setAttribute('aria-hidden', 'true');
    for (let n = 0; n < cols * rows; n++) {
      const img = document.createElement('img');
      const size = Math.round(rnd(44, 86));
      img.src = badges[n % badges.length].image;
      img.alt = '';
      img.draggable = false;
      img.style.cssText = `left:${(((n % cols) + 0.5) / cols * 100 + rnd(-6, 6)).toFixed(1)}%;`
        + `top:${(((Math.floor(n / cols)) + 0.5) / rows * 100 + rnd(-8, 8)).toFixed(1)}%;`
        + `width:${size}px;height:${size}px;opacity:${rnd(0.45, 0.8).toFixed(2)};`
        + `--dx:${Math.round(rnd(25, 60) * (Math.random() < 0.5 ? -1 : 1))}px;--dy:${Math.round(rnd(20, 50))}px;`
        + `--dur:${rnd(9, 16).toFixed(1)}s;--delay:-${rnd(0, 12).toFixed(1)}s;`;
      field.appendChild(img);
    }
    document.body.appendChild(field);
  }

  // Receipts preset: one long receipt, itemised with prices, swimming slowly up the page (see badge-bg.css), over a
  // wallpaper of tiny sketched chameleon faces. Plain DOM, no resize handling needed (it is taller than any screen).
  const ITEMS = [['Pork sinigang', 385], ['Garlic rice x4', 160], ['Sisig', 295], ['Iced tea pitcher', 120], ['Halo-halo x3', 255],
    ['Lechon kawali', 340], ['Calamansi juice', 90], ['Milk tea x2', 210], ['Chicken inasal', 275], ['Leche flan', 130],
    ['Pancit canton', 220], ['Service charge', 117], ['Grab fare', 148], ['Coffee x3', 285], ['Pizza slice x2', 190], ['Bottled water', 45]];

  function buildReceipt() {
    document.querySelector('.badge-field')?.remove();
    const field = document.createElement('div');
    field.className = 'badge-field badge-field-receipt';
    field.setAttribute('aria-hidden', 'true');
    let rows = '', total = 0;
    for (let i = 0; i < 40; i++) {
      const [name, price] = ITEMS[(i * 7 + 3) % ITEMS.length];
      total += price;
      rows += `<li><span>${name}</span><b>₱${price.toFixed(2)}</b></li>`;
    }
    field.innerHTML = `<div class="rcpt"><div class="rcpt-sway"><div class="rcpt-paper">`
      + `<p class="rcpt-head">SETLO</p><p class="rcpt-sub">Split the bill, not the friendship</p>`
      + `<ul>${rows}</ul><p class="rcpt-total"><span>TOTAL</span><b>₱${total.toLocaleString('en-PH', { minimumFractionDigits: 2 })}</b></p>`
      + `<p class="rcpt-sub">Thank you! Settle up soon ♥</p></div></div></div>`;
    document.body.appendChild(field);
  }

  /** Redraw the backdrop: the receipt, or the floating badges ($badges from api/achievements.php, or fetched;
      hidden ones never float). Anything else removes the layer. */
  async function refresh(badges) {
    if (document.body.dataset.bg === 'receipts') { buildReceipt(); return; }
    if (document.body.dataset.bg !== 'badges') {
      document.querySelector('.badge-field')?.remove();
      return;
    }
    try {
      badges = badges || (await api.get('achievements.php')).badges;
      build(badges.filter((a) => !a.hidden && a.image));
    } catch (e) { /* non-critical */ }
  }

  /** Switch the backdrop now ({ mode, image } from api/profile.php), without a reload. */
  function set(bg) {
    const body = document.body;
    if (!body.classList.contains('badge-bg') || !bg) return;
    body.dataset.bg = bg.mode;
    if (bg.image) body.style.setProperty('--bg-photo', `url("${bg.image}")`);
    else body.style.removeProperty('--bg-photo');
    refresh();
  }

  window.SetloBackground = { set, refresh };
  document.addEventListener('DOMContentLoaded', () => { if (document.body.classList.contains('badge-bg')) refresh(); });
})();
