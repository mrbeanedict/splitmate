<?php
// Search box for a list page: filters what the page already loaded, as you type (no button).
// Set $searchHint (placeholder) before including; the page's Vue data needs `q: ''` and a computed that uses it.
$searchHint = $searchHint ?? 'Search';
?>
<div class="relative mb-3">
  <label class="sr-only" for="list-search"><?= h($searchHint) ?></label>
  <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path stroke-linecap="round" d="M16 16l4 4"/></svg>
  <input id="list-search" v-model="q" @keydown.esc="q = ''" type="search" autocomplete="off" spellcheck="false" class="input-soft !pl-10 !pr-9" placeholder="<?= h($searchHint) ?>" />
  <button v-if="q" type="button" @click="q = ''" class="absolute right-2.5 top-1/2 flex h-6 w-6 -translate-y-1/2 items-center justify-center rounded-full text-slate-400 hover:text-ink" aria-label="Clear search">
    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24"><path stroke-linecap="round" d="M6 6l12 12M18 6L6 18"/></svg>
  </button>
</div>
