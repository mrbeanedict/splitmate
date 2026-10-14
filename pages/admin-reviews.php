<?php
require __DIR__ . '/../includes/bootstrap.php';
$admin = require_admin();
$title = 'Reviews';
$subtitle = 'Ratings and feedback from users. Reviews go live as soon as they are posted; hide or delete any that break the rules.';
$adminNav = 'reviews';
$loginPage = 'admin-login';
require __DIR__ . '/../partials/head.php';
require __DIR__ . '/../partials/admin-top.php';
?>
<div id="app" class="space-y-4 p-5 md:p-8" v-cloak>
  <spinner v-if="loading"></spinner>
  <div v-else-if="!items.length" class="card p-8 text-center text-sm text-slate-400">No reviews yet.</div>

  <div v-for="r in items" :key="r.id" class="card p-5" :class="{ 'opacity-70': r.status === 'hidden' }">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <p class="text-sm font-semibold">{{ r.name }} <span class="font-normal text-slate-400">· {{ r.email }}</span></p>
        <p class="mt-0.5 text-base tracking-wide text-amber-500" :aria-label="r.rating + ' out of 5 stars'">{{ '★'.repeat(r.rating) }}<span class="text-slate-300">{{ '★'.repeat(5 - r.rating) }}</span></p>
      </div>
      <span class="rounded-full px-2.5 py-1 text-[11px] font-bold capitalize" :class="r.status === 'hidden' ? 'bg-slate-100 text-slate-500' : 'bg-emerald-100 text-emerald-700'">{{ r.status === 'hidden' ? 'Hidden' : 'Live' }}</span>
    </div>
    <p v-if="r.comment" class="mt-3 whitespace-pre-line rounded-lg bg-slate-50 px-3.5 py-2.5 text-sm text-slate-600">{{ r.comment }}</p>
    <p v-else class="mt-3 text-xs text-slate-400">Rating only, no comment.</p>
    <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
      <p class="text-[11px] text-slate-400">Updated {{ fmtDateTime(r.updated_at) }}</p>
      <div class="flex gap-2">
        <button v-if="r.status === 'hidden'" @click="setStatus(r, 'approved')" class="btn btn-primary btn-sm" :disabled="busy">Show again</button>
        <button v-if="r.status !== 'hidden'" @click="setStatus(r, 'hidden')" class="btn btn-outline btn-sm" :disabled="busy">Hide</button>
        <button @click="remove(r)" class="btn btn-ghost btn-sm" :disabled="busy">Delete</button>
      </div>
    </div>
  </div>
</div>

<script>
Setlo.mount({
  data: () => ({ loading: true, busy: false, items: [] }),
  async mounted() {
    await Setlo.load(this, 'admin.php', { view: 'reviews' }, (r) => { this.items = r.reviews; });
  },
  methods: {
    async setStatus(r, status) {
      const res = await Setlo.run(this, () => api.post('admin.php', { action: 'review_status', id: r.id, status }));
      if (res) { r.status = status; Setlo.toast(status === 'approved' ? 'Review is visible again' : 'Review hidden', 'ok'); }
    },
    async remove(r) {
      if (!(await Setlo.confirm({ title: 'Delete this review?', text: 'This can’t be undone.', confirmText: 'Delete', danger: true }))) return;
      const res = await Setlo.run(this, () => api.post('admin.php', { action: 'review_delete', id: r.id }));
      if (res) this.items = this.items.filter((x) => x.id !== r.id);
    },
  },
});
</script>
<?php require __DIR__ . '/../partials/admin-bottom.php'; ?>
<?php require __DIR__ . '/../partials/foot.php'; ?>
