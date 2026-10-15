<?php
// Login / sign-up landing shell (design: assets/login.png). The page prints its card, then includes auth-landing-bottom.php.
// Phones: card only. lg: brand column + card. xl: brand column + card + phone mockup.
?>
<style>
  /* Sign-in pages: Home link in an always-visible top bar (plain CSS, no Tailwind rebuild) */
  .auth-top { position: absolute; top: 0; left: 0; right: 0; z-index: 5; display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 16px; }
  .landing .landing-grid { padding-top: 4.5rem; }
  .auth-top a { display: inline-flex; align-items: center; gap: 8px; min-height: 40px; padding: 0 14px; border-radius: 999px; font-size: 13.5px; font-weight: 700; text-decoration: none; transition: background .15s, border-color .15s, color .15s; }
  .auth-top .home { color: rgb(var(--brand-700)); background: rgb(var(--surface) / .8); border: 1px solid rgb(var(--brand-200)); }
  .auth-top .home:hover { background: rgb(var(--brand-50)); border-color: rgb(var(--brand-400)); }
  .auth-top a:focus-visible { outline: 3px solid rgb(var(--brand-400) / .6); outline-offset: 2px; }
  .auth-top svg { width: 17px; height: 17px; flex: none; }
  @media (min-width: 640px) { .auth-top { padding: 16px 32px; } }
  @media (prefers-reduced-motion: reduce) { .auth-top a { transition: none; } }
</style>
<div class="landing">
  <nav class="auth-top" aria-label="Setlo navigation">
    <a class="home" href="<?= h(url('')) ?>" aria-label="Back to the Setlo home page">
      <svg fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>Home
    </a>
  </nav>
  <!-- Soft background shapes -->
  <div class="landing-blob -right-40 -top-40 h-[34rem] w-[34rem] bg-brand-100/60" aria-hidden="true"></div>
  <div class="landing-blob -bottom-56 right-[18%] h-[36rem] w-[36rem] bg-brand-200/40" aria-hidden="true"></div>
  <div class="landing-blob -left-32 bottom-10 h-72 w-72 bg-white/60" aria-hidden="true"></div>
  <svg class="pointer-events-none absolute -bottom-6 -left-8 hidden h-72 w-44 text-brand-200/60 lg:block" viewBox="0 0 100 160" fill="currentColor" aria-hidden="true">
    <path d="M50 160C48 120 30 95 8 80c26 2 40 14 44 30 2-30 14-56 40-78-8 34-12 70-10 100-6-6-14-10-24-10 8 8 12 20 12 38z"/>
  </svg>
  <svg class="pointer-events-none absolute -right-6 bottom-0 hidden h-80 w-52 text-brand-300/50 xl:block" viewBox="0 0 100 160" fill="currentColor" aria-hidden="true">
    <path d="M60 160c2-40 20-70 40-90-24 6-38 22-42 40-4-34-20-60-48-80 10 36 16 72 12 104 6-6 16-8 26-6-6 8-10 18-8 32z"/>
  </svg>

  <div class="landing-grid">
    <!-- Left: brand, headline, features -->
    <aside class="hidden flex-col lg:flex">
      <a href="<?= h(url('')) ?>" class="flex items-center gap-4" aria-label="Setlo home">
        <img src="<?= h(url('assets/icons/icon-192.png')) ?>" alt="" class="logo-img h-[72px] w-[72px] rounded-[22px]" />
        <div>
          <p class="text-[44px] font-extrabold leading-none tracking-tight text-brand-600">setlo</p>
          <p class="mt-1.5 text-[16px] font-medium text-slate-500">Split. Track. Stay Together.</p>
        </div>
      </a>

      <h1 class="mt-14 text-[40px] font-extrabold leading-[1.1] tracking-tight text-slate-700 xl:text-[44px]">
        Smarter finances<br />for a better<br />
        <span class="relative inline-block text-brand-600">shared life.
          <svg class="absolute -bottom-3 left-0 h-3 w-full text-brand-600" viewBox="0 0 200 12" fill="none" preserveAspectRatio="none" aria-hidden="true">
            <path d="M2 8c40-6 110-8 196-3" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
          </svg>
        </span>
      </h1>

      <ul class="mt-14 space-y-6">
        <li class="flex items-center gap-4">
          <span class="landing-feature-icon">
            <svg class="h-7 w-7" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><circle cx="9" cy="8" r="3"/><circle cx="17" cy="9" r="2.5"/><path stroke-linecap="round" d="M3 19c.8-3 3.2-5 6-5s5.2 2 6 5M15 14.5c2.4 0 4.6 1.6 5.5 4.5"/></svg>
          </span>
          <div><p class="text-[17px] font-bold text-brand-700">Split Bills Fairly</p><p class="text-[14px] text-slate-500">No more awkward money talks.</p></div>
        </li>
        <li class="flex items-center gap-4">
          <span class="landing-feature-icon">
            <svg class="h-7 w-7" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linejoin="round" d="M6 3h12v18l-3-2-3 2-3-2-3 2V3z"/><path stroke-linecap="round" d="M9 8h6M9 12h6M9 16h3"/></svg>
          </span>
          <div><p class="text-[17px] font-bold text-brand-700">Track Expenses</p><p class="text-[14px] text-slate-500">Know where your money goes.</p></div>
        </li>
        <li class="flex items-center gap-4">
          <span class="landing-feature-icon">
            <svg class="h-7 w-7" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linejoin="round" d="M12 3l8 3v6c0 4.5-3.4 8-8 9-4.6-1-8-4.5-8-9V6l8-3z"/><path stroke-linecap="round" stroke-linejoin="round" d="M8.5 12l2.5 2.5 4.5-5"/></svg>
          </span>
          <div><p class="text-[17px] font-bold text-brand-700">Stay Organized</p><p class="text-[14px] text-slate-500">Keep your shared life on track.</p></div>
        </li>
      </ul>
    </aside>

    <!-- Center: the page's card -->
    <main class="w-full">
      <div class="mb-6 flex flex-col items-center text-center lg:hidden">
        <p class="text-[15px] font-medium text-slate-500">Split. Track. Stay Together.</p>
      </div>
