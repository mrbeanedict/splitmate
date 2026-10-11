/* Login splash (partials/splash.php): removes the overlay when its timeline ends, on skip (click / tap /
   Esc / Enter / Space), or after a safety timeout, so the login form can never stay hidden behind it. */
(function () {
  const el = document.getElementById('splash');
  const root = document.documentElement;
  if (!el) return;
  if (!root.classList.contains('splash-on')) { el.remove(); return; }

  let done = false;
  function finish() {
    if (done) return;
    done = true;
    el.remove();
    root.classList.remove('splash-on');
    document.removeEventListener('keydown', onKey);
    // Desktop only: on phones, focusing would pop the keyboard over the page
    const email = document.getElementById('email');
    if (email && !matchMedia('(pointer: coarse)').matches) email.focus();
  }
  function skip() {
    if (done || el.classList.contains('sp-skip')) return;
    el.classList.add('sp-skip');
    setTimeout(finish, 320);
  }
  function onKey(e) {
    if (e.key === 'Escape' || e.key === 'Enter' || e.key === ' ') { e.preventDefault(); skip(); }
  }

  el.addEventListener('animationend', (e) => { if (e.animationName === 'sp-bg-out') finish(); });
  el.addEventListener('click', skip);
  document.addEventListener('keydown', onKey);
  setTimeout(finish, 7000);
})();
