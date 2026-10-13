<?php
// Join a bill from an invite link / QR: join.php?code=XXXXXXXXXXXX
require __DIR__ . '/../includes/bootstrap.php';

$code = preg_replace('/[^A-Za-z0-9]/', '', (string) ($_GET['code'] ?? ''));
$user = current_user();
if (!$user) {
    redirect('pages/login?next=' . urlencode('join?code=' . $code));
}
if ($user['role'] === 'admin') {
    redirect('pages/admin-dashboard');
}
$title = 'Join Bill';
require __DIR__ . '/../partials/head.php';
?>
<div id="app" class="device device-white auth-layout" v-cloak>

  <div class="auth-hero px-6 pb-14 pt-8">
    <div class="flex items-center gap-2.5 text-white">
      <img src="<?= h(url('assets/icons/icon-192.png')) ?>" alt="" class="logo-img h-[34px] w-[34px] rounded-[10px]" />
      <span class="text-[24px] font-extrabold tracking-tight">setlo</span>
    </div>
    <h1 class="mt-6 text-[24px] font-extrabold tracking-tight text-white">You're invited 🍽️</h1>
    <p class="mt-1 text-[13px] text-brand-50/90">Join the bill to see your share and settle up.</p>
  </div>

  <div class="auth-sheet flex flex-1 flex-col px-6 pb-6 pt-7">
    <spinner v-if="loading"></spinner>

    <div v-else-if="error" class="py-6 text-center">
      <p class="text-[16px] font-extrabold text-ink">Link not working</p>
      <p class="mt-1 text-[13px] text-slate-500">{{ error }}</p>
      <a href="dashboard" class="btn-pill btn-pill-soft mt-6">Go to dashboard</a>
    </div>

    <template v-else>
      <div class="tile p-4">
        <div class="flex items-center gap-3.5">
          <div class="initials">{{ initials }}</div>
          <div class="min-w-0 flex-1">
            <p class="truncate text-[16px] font-extrabold text-ink">{{ bill.name }}</p>
            <p class="text-[12px] text-slate-400">Created by {{ bill.creator }} · {{ fmtDate(bill.created_at) }}</p>
          </div>
        </div>
        <div class="mt-4 flex items-center gap-2">
          <div class="flex -space-x-2">
            <avatar v-for="m in members.slice(0, 6)" :key="m.id" :user="m" :size="30" ring></avatar>
          </div>
          <span class="text-[12px] text-slate-500">{{ members.length }} {{ members.length === 1 ? 'member' : 'members' }}</span>
        </div>
      </div>

      <p v-if="bill.locked" class="mt-4 rounded-xl bg-amber-50 px-4 py-3 text-[13px] text-amber-800">
        This bill is already {{ bill.status }}, so new members can't join. Ask {{ bill.creator.split(' ')[0] }} to add you directly.
      </p>

      <div class="mt-6">
        <button v-if="isMember" @click="go" class="btn-pill btn-pill-primary">You're already in — open bill</button>
        <button v-else-if="!bill.locked" @click="join" class="btn-pill btn-pill-primary" :disabled="busy">{{ busy ? 'Joining…' : 'Join as <?= h(first_name($user['full_name'])) ?>' }}</button>
        <a href="dashboard" class="btn-pill btn-pill-soft mt-3">Not now</a>
      </div>
    </template>
  </div>
</div>

<script>
Setlo.mount({
  data: () => ({ code: <?= json_encode($code) ?>, loading: true, busy: false, error: '', bill: null, members: [], isMember: false }),
  computed: {
    initials() { return this.bill.name.split(/\s+/).slice(0, 2).map((w) => w[0]).join('').toUpperCase(); },
  },
  async mounted() {
    try {
      const r = await api.get('bills.php', { invite: this.code });
      this.bill = r.bill;
      this.members = r.members;
      this.isMember = r.is_member;
    } catch (e) {
      this.error = e.message;
    } finally {
      this.loading = false;
    }
  },
  methods: {
    go() { location.href = 'bill-items?bill=' + this.bill.id; },
    async join() {
      const r = await Setlo.run(this, () => api.post('bills.php', { action: 'join', code: this.code }));
      if (r) location.href = r.redirect;
    },
  },
});
</script>
<?php require __DIR__ . '/../partials/foot.php'; ?>
